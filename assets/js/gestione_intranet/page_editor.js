(function () {
  // — util —
  function wait_for_customfetch(max_ms = 5000) {
    return new Promise((resolve, reject) => {
      const t0 = Date.now();
      (function tick() {
        if (typeof window.customFetch !== 'function' && typeof window.customfetch === 'function') {
          window.customFetch = window.customfetch;
        }
        if (typeof window.customFetch === 'function') return resolve();
        if (Date.now() - t0 > max_ms) return reject(new Error('customfetch non disponibile'));
        setTimeout(tick, 50);
      })();
    });
  }

  // ============================================
  // UTILITY: Validazione link menu
  // ============================================
  /**
   * Verifica se un link contiene un form_name valido (non vuoto)
   * Supporta sia formato ?page=form&form_name= che index.php?...&form_name=
   */
  function isValidMenuLink(link) {
    if (!link || typeof link !== 'string') return false;

    try {
      // Per link relativi (?page=form&...)
      if (link.startsWith('?')) {
        const urlParams = new URLSearchParams(link.substring(1)); // rimuovi il ?
        const formName = urlParams.get('form_name');
        return formName && formName.trim() !== '';
      }

      // Per link assoluti (index.php?...)
      if (link.includes('index.php?')) {
        const url = new URL(link, window.location.origin);
        const formName = url.searchParams.get('form_name');
        return formName && formName.trim() !== '';
      }

      return false;
    } catch (e) {
      console.warn('Errore parsing link:', link, e);
      return false;
    }
  }

  // ============================================
  // FUNZIONE RIUTILIZZABILE: Render opzioni select compatte
  // ============================================
  /**
   * Renderizza le opzioni per un campo select in modo tabellare
   * @param {Object} field - Il campo select
   * @param {HTMLElement} container - Container dove inserire l'editor
   */
  function renderSelectOptionsEditor(field, container) {
    container.innerHTML = '';

    // Assicurati che field.options sia sempre un array
    let options = field.options || [];
    if (typeof options === 'string') {
      try {
        options = JSON.parse(options);
      } catch (e) {
        options = [];
      }
    }
    if (!Array.isArray(options)) {
      options = [];
    }

    // Crea tabella senza header
    const table = document.createElement('table');
    table.className = 'editor-select-options-table';

    // Body con le righe
    const tbody = document.createElement('tbody');
    tbody.className = 'editor-select-options-tbody';

    options.forEach((opt, idx) => {
      const row = document.createElement('tr');
      row.className = 'editor-select-option-row';

      // Colonna numero
      const numCell = document.createElement('td');
      numCell.className = 'editor-select-options-col-num';
      numCell.textContent = idx + 1;
      row.appendChild(numCell);

      // Colonna valore (contenteditable, no input box)
      const valueCell = document.createElement('td');
      valueCell.className = 'editor-select-options-col-value';
      valueCell.contentEditable = true;
      valueCell.textContent = opt || '';
      valueCell.onblur = () => {
        if (!Array.isArray(field.options)) {
          field.options = [];
        }
        field.options[idx] = valueCell.textContent.trim();
      };
      // Gestione Enter per aggiungere nuova riga
      valueCell.onkeydown = (e) => {
        if (e.key === 'Enter' && idx === options.length - 1) {
          e.preventDefault();
          if (!Array.isArray(field.options)) {
            field.options = [];
          }
          field.options.push('');
          renderSelectOptionsEditor(field, container);
          const newCell = container.querySelector('tbody tr:last-child .editor-select-options-col-value');
          if (newCell) {
            newCell.focus();
            // Seleziona tutto il testo se vuoto
            const range = document.createRange();
            range.selectNodeContents(newCell);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
          }
        }
      };
      row.appendChild(valueCell);

      // Colonna azioni (delete)
      const actionCell = document.createElement('td');
      actionCell.className = 'editor-select-options-col-action';
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'editor-select-option-remove';
      del.innerHTML = '✖';
      del.title = 'Rimuovi opzione';
      del.onclick = () => {
        if (!Array.isArray(field.options)) {
          field.options = [];
        }
        field.options.splice(idx, 1);
        renderSelectOptionsEditor(field, container);
        render_preview();
      };
      actionCell.appendChild(del);
      row.appendChild(actionCell);

      tbody.appendChild(row);
    });

    table.appendChild(tbody);
    container.appendChild(table);

    // Pulsante aggiungi opzione
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'button small editor-select-option-add';
    addBtn.textContent = '+ Aggiungi opzione';
    addBtn.onclick = (e) => {
      e.preventDefault();
      if (!Array.isArray(field.options)) {
        field.options = [];
      }
      field.options.push('');
      renderSelectOptionsEditor(field, container);
      const newCell = container.querySelector('tbody tr:last-child .editor-select-options-col-value');
      if (newCell) {
        newCell.focus();
        // Seleziona tutto il testo se vuoto
        const range = document.createRange();
        range.selectNodeContents(newCell);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
    };
    container.appendChild(addBtn);
  }

  // ============================================
  // FUNZIONE RIUTILIZZABILE: Cambio tipo campo
  // ============================================
  /**
   * Applica il cambio di tipo a un campo
   * @param {Object} field - Il campo da modificare
   * @param {string} nextType - Il nuovo tipo
   * @param {Array} allFields - Array completo di tutti i campi (per controlli globali)
   * @param {string} prevType - Il tipo precedente (per rollback se necessario)
   * @returns {boolean} - true se il cambio è stato applicato, false se è stato rifiutato
   */
  function applyFieldTypeChange(field, nextType, allFields, prevType) {
    // Controllo: un solo campo file per pagina (controlla sia top-level che children)
    if (nextType === 'file') {
      const hasFile = allFields.some(f => {
        if (f.type === 'file') return true;
        if (Array.isArray(f.children) && f.children.some(ch => ch.type === 'file')) return true;
        return false;
      });
      if (hasFile && prevType !== 'file') {
        showToast('è consentito un solo campo upload per scheda.', 'error');
        return false;
      }
    }

    // Applica il nuovo tipo
    field.type = nextType;

    // Gestione opzioni per select/checkbox/radio
    if (['select', 'checkbox', 'radio'].includes(field.type)) {
      field.options = (Array.isArray(field.options) && field.options.length) ? field.options : ['opzione 1'];
      delete field.datasource;
      delete field.multiple;
    }
    // Gestione datasource per dbselect
    else if (field.type === 'dbselect') {
      // Assicurati che ci sia sempre una configurazione datasource valida
      if (!field.datasource || !field.datasource.table || !field.datasource.valueCol) {
        field.datasource = {
          table: 'users',  // Tabella di default
          valueCol: 'id',
          labelCol: 'username',
          multiple: 0
        };
      }
      const ds = field.datasource;
      const multi = boolish(field.multiple ?? ds?.multiple);
      field.multiple = multi;
      if (ds) ds.multiple = multi ? 1 : 0;
      field.options = [];
    }
    // Pulizia per altri tipi
    else {
      field.options = [];
      delete field.datasource;
      delete field.multiple;
    }

    return true;
  }

  function initRightToolbar() {
    const toolbar = document.getElementById('pe-right-toolbar');
    const panel = document.getElementById('rtb-panel');
    const closeBtn = document.getElementById('rtb-close');
    const tabWizard = document.getElementById('rtb-tab-wizard');
    const tabFields = document.getElementById('rtb-tab-fields');
    const tabResp = document.getElementById('rtb-tab-response');
    const tabStates = document.getElementById('rtb-tab-states');
    const tabTabs = document.getElementById('rtb-tab-tabs');
    const tabNotify = document.getElementById('rtb-tab-notify');
    const titleEl = panel && panel.querySelector('.rtb-title');
    const bodyEl = panel && panel.querySelector('.rtb-panel-body');
    if (!toolbar || !panel || !titleEl || !bodyEl) return;

    const originalPalette = document.getElementById('field-dashboard');

    // mostriamo solo "Campi" e "Stati" (al momento Azioni nascosto)
    if (tabResp) tabResp.style.display = 'none';

    // Crea viste cache dentro al pannello per switching istantaneo
    const views = { fields: null, wizard: null, states: null, tabs: null, notify: null };
    function ensureViews() {
      if (!bodyEl) return;
      if (!views.fields) { views.fields = document.createElement('div'); views.fields.style.display = 'none'; bodyEl.appendChild(views.fields); }
      if (!views.wizard) { views.wizard = document.createElement('div'); views.wizard.style.display = 'none'; bodyEl.appendChild(views.wizard); }
      if (!views.states) { views.states = document.createElement('div'); views.states.style.display = 'none'; bodyEl.appendChild(views.states); }
      if (!views.tabs) { views.tabs = document.createElement('div'); views.tabs.style.display = 'none'; bodyEl.appendChild(views.tabs); }
      if (!views.notify) { views.notify = document.createElement('div'); views.notify.style.display = 'none'; bodyEl.appendChild(views.notify); }
    }
    function showView(key) {
      ensureViews();
      views.fields.style.display = (key === 'fields') ? 'block' : 'none';
      views.wizard.style.display = (key === 'wizard') ? 'block' : 'none';
      views.states.style.display = (key === 'states') ? 'block' : 'none';
      views.tabs.style.display = (key === 'tabs') ? 'block' : 'none';
      views.notify.style.display = (key === 'notify') ? 'block' : 'none';
    }

    function showFieldsPalette() {
      titleEl.textContent = 'Campi';
      ensureViews();
      if (originalPalette && !views.fields.contains(originalPalette)) {
        views.fields.innerHTML = '';
        views.fields.appendChild(originalPalette);
        try { wirePaletteFieldDrag(); } catch (_) { }
      }
      showView('fields');
    }

    // Sposta il wizard dentro/fuori dal pannello senza cambiare dimensioni
    function mountWizardIntoPanel() {
      const wizard = document.getElementById('pe-wizard');
      const placeholder = document.getElementById('pe-wizard-placeholder');
      ensureViews();
      if (!wizard || !views.wizard || !placeholder) return;
      if (views.wizard.contains(wizard)) {
        wizard.style.display = 'block';
        showView('wizard');
        return; // già montato
      }
      // stile per pannello
      wizard.style.display = 'block';
      wizard.style.marginTop = '0';
      wizard.style.padding = '0';
      views.wizard.innerHTML = '';
      views.wizard.appendChild(wizard);
      showView('wizard');
    }

    function unmountWizardToPage() {
      const wizard = document.getElementById('pe-wizard');
      const placeholder = document.getElementById('pe-wizard-placeholder');
      if (!wizard || !placeholder) return;
      if (placeholder.contains(wizard)) return; // già smontato
      placeholder.parentNode.insertBefore(wizard, placeholder.nextSibling);
      // ripristina visibilità default
      wizard.style.display = 'none';
    }

    function setActiveTab() {
      tabWizard && tabWizard.classList.remove('is-active');
      tabFields && tabFields.classList.add('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabStates && tabStates.classList.remove('is-active');
      tabTabs && tabTabs.classList.remove('is-active');
      tabNotify && tabNotify.classList.remove('is-active');
      panel.setAttribute('data-section', 'fields');
      showFieldsPalette();
      panel.classList.remove('is-collapsed');
      toolbar.classList.add('is-open');
      // smonta wizard se presente
      unmountWizardToPage();
    }

    async function setWizardTab() {
      tabWizard && tabWizard.classList.add('is-active');
      tabFields && tabFields.classList.remove('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabStates && tabStates.classList.remove('is-active');
      tabTabs && tabTabs.classList.remove('is-active');
      tabNotify && tabNotify.classList.remove('is-active');
      panel.setAttribute('data-section', 'wizard');
      // Titolo rtb: mostra modalità
      const isCreate = !formName || String(formName).trim() === '';
      titleEl.textContent = isCreate ? 'Proprietà — Creazione' : 'Proprietà — Modifica';
      // Assicurati che la palette campi sia rimossa dalla root del pannello
      ensureViews();
      if (originalPalette && bodyEl.contains(originalPalette) && !views.fields.contains(originalPalette)) {
        views.fields.innerHTML = '';
        views.fields.appendChild(originalPalette);
        views.fields.style.display = 'none';
      }
      mountWizardIntoPanel();
      // Nascondi heading interno per evitare doppio titolo
      const wh = document.getElementById('pe-wizard-heading');
      if (wh) wh.style.display = 'none';
      panel.classList.remove('is-collapsed');
      toolbar.classList.add('is-open');

      // Carica i dati del form (descrizione, colore, responsabile) prima di popolare il wizard
      if (formName) {
        try {
          const data = await window.customFetch('page_editor', 'getForm', { form_name: formName });
          // Imposta descrizione
          if (inputFormDesc && data?.form?.description) {
            inputFormDesc.value = String(data.form.description);
          }
          // Imposta responsabile
          if (data?.form?.responsabile) {
            const currentResp = String(data.form.responsabile);
            if (typeof initResponsabiliRows === 'function') {
                initResponsabiliRows(currentResp);
            }
          }
          // Carica colore
          await loadAndSetFormColor();
        } catch (e) {
          console.warn('[setWizardTab] Errore caricamento dati form:', e);
        }
      }

      // Popola i select del wizard quando è attivo nel pannello
      loadSectionsAndParents().catch(() => { });
      loadResponsabiliOptions().catch(() => { });
      openWizardPrefilledForEdit().catch(() => { });
    }

    async function renderStatesPanel(targetContainer) {
      const container = targetContainer || bodyEl;
      titleEl.textContent = 'Stati';
      if (!container) return;
      container.innerHTML = '';
      const wrap = document.createElement('div');
      wrap.style.display = 'grid';
      wrap.style.gridTemplateColumns = '1fr';
      wrap.style.gap = '8px';

      // Nessun controllo in header: aggiunta avviene sotto ogni gruppo base

      // Container liste per gruppi
      const listsWrap = document.createElement('div');
      listsWrap.style.display = 'grid';
      listsWrap.style.gap = '10px';
      wrap.appendChild(listsWrap);

      const defaultStates = [
        { id: 1, name: 'Aperta', color: '#3498db' },
        { id: 2, name: 'In corso', color: '#f1c40f' },
        { id: 5, name: 'Chiusa', color: '#2ecc71' }
      ];
      const currentName = (formNameHidden?.value || inputFormName?.value || '').trim();

      if (!currentName) {
        // Se il form non è ancora creato/salvato, mostra avviso e disabilita CRUD
        const info = document.createElement('div');
        info.className = 'input-static muted';
        info.textContent = 'Salva prima la pagina per configurare gli stati.';
        wrap.appendChild(info);
        container.appendChild(wrap);
        return;
      }
      try {
        await wait_for_customfetch();
        if (currentName) {
          const resp = await window.customFetch('page_editor', 'getFormStates', { form_name: currentName });
          if (resp?.success && Array.isArray(resp.states)) {
            window.peStates = resp.states.map(s => ({ id: s.id || null, name: s.name || '', color: s.color || '#95A5A6', active: s.active ? 1 : 0, base_group: (s.base_group ?? 2), is_base: (s.is_base ? 1 : 0) }));
            // Ordina gli stati dopo il caricamento per assicurarsi che siano nel ordine corretto
            window.peStates.sort((a, b) => {
              // Prima ordina per sort_order se disponibile
              if (a.sort_order && b.sort_order && a.sort_order !== b.sort_order) {
                return a.sort_order - b.sort_order;
              }
              // Poi ordina per base_group
              if (a.base_group !== b.base_group) {
                return (a.base_group || 0) - (b.base_group || 0);
              }
              // Infine ordina per id
              return (a.id || 0) - (b.id || 0);
            });
          }
        }
      } catch (e) {
        console.error('[renderStatesPanel] Error loading states:', e);
      }
      if (!Array.isArray(window.peStates)) window.peStates = defaultStates.slice();

      function renderBuckets() {
        listsWrap.innerHTML = '';
        const buckets = [
          { key: 1, title: 'Aperta', color: '#3498DB' },
          { key: 2, title: 'In corso', color: '#F1C40F' },
          { key: 3, title: 'Chiusa', color: '#2ECC71' }
        ];
        // Se esistono stati base dal DB, usa i loro nomi come label
        const baseByGroup = {};
        window.peStates.forEach(s => { if (s.base_group && s.is_base && !baseByGroup[s.base_group]) baseByGroup[s.base_group] = s.name; });
        buckets.forEach(b => { if (baseByGroup[b.key]) b.title = baseByGroup[b.key]; });

        // Costruisci stato base (fisso) + figli per bucket
        buckets.forEach((b, bi) => {
          const section = document.createElement('div');
          section.style.border = '1px solid #e0e0e0';
          section.style.borderRadius = '8px';
          section.style.padding = '10px';

          const header = document.createElement('div');
          header.style.display = 'flex'; header.style.alignItems = 'center'; header.style.justifyContent = 'flex-start'; header.style.gap = '8px';
          const leftH = document.createElement('div'); leftH.style.display = 'flex'; leftH.style.gap = '8px';
          const dot = document.createElement('span'); dot.style.width = '12px'; dot.style.height = '12px'; dot.style.borderRadius = '50%'; dot.style.background = b.color || '#CCCCCC';
          const inputTitle = document.createElement('input'); inputTitle.type = 'text'; inputTitle.value = b.title; inputTitle.style.width = '220px';
          leftH.append(dot, inputTitle); header.appendChild(leftH);

          section.appendChild(header);

          const list = document.createElement('div'); list.style.marginTop = '8px'; list.style.display = 'grid'; list.style.gap = '6px';
          // elementi figli del bucket
          const children = window.peStates.filter(s => (s.base_group || 0) === b.key && !s.is_base);
          children.forEach((s, idx) => {
            const card = document.createElement('div'); card.className = 'field-card'; card.style.display = 'flex'; card.style.alignItems = 'center'; card.style.justifyContent = 'space-between'; card.style.gap = '8px';
            const left = document.createElement('div'); left.style.display = 'flex'; left.style.gap = '8px'; left.style.alignItems = 'center';
            const colorWrap = document.createElement('span'); colorWrap.style.position = 'relative'; colorWrap.style.display = 'inline-block'; colorWrap.style.width = '16px'; colorWrap.style.height = '16px';
            const d = document.createElement('span'); d.style.width = '14px'; d.style.height = '14px'; d.style.borderRadius = '50%'; d.style.background = s.color || '#999'; d.style.cursor = 'pointer'; d.style.display = 'inline-block';
            const colorInput = document.createElement('input'); colorInput.type = 'color'; colorInput.value = (s.color && /^#/.test(s.color)) ? s.color : '#95a5a6';
            // posiziona l'input sopra il pallino (invisibile) per aprire il picker vicino
            colorInput.style.position = 'absolute';
            colorInput.style.left = '0';
            colorInput.style.top = '0';
            colorInput.style.width = '16px';
            colorInput.style.height = '16px';
            colorInput.style.opacity = '0';
            colorInput.style.cursor = 'pointer';
            colorInput.style.border = 'none';
            colorInput.style.padding = '0';
            colorInput.style.margin = '0';
            colorInput.style.background = 'transparent';
            colorWrap.appendChild(d);
            colorWrap.appendChild(colorInput);
            d.addEventListener('click', () => colorInput.click());
            colorInput.addEventListener('input', async () => {
              const prev = s.color; s.color = colorInput.value; d.style.background = s.color || '#CCCCCC';
              if (prev !== s.color) await persistStates();
            });
            const nameInput = document.createElement('input'); nameInput.type = 'text'; nameInput.value = s.name || `Stato ${idx + 1}`; nameInput.style.minWidth = '160px';
            if (s.__focus) { setTimeout(() => { nameInput.focus(); nameInput.select(); delete s.__focus; }, 0); }
            nameInput.addEventListener('change', async () => {
              const newV = nameInput.value.trim();
              if (newV && newV !== s.name) { s.name = newV; }
              // assicura base_group coerente
              if (!s.base_group) s.base_group = b.key;
              await persistStates();
            });
            left.append(colorWrap, nameInput);
            const right = document.createElement('div'); right.style.display = 'flex'; right.style.gap = '6px'; right.style.alignItems = 'center';
            const del = document.createElement('img');
            del.src = 'assets/icons/delete.png';
            del.alt = 'elimina';
            del.title = 'Elimina';
            del.style.width = '14px';
            del.style.height = '14px';
            del.style.cursor = 'pointer';
            del.style.position = 'static';
            del.style.marginLeft = '6px';
            right.append(del);
            del.addEventListener('click', async () => {
              if (confirm('Eliminare questo stato?')) { const ix = window.peStates.indexOf(s); if (ix >= 0) window.peStates.splice(ix, 1); await persistStates(); renderBuckets(); }
            });
            card.append(left, right); list.appendChild(card);
          });

          // area tratteggiata per aggiunta
          const dashed = document.createElement('div');
          dashed.style.border = '2px dashed #dee2e6'; dashed.style.borderRadius = '6px'; dashed.style.padding = '10px'; dashed.style.textAlign = 'center'; dashed.style.color = '#6c757d';
          dashed.textContent = 'Click per aggiungere uno stato sotto questo gruppo';
          dashed.style.cursor = 'pointer';
          const onAdd = async () => {
            const color = '#95a5a6';
            // Trova la posizione corretta per inserire il nuovo stato
            // Deve essere dopo gli stati esistenti del stesso gruppo
            let insertIndex = window.peStates.length;
            for (let i = 0; i < window.peStates.length; i++) {
              if ((window.peStates[i].base_group || 0) === b.key) {
                // Continua a cercare finché non trova uno stato di un gruppo successivo
                let j = i + 1;
                while (j < window.peStates.length && (window.peStates[j].base_group || 0) === b.key) {
                  j++;
                }
                insertIndex = j;
                break;
              } else if ((window.peStates[i].base_group || 0) > b.key) {
                // Se abbiamo trovato un gruppo successivo, inserisci qui
                insertIndex = i;
                break;
              }
            }

            window.peStates.splice(insertIndex, 0, { id: null, name: 'Nuovo stato', color, active: 1, base_group: b.key, __focus: true });
            // salva SOLO dopo che l’utente ha digitato (gestito da change su nameInput)
            renderBuckets();
          };
          dashed.addEventListener('click', onAdd);

          section.appendChild(list);
          section.appendChild(dashed);

          // salva rinomina del titolo base
          inputTitle.addEventListener('change', async () => {
            let base = window.peStates.find(s => (s.base_group || 0) === b.key && s.is_base);
            const newName = inputTitle.value.trim() || b.title;
            if (!base) {
              // Inserisci il nuovo stato base nella posizione corretta
              let insertIndex = 0;
              for (let i = 0; i < window.peStates.length; i++) {
                if ((window.peStates[i].base_group || 0) >= b.key) {
                  insertIndex = i;
                  break;
                }
                insertIndex = i + 1;
              }
              window.peStates.splice(insertIndex, 0, { id: null, name: newName, color: b.color, active: 1, base_group: b.key, is_base: 1 });
            } else if (base.name !== newName) {
              base.name = newName;
            } else {
              return; // nessuna modifica
            }
            await persistStates();
          });

          listsWrap.appendChild(section);
        });
      }

      function statesHash() {
        try {
          const essential = (window.peStates || []).map(s => ({ n: s.name, c: s.color, a: !!s.active, g: s.base_group }));
          return JSON.stringify(essential);
        } catch { return ''; }
      }

      async function persistStates() {
        try {
          await wait_for_customfetch();
          if (!currentName) return;
          const newHash = statesHash();
          if (window.__peStatesHash && window.__peStatesHash === newHash) return; // nessuna modifica

          // Ordina gli stati per base_group e poi per ordine di inserimento (id o indice)
          const sortedStates = [...window.peStates].sort((a, b) => {
            // Prima ordina per base_group
            if (a.base_group !== b.base_group) {
              return (a.base_group || 0) - (b.base_group || 0);
            }
            // Poi ordina per id (se esiste) o per indice nell'array originale
            if (a.id && b.id) {
              return a.id - b.id;
            }
            // Se uno ha id e l'altro no, quello con id viene prima
            if (a.id && !b.id) return -1;
            if (!a.id && b.id) return 1;
            // Infine ordina per posizione nell'array originale
            return window.peStates.indexOf(a) - window.peStates.indexOf(b);
          });

          const payload = { form_name: currentName, states: sortedStates.map((s, i) => ({ name: s.name, color: s.color, active: s.active ?? 1, sort_order: (i + 1) * 10, base_group: s.base_group || 2, is_base: s.is_base ? 1 : 0 })) };
          const r = await window.customFetch('page_editor', 'saveFormStates', payload);
          if (r?.success) {
            if (typeof window.showToast === 'function') showToast('Stati salvati', 'success');
            window.__peStatesHash = newHash;
          } else {
            if (typeof window.showToast === 'function') showToast(r?.message || 'Errore salvataggio stati', 'error');
            console.warn('saveFormStates failed', r);
          }
        } catch (e) {
          console.error('persistStates error', e);
          if (typeof window.showToast === 'function') showToast('Errore di rete nel salvataggio stati', 'error');
        }
      }

      renderBuckets();
      container.appendChild(wrap);
    }

    function setStatesTab() {
      tabWizard && tabWizard.classList.remove('is-active');
      tabFields && tabFields.classList.remove('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabStates && tabStates.classList.add('is-active');
      tabTabs && tabTabs.classList.remove('is-active');
      tabNotify && tabNotify.classList.remove('is-active');
      panel.setAttribute('data-section', 'states');
      titleEl.textContent = 'Stati'; // FIX: forza aggiornamento titolo
      ensureViews();
      if (views.states && !views.states.hasChildNodes()) {
        renderStatesPanel(views.states).catch(() => { });
      }
      showView('states');
      panel.classList.remove('is-collapsed');
      toolbar.classList.add('is-open');
    }

    function renderTabsPanel(targetContainer) {
      const container = targetContainer || bodyEl;
      titleEl.textContent = 'Schede';
      if (!container) return;

      ensureViews();
      const wrap = views.tabs;
      wrap.innerHTML = '';
      wrap.style.display = 'grid';
      wrap.style.gridTemplateColumns = '1fr';
      wrap.style.gap = '12px';
      wrap.style.padding = '0';

      // Seleziona la scheda attiva nella preview (usa la variabile globale)
      const currentTabKey = (typeof activeTabKey !== 'undefined' && activeTabKey) ? activeTabKey : DEFAULT_TAB_KEY;
      const activeTab = tabs.find(t => t.key === currentTabKey);
      if (!activeTab || activeTab.key === PROPERTIES_TAB_KEY) {
        const info = document.createElement('div');
        info.className = 'input-static muted';
        info.textContent = 'Seleziona una scheda nella preview per configurarla.';
        wrap.appendChild(info);
        return;
      }

      // Assicurati che tabState[currentTabKey] esista e abbia tutte le proprietà
      if (!tabState[currentTabKey]) {
        tabState[currentTabKey] = {
          fields: [],
          hasFixed: (currentTabKey === DEFAULT_TAB_KEY),
          submitLabel: null,
          submitAction: 'submit',
          isMain: (currentTabKey === DEFAULT_TAB_KEY),
          visibilityMode: 'all',
          unlockAfterSubmitPrev: false,
          // NUOVE PROPRIETÀ WORKFLOW
          visibilityRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
          editRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
          visibilityCondition: { type: 'always' },
          redirectAfterSubmit: false
        };
      }

      // Inizializza le proprietà se mancanti (con default appropriati)
      if (tabState[currentTabKey].isMain === undefined) {
        tabState[currentTabKey].isMain = (currentTabKey === DEFAULT_TAB_KEY);
      }
      if (tabState[currentTabKey].visibilityMode === undefined) {
        tabState[currentTabKey].visibilityMode = 'all';
      }
      if (tabState[currentTabKey].unlockAfterSubmitPrev === undefined) {
        tabState[currentTabKey].unlockAfterSubmitPrev = false;
      }
      if (tabState[currentTabKey].submitLabel === undefined) {
        tabState[currentTabKey].submitLabel = null;
      }
      if (tabState[currentTabKey].submitAction === undefined) {
        tabState[currentTabKey].submitAction = 'submit';
      }
      if (!Array.isArray(tabState[currentTabKey].fields)) {
        tabState[currentTabKey].fields = [];
      }
      // NUOVE PROPRIETÀ WORKFLOW - inizializzazione se mancanti
      if (!tabState[currentTabKey].visibilityRoles) {
        tabState[currentTabKey].visibilityRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
      }
      if (!tabState[currentTabKey].editRoles) {
        tabState[currentTabKey].editRoles = tabState[currentTabKey].visibilityRoles.slice();
      }
      if (!tabState[currentTabKey].visibilityCondition) {
        tabState[currentTabKey].visibilityCondition = { type: 'always' };
      }
      if (tabState[currentTabKey].redirectAfterSubmit === undefined) {
        tabState[currentTabKey].redirectAfterSubmit = false;
      }

      // Usa un riferimento diretto per i listener
      const tabStateData = tabState[currentTabKey];

      // 1. Scheda principale
      const mainGroup = document.createElement('div');
      mainGroup.style.display = 'flex';
      mainGroup.style.flexDirection = 'column';
      mainGroup.style.gap = '8px';

      const mainLabel = document.createElement('label');
      mainLabel.style.display = 'flex';
      mainLabel.style.alignItems = 'center';
      mainLabel.style.gap = '8px';
      mainLabel.style.cursor = 'pointer';

      const mainCheckbox = document.createElement('input');
      mainCheckbox.type = 'checkbox';
      mainCheckbox.checked = tabStateData.isMain || false;
      mainCheckbox.addEventListener('change', () => {
        const isMain = mainCheckbox.checked;
        // Aggiorna immediatamente in memoria - usa tabState direttamente per essere sicuri
        tabState[currentTabKey].isMain = isMain;
        if (isMain) {
          // Imposta tutte le altre schede a isMain = false
          Object.keys(tabState).forEach(key => {
            if (key !== currentTabKey && tabState[key]) {
              tabState[key].isMain = false;
            }
          });
        }
        console.log('[renderTabsPanel] isMain aggiornato:', tabState[currentTabKey].isMain, 'per scheda:', currentTabKey);
        // Aggiorna il pannello e la preview
        renderTabsPanel(views.tabs);
        render_preview();
        // Marca come modificato per il salvataggio
        if (typeof markAsModified === 'function') markAsModified();
      });

      const mainText = document.createElement('span');
      mainText.textContent = 'Scheda principale';
      mainText.style.fontWeight = '500';

      mainLabel.appendChild(mainCheckbox);
      mainLabel.appendChild(mainText);
      mainGroup.appendChild(mainLabel);

      const mainHelp = document.createElement('div');
      mainHelp.className = 'input-static muted';
      mainHelp.style.fontSize = '12px';
      mainHelp.textContent = 'Il form partirà da questa scheda quando viene aperto.';
      mainGroup.appendChild(mainHelp);

      wrap.appendChild(mainGroup);

      // 2. Scheda di Chiusura (Nuova)
      const closureGroup = document.createElement('div');
      closureGroup.style.display = 'flex';
      closureGroup.style.flexDirection = 'column';
      closureGroup.style.gap = '8px';
      closureGroup.style.marginTop = '12px';

      const closureLabel = document.createElement('label');
      closureLabel.style.display = 'flex';
      closureLabel.style.alignItems = 'center';
      closureLabel.style.gap = '8px';
      closureLabel.style.cursor = 'pointer';

      const closureCheckbox = document.createElement('input');
      closureCheckbox.type = 'checkbox';
      closureCheckbox.checked = tabStateData.isClosureTab || false;
      closureCheckbox.addEventListener('change', () => {
        const isClosure = closureCheckbox.checked;
        tabState[currentTabKey].isClosureTab = isClosure;

        // Se è scheda chiusura, suggerisci cambio label se ancora default
        if (isClosure && (!tabStateData.label || tabStateData.label.startsWith('Scheda '))) {
          // Non cambiamo label direttamente qui per non rompere sync, ma l'utente può rinominarla
          // Eventualmente potremmo forzare rinomina, ma meglio lasciare libertà
        }

        console.log('[renderTabsPanel] isClosureTab aggiornato:', tabState[currentTabKey].isClosureTab);
        render_preview();
        if (typeof markAsModified === 'function') markAsModified();
      });

      const closureText = document.createElement('span');
      closureText.textContent = 'Scheda di Chiusura';
      closureText.style.fontWeight = '500';

      closureLabel.appendChild(closureCheckbox);
      closureLabel.appendChild(closureText);
      closureGroup.appendChild(closureLabel);

      const closureHelp = document.createElement('div');
      closureHelp.className = 'input-static muted';
      closureHelp.style.fontSize = '12px';
      closureHelp.textContent = 'Aggiunge automaticamente campi per esito (Accettata/Rifiutata), note e data chiusura. Include logica di updateEsito.';
      closureGroup.appendChild(closureHelp);

      wrap.appendChild(closureGroup);

      // ========== SEZIONE WORKFLOW / VISIBILITÀ ==========
      const workflowSection = document.createElement('div');
      workflowSection.style.marginTop = '16px';
      workflowSection.style.paddingTop = '16px';
      workflowSection.style.borderTop = '1px solid #e1e4e8';

      const workflowTitle = document.createElement('div');
      workflowTitle.textContent = 'Workflow / Visibilità';
      workflowTitle.style.fontWeight = '600';
      workflowTitle.style.fontSize = '14px';
      workflowTitle.style.marginBottom = '12px';
      workflowTitle.style.color = '#24292f';
      workflowSection.appendChild(workflowTitle);

      // Inizializza proprietà workflow se mancanti
      if (!tabStateData.visibilityRoles) {
        tabStateData.visibilityRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
      }
      if (!tabStateData.editRoles) {
        tabStateData.editRoles = tabStateData.visibilityRoles.slice();
      }
      if (!tabStateData.visibilityCondition) {
        tabStateData.visibilityCondition = { type: 'always' };
      }
      if (tabStateData.redirectAfterSubmit === undefined) {
        tabStateData.redirectAfterSubmit = false;
      }

      // WORKFLOW: Tipo scheda e Visibilità ruoli - RIMOSSI DALLA GUI
      // La gestione viene fatta automaticamente dal motore di backoffice e i ruoli standard
      // non devono essere sovrascritti o manipolati per non creare conflitti con `Struttura`/Responsabili.

      // 2b. Condizione di visibilità/sblocco
      const conditionGroup = document.createElement('div');
      conditionGroup.style.display = 'flex';
      conditionGroup.style.flexDirection = 'column';
      conditionGroup.style.gap = '6px';
      conditionGroup.style.marginBottom = '12px';

      const conditionLabel = document.createElement('label');
      conditionLabel.textContent = 'Condizione di sblocco';
      conditionLabel.style.fontWeight = '500';
      conditionLabel.style.fontSize = '13px';
      conditionGroup.appendChild(conditionLabel);

      const conditionSelect = document.createElement('select');
      conditionSelect.style.width = '100%';
      conditionSelect.style.padding = '6px 8px';
      conditionSelect.style.border = '1px solid #d0d7de';
      conditionSelect.style.borderRadius = '6px';
      conditionSelect.style.fontSize = '13px';

      // FASE 4 - MVP: Rimuovi "after_step_saved" (bozza) - mantieni solo opzioni con implementazione reale
      const conditionOptions = [
        { value: 'always', text: 'Sempre visibile (nessuna condizione)' },
        { value: 'after_step_submitted', text: 'Dopo che una scheda è stata inviata' },
        { value: 'after_all_previous_submitted', text: 'Dopo che TUTTE le schede precedenti sono inviate' }
      ];

      const currentCondType = tabStateData.visibilityCondition?.type || 'always';

      conditionOptions.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.value;
        option.textContent = opt.text;
        option.selected = (opt.value === currentCondType);
        conditionSelect.appendChild(option);
      });

      // FASE 4 - MVP: Mappa "after_step_saved" (obsoleto) a "after_step_submitted" per retrocompatibilità
      let effectiveCondType = currentCondType;
      if (currentCondType === 'after_step_saved') {
        effectiveCondType = 'after_step_submitted';
        // Aggiorna anche in memoria
        tabState[currentTabKey].visibilityCondition = { type: 'after_step_submitted' };
      }

      // Container per selettore scheda dipendenza (mostrato solo per alcune condizioni)
      const dependsOnContainer = document.createElement('div');
      dependsOnContainer.style.marginTop = '8px';
      dependsOnContainer.style.display = (effectiveCondType === 'after_step_submitted') ? 'block' : 'none';

      const dependsOnLabel = document.createElement('label');
      dependsOnLabel.textContent = 'Scheda di riferimento (opzionale)';
      dependsOnLabel.style.fontSize = '12px';
      dependsOnLabel.style.color = '#57606a';
      dependsOnLabel.style.display = 'block';
      dependsOnLabel.style.marginBottom = '4px';

      const dependsOnSelect = document.createElement('select');
      dependsOnSelect.style.width = '100%';
      dependsOnSelect.style.padding = '6px 8px';
      dependsOnSelect.style.border = '1px solid #d0d7de';
      dependsOnSelect.style.borderRadius = '6px';
      dependsOnSelect.style.fontSize = '12px';

      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = '(Scheda precedente)';
      dependsOnSelect.appendChild(defaultOpt);

      // Aggiungi le altre schede come opzioni
      tabs.forEach(t => {
        if (t.key !== currentTabKey && t.key !== PROPERTIES_TAB_KEY) {
          const opt = document.createElement('option');
          opt.value = t.key;
          opt.textContent = t.label;
          opt.selected = (tabStateData.visibilityCondition?.depends_on === t.key);
          dependsOnSelect.appendChild(opt);
        }
      });

      dependsOnSelect.addEventListener('change', () => {
        tabState[currentTabKey].visibilityCondition = {
          type: conditionSelect.value,
          depends_on: dependsOnSelect.value || undefined
        };
        if (typeof markAsModified === 'function') markAsModified();
      });

      dependsOnContainer.appendChild(dependsOnLabel);
      dependsOnContainer.appendChild(dependsOnSelect);

      conditionSelect.addEventListener('change', () => {
        const newType = conditionSelect.value;
        tabState[currentTabKey].visibilityCondition = {
          type: newType,
          depends_on: (newType === 'after_step_saved' || newType === 'after_step_submitted') ? dependsOnSelect.value : undefined
        };

        // Mostra/nascondi selettore dipendenza (FASE 4 - MVP: solo per after_step_submitted)
        dependsOnContainer.style.display = (newType === 'after_step_submitted') ? 'block' : 'none';

        // Retrocompatibilità: aggiorna anche il vecchio campo
        if (newType === 'after_step_submitted' && !dependsOnSelect.value) {
          tabState[currentTabKey].unlockAfterSubmitPrev = true;
        } else {
          tabState[currentTabKey].unlockAfterSubmitPrev = false;
        }

        render_preview();
        if (typeof markAsModified === 'function') markAsModified();
      });

      conditionGroup.appendChild(conditionSelect);
      conditionGroup.appendChild(dependsOnContainer);

      // Help text
      const conditionHelp = document.createElement('div');
      conditionHelp.className = 'input-static muted';
      conditionHelp.style.fontSize = '11px';
      conditionHelp.style.marginTop = '4px';
      conditionHelp.textContent = 'Admin può sempre accedere a tutte le schede.';
      conditionGroup.appendChild(conditionHelp);

      // Disabilita per prima scheda
      if (currentTabKey === DEFAULT_TAB_KEY) {
        conditionSelect.disabled = true;
        conditionSelect.value = 'always';
        dependsOnContainer.style.display = 'none';
        conditionHelp.textContent = 'La prima scheda è sempre visibile.';
      }

      workflowSection.appendChild(conditionGroup);

      // 2c. Redirect dopo submit
      const redirectGroup = document.createElement('div');
      redirectGroup.style.display = 'flex';
      redirectGroup.style.flexDirection = 'column';
      redirectGroup.style.gap = '6px';
      redirectGroup.style.marginBottom = '12px';

      const redirectLabel = document.createElement('label');
      redirectLabel.style.display = 'flex';
      redirectLabel.style.alignItems = 'center';
      redirectLabel.style.gap = '8px';
      redirectLabel.style.cursor = 'pointer';

      const redirectCheckbox = document.createElement('input');
      redirectCheckbox.type = 'checkbox';
      redirectCheckbox.checked = tabStateData.redirectAfterSubmit || false;

      redirectCheckbox.addEventListener('change', () => {
        tabState[currentTabKey].redirectAfterSubmit = redirectCheckbox.checked;
        if (typeof markAsModified === 'function') markAsModified();
      });

      const redirectText = document.createElement('span');
      redirectText.textContent = 'Dopo il submit, vai alla scheda successiva';
      redirectText.style.fontWeight = '500';
      redirectText.style.fontSize = '13px';

      redirectLabel.appendChild(redirectCheckbox);
      redirectLabel.appendChild(redirectText);
      redirectGroup.appendChild(redirectLabel);

      const redirectHelp = document.createElement('div');
      redirectHelp.className = 'input-static muted';
      redirectHelp.style.fontSize = '11px';
      redirectHelp.textContent = 'Se attivo, dopo il salvataggio l\'utente viene portato alla prossima scheda disponibile.';
      redirectGroup.appendChild(redirectHelp);

      workflowSection.appendChild(redirectGroup);

      // 2d. Ruoli che possono modificare (opzionale, collassato)
      const editRolesDetails = document.createElement('details');
      editRolesDetails.style.marginTop = '8px';

      const editRolesSummary = document.createElement('summary');
      editRolesSummary.textContent = 'Ruoli che possono modificare (avanzato)';
      editRolesSummary.style.cursor = 'pointer';
      editRolesSummary.style.fontSize = '12px';
      editRolesSummary.style.color = '#57606a';
      editRolesDetails.appendChild(editRolesSummary);

      const editRolesContainer = document.createElement('div');
      editRolesContainer.style.display = 'flex';
      editRolesContainer.style.flexWrap = 'wrap';
      editRolesContainer.style.gap = '8px';
      editRolesContainer.style.marginTop = '8px';

      // FASE 4 - MVP: Mostra solo "Utente" e "Responsabile" anche per editRoles (assegnatario è implicito)
      const editRolesList = ['utente', 'responsabile', 'admin'];
      editRolesList.forEach(role => {
        const roleLabel = document.createElement('label');
        roleLabel.style.display = 'flex';
        roleLabel.style.alignItems = 'center';
        roleLabel.style.gap = '4px';
        roleLabel.style.cursor = 'pointer';
        roleLabel.style.fontSize = '12px';
        roleLabel.style.padding = '4px 8px';
        roleLabel.style.background = '#fff8e6';
        roleLabel.style.borderRadius = '4px';

        const roleCheck = document.createElement('input');
        roleCheck.type = 'checkbox';
        const currentEditRoles = tabStateData.editRoles || [];
        if (role === 'admin') {
          roleCheck.checked = true;
          roleCheck.disabled = true;
        } else if (role === 'responsabile') {
          // Se responsabile è selezionato, o se assegnatario è selezionato (retrocompatibilità), mostra checked
          roleCheck.checked = currentEditRoles.includes('responsabile') || currentEditRoles.includes('assegnatario');
        } else {
          roleCheck.checked = currentEditRoles.includes(role);
        }

        roleCheck.addEventListener('change', () => {
          const roles = new Set(currentEditRoles);
          if (roleCheck.checked) {
            roles.add(role);
            // FASE 4 - MVP: Se "responsabile" è selezionato, aggiungi automaticamente "assegnatario"
            if (role === 'responsabile') {
              roles.add('assegnatario');
            }
          } else {
            roles.delete(role);
            // FASE 4 - MVP: Se "responsabile" è deselezionato, rimuovi anche "assegnatario"
            if (role === 'responsabile') {
              roles.delete('assegnatario');
            }
          }
          roles.add('admin');
          tabState[currentTabKey].editRoles = Array.from(roles);
          if (typeof markAsModified === 'function') markAsModified();
        });

        const roleText = document.createElement('span');
        roleText.textContent = role === 'responsabile' ? 'Responsabile (e assegnatari)' : role.charAt(0).toUpperCase() + role.slice(1);

        roleLabel.appendChild(roleCheck);
        roleLabel.appendChild(roleText);
        editRolesContainer.appendChild(roleLabel);
      });

      editRolesDetails.appendChild(editRolesContainer);
      workflowSection.appendChild(editRolesDetails);

      wrap.appendChild(workflowSection);

      // FASE 4 - MVP: Retrocompatibilità e mappatura meta vecchi
      // visibility_mode deriva da visibilityRoles (per retrocompatibilità)
      if (tabStateData.visibilityRoles && !tabStateData.visibilityRoles.includes('utente')) {
        tabState[currentTabKey].visibilityMode = 'responsabile';
      } else {
        tabState[currentTabKey].visibilityMode = 'all';
      }

      // FASE 4 - MVP: Mappa "after_step_saved" (obsoleto) a "after_step_submitted" se presente
      if (tabStateData.visibilityCondition?.type === 'after_step_saved') {
        tabState[currentTabKey].visibilityCondition = {
          type: 'after_step_submitted',
          depends_on: tabStateData.visibilityCondition.depends_on
        };
      }

      // FASE 4 - MVP: Assicura che "assegnatario" sia sempre presente se "responsabile" è presente
      const visRoles = tabStateData.visibilityRoles || [];
      const editRoles = tabStateData.editRoles || [];
      if (visRoles.includes('responsabile') && !visRoles.includes('assegnatario')) {
        visRoles.push('assegnatario');
        tabState[currentTabKey].visibilityRoles = visRoles;
      }
      if (editRoles.includes('responsabile') && !editRoles.includes('assegnatario')) {
        editRoles.push('assegnatario');
        tabState[currentTabKey].editRoles = editRoles;
      }

      // ============================================
      // FIX: Pulsante di salvataggio diretto per le schede
      // ============================================
      const saveSepar = document.createElement('div');
      saveSepar.style.marginTop = '24px';
      saveSepar.style.borderTop = '1px solid #e1e4e8';
      saveSepar.style.paddingTop = '16px';
      saveSepar.style.textAlign = 'center'; // centra il pulsante

      const btnSaveTabs = document.createElement('button');
      btnSaveTabs.type = 'button';
      btnSaveTabs.className = 'button primary';
      btnSaveTabs.innerHTML = '💾 Salva configurazione schede';
      // Stile pulsante per evidenziarlo
      btnSaveTabs.style.width = '100%';
      btnSaveTabs.style.justifyContent = 'center';
      btnSaveTabs.onclick = async (e) => {
        e.preventDefault();
        // Feedback immediato
        const oldText = btnSaveTabs.innerHTML;
        btnSaveTabs.disabled = true;
        btnSaveTabs.innerHTML = 'Salvando...';

        try {
          if (typeof window.persistTabs === 'function') {
            await window.persistTabs();
            // persistTabs mostra già i toast di successo/errore
          } else {
            console.error('Funzione persistTabs non trovata');
            if (typeof showToast === 'function') showToast('Errore interno: funzione di salvataggio mancante', 'error');
          }
        } catch (err) {
          console.error('Errore durante il salvataggio schede:', err);
        } finally {
          // Ripristina pulsante
          setTimeout(() => {
            btnSaveTabs.disabled = false;
            btnSaveTabs.innerHTML = oldText;
          }, 500);
        }
      };

      saveSepar.appendChild(btnSaveTabs);
      // Aggiunge anche una nota esplicativa piccola sotto
      const saveNote = document.createElement('div');
      saveNote.className = 'input-static muted';
      saveNote.style.fontSize = '11px';
      saveNote.style.marginTop = '6px';
      saveNote.style.textAlign = 'center';
      saveNote.textContent = 'Salva la struttura e le proprietà delle schede.';
      saveSepar.appendChild(saveNote);

      wrap.appendChild(saveSepar);
    }

    function setTabsTab() {
      tabWizard && tabWizard.classList.remove('is-active');
      tabFields && tabFields.classList.remove('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabStates && tabStates.classList.remove('is-active');
      tabTabs && tabTabs.classList.add('is-active');
      panel.setAttribute('data-section', 'tabs');
      ensureViews();
      // Aggiorna sempre il pannello quando viene aperto (per riflettere la scheda attiva corrente)
      renderTabsPanel(views.tabs);
      showView('tabs');
      panel.classList.remove('is-collapsed');
      toolbar.classList.add('is-open');
    }

    // Funzione per aggiornare il pannello "Schede" se è aperto
    window.updateTabsPanelIfOpen = function () {
      if (panel && panel.getAttribute('data-section') === 'tabs' && !panel.classList.contains('is-collapsed')) {
        ensureViews();
        renderTabsPanel(views.tabs);
      }
    };

    // =========================
    // PANNELLO NOTIFICHE
    // =========================
    function renderNotifyPanel(targetContainer) {
      const container = targetContainer || bodyEl;
      titleEl.textContent = 'Notifiche';
      if (!container) return;

      ensureViews();
      const wrap = views.notify;
      wrap.innerHTML = '';
      wrap.style.display = 'grid';
      wrap.style.gridTemplateColumns = '1fr';
      wrap.style.gap = '16px';
      wrap.style.padding = '0';

      const currentName = (formNameHidden?.value || inputFormName?.value || '').trim();
      if (!currentName) {
        const info = document.createElement('div');
        info.className = 'input-static muted';
        info.textContent = 'Salva prima la pagina per configurare le notifiche.';
        wrap.appendChild(info);
        return;
      }

      // Stato notifiche in memoria (verrà caricato dal DB se esiste)
      if (!window.peNotifyConfig) {
        window.peNotifyConfig = {
          enabled: false,
          events: {
            on_submit: false,
            on_status_change: false,
            on_assignment_change: false
          },
          channels: {
            in_app: false,
            email: false
          },
          recipients: {
            responsabile: false,
            assegnatario: false,
            creatore: false,
            custom_email: false,
            custom_email_value: ''
          },
          messages: {
            in_app_message: '',
            email_subject: '',
            email_body: ''
          }
        };
      }

      const cfg = window.peNotifyConfig;

      // === MASTER TOGGLE ===
      const masterSection = document.createElement('div');
      masterSection.className = 'field-card';
      masterSection.style.cursor = 'default';
      masterSection.style.borderLeft = '4px solid #3498db';
      masterSection.style.background = '#f0f7ff';

      const masterLabel = document.createElement('label');
      masterLabel.style.display = 'flex';
      masterLabel.style.alignItems = 'center';
      masterLabel.style.gap = '10px';
      masterLabel.style.cursor = 'pointer';
      masterLabel.style.fontWeight = 'bold';

      const masterChk = document.createElement('input');
      masterChk.type = 'checkbox';
      masterChk.checked = cfg.enabled || false;
      masterChk.style.transform = 'scale(1.2)';
      masterChk.addEventListener('change', () => {
        cfg.enabled = masterChk.checked;
        if (cfg.enabled) {
          masterSection.style.background = '#f0f7ff';
        } else {
          masterSection.style.background = '#fff5f5';
        }
      });

      const masterTxt = document.createElement('span');
      masterTxt.textContent = 'Abilita notifiche per questo modulo';
      masterLabel.append(masterChk, masterTxt);
      masterSection.appendChild(masterLabel);
      wrap.appendChild(masterSection);

      // === CONTENITORE IMPOSTAZIONI DIPENDENTI ===
      // Questo container racchiude tutto ciò che dipende dal Master Toggle
      const settingsContainer = document.createElement('div');
      settingsContainer.style.display = 'grid';
      settingsContainer.style.gap = '16px';
      settingsContainer.style.transition = 'opacity 0.3s ease, filter 0.3s ease';

      const updateSettingsState = (enabled) => {
        if (enabled) {
          settingsContainer.style.opacity = '1';
          settingsContainer.style.filter = 'none';
          settingsContainer.style.pointerEvents = 'auto';
          masterSection.style.background = '#f0f7ff';
          masterSection.style.borderLeftColor = '#3498db';
        } else {
          settingsContainer.style.opacity = '0.5';
          settingsContainer.style.filter = 'grayscale(0.5)';
          settingsContainer.style.pointerEvents = 'none';
          masterSection.style.background = '#fff5f5';
          masterSection.style.borderLeftColor = '#e74c3c';
        }
      };

      masterChk.addEventListener('change', () => {
        cfg.enabled = masterChk.checked;
        updateSettingsState(cfg.enabled);
      });

      // Stato iniziale
      updateSettingsState(cfg.enabled);
      wrap.appendChild(settingsContainer);

      // === A) EVENTI ===
      const eventsSection = document.createElement('div');
      eventsSection.className = 'field-card';
      eventsSection.style.cursor = 'default';

      const eventsTitle = document.createElement('h4');
      eventsTitle.textContent = 'Eventi';
      eventsTitle.style.marginTop = '0';
      eventsTitle.style.marginBottom = '12px';
      eventsTitle.style.fontSize = '14px';
      eventsTitle.style.fontWeight = '600';
      eventsSection.appendChild(eventsTitle);

      const eventsList = document.createElement('div');
      eventsList.style.display = 'grid';
      eventsList.style.gap = '8px';

      const events = [
        { key: 'on_submit', label: 'Quando il modulo viene inviato' },
        { key: 'on_status_change', label: 'Quando cambia stato' },
        { key: 'on_assignment_change', label: 'Quando cambia assegnatario/responsabile' }
      ];

      events.forEach(evt => {
        const lbl = document.createElement('label');
        lbl.style.display = 'flex';
        lbl.style.alignItems = 'center';
        lbl.style.gap = '8px';
        lbl.style.cursor = 'pointer';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = cfg.events[evt.key] || false;
        chk.addEventListener('change', () => {
          cfg.events[evt.key] = chk.checked;
        });
        const txt = document.createElement('span');
        txt.textContent = evt.label;
        lbl.append(chk, txt);
        eventsList.appendChild(lbl);
      });

      eventsSection.appendChild(eventsList);
      settingsContainer.appendChild(eventsSection);

      // === B) CANALI ===
      const channelsSection = document.createElement('div');
      channelsSection.className = 'field-card';
      channelsSection.style.cursor = 'default';

      const channelsTitle = document.createElement('h4');
      channelsTitle.textContent = 'Canali';
      channelsTitle.style.marginTop = '0';
      channelsTitle.style.marginBottom = '12px';
      channelsTitle.style.fontSize = '14px';
      channelsTitle.style.fontWeight = '600';
      channelsSection.appendChild(channelsTitle);

      const channelsList = document.createElement('div');
      channelsList.style.display = 'grid';
      channelsList.style.gap = '8px';

      const channels = [
        { key: 'in_app', label: 'Notifica in-app' },
        { key: 'email', label: 'Email' }
      ];

      channels.forEach(ch => {
        const lbl = document.createElement('label');
        lbl.style.display = 'flex';
        lbl.style.alignItems = 'center';
        lbl.style.gap = '8px';
        lbl.style.cursor = 'pointer';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = cfg.channels[ch.key] || false;
        chk.addEventListener('change', () => {
          cfg.channels[ch.key] = chk.checked;
        });
        const txt = document.createElement('span');
        txt.textContent = ch.label;
        lbl.append(chk, txt);
        channelsList.appendChild(lbl);
      });

      channelsSection.appendChild(channelsList);
      settingsContainer.appendChild(channelsSection);

      // === C) DESTINATARI ===
      const recipientsSection = document.createElement('div');
      recipientsSection.className = 'field-card';
      recipientsSection.style.cursor = 'default';

      const recipientsTitle = document.createElement('h4');
      recipientsTitle.textContent = 'Destinatari';
      recipientsTitle.style.marginTop = '0';
      recipientsTitle.style.marginBottom = '12px';
      recipientsTitle.style.fontSize = '14px';
      recipientsTitle.style.fontWeight = '600';
      recipientsSection.appendChild(recipientsTitle);

      const recipientsList = document.createElement('div');
      recipientsList.style.display = 'grid';
      recipientsList.style.gap = '8px';

      const recipients = [
        { key: 'responsabile', label: 'Responsabile' },
        { key: 'assegnatario', label: 'Assegnatario' },
        { key: 'autore', label: 'Autore' },
        { key: 'custom_email', label: 'Email personalizzata', hasInput: true }
      ];

      recipients.forEach(rec => {
        const lbl = document.createElement('label');
        lbl.style.display = 'flex';
        lbl.style.alignItems = 'center';
        lbl.style.gap = '8px';
        lbl.style.cursor = 'pointer';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.checked = cfg.recipients[rec.key] || false;
        const customEmailInputRef = { current: null };

        chk.addEventListener('change', () => {
          cfg.recipients[rec.key] = chk.checked;
          if (rec.hasInput && customEmailInputRef.current) {
            customEmailInputRef.current.style.display = chk.checked ? 'block' : 'none';
          }
        });
        const txt = document.createElement('span');
        txt.textContent = rec.label;
        lbl.append(chk, txt);

        // Wrap label and potentially input in a container
        const rowDiv = document.createElement('div');
        rowDiv.appendChild(lbl);

        if (rec.hasInput) {
          const customEmailInput = document.createElement('input');
          customEmailInputRef.current = customEmailInput;
          customEmailInput.type = 'email';
          customEmailInput.placeholder = 'email@example.com';
          customEmailInput.value = cfg.recipients.custom_email_value || '';
          customEmailInput.style.width = '100%';
          customEmailInput.style.marginTop = '6px';
          customEmailInput.style.marginLeft = '26px';
          customEmailInput.style.display = cfg.recipients.custom_email ? 'block' : 'none';
          customEmailInput.addEventListener('change', () => {
            cfg.recipients.custom_email_value = customEmailInput.value.trim();
          });
          rowDiv.appendChild(customEmailInput);
        }
        recipientsList.appendChild(rowDiv);
      });

      recipientsSection.appendChild(recipientsList);
      settingsContainer.appendChild(recipientsSection);

      // === D) MESSAGGI ===
      const messagesSection = document.createElement('div');
      messagesSection.className = 'field-card';
      messagesSection.style.cursor = 'default';

      const messagesTitle = document.createElement('h4');
      messagesTitle.textContent = 'Messaggi';
      messagesTitle.style.marginTop = '0';
      messagesTitle.style.marginBottom = '12px';
      messagesTitle.style.fontSize = '14px';
      messagesTitle.style.fontWeight = '600';
      messagesSection.appendChild(messagesTitle);

      const messagesGrid = document.createElement('div');
      messagesGrid.style.display = 'grid';
      /* messagesGrid.style.gap = '12px'; */

      // Messaggio in-app
      const inAppLabel = document.createElement('label');
      inAppLabel.textContent = 'Messaggio notifica in-app';
      inAppLabel.style.display = 'block';
      inAppLabel.style.marginBottom = '4px';
      inAppLabel.style.fontSize = '13px';
      messagesGrid.appendChild(inAppLabel);

      const inAppInput = document.createElement('input');
      inAppInput.type = 'text';
      inAppInput.placeholder = 'Es: Nuova segnalazione ricevuta';
      inAppInput.value = cfg.messages.in_app_message || '';
      inAppInput.style.width = '100%';
      inAppInput.addEventListener('change', () => {
        cfg.messages.in_app_message = inAppInput.value.trim();
      });
      messagesGrid.appendChild(inAppInput);

      // Selezione Template
      const templateLabel = document.createElement('label');
      templateLabel.textContent = 'Template Email';
      templateLabel.style.display = 'block';
      templateLabel.style.marginTop = '8px';
      templateLabel.style.marginBottom = '4px';
      templateLabel.style.fontSize = '13px';
      messagesGrid.appendChild(templateLabel);

      const templateSelect = document.createElement('select');
      templateSelect.className = 'input-select';
      templateSelect.style.width = '100%';
      const templates = [
        { value: 'base_template', label: 'Template Professionale (Header Blu)' },
        { value: 'minimal_template', label: 'Template Minimalista' },
        { value: 'none', label: 'Nessun Template (Testo Semplice)' }
      ];
      templates.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.value;
        opt.textContent = t.label;
        opt.selected = (cfg.messages.email_template || 'base_template') === t.value;
        templateSelect.appendChild(opt);
      });
      templateSelect.addEventListener('change', () => {
        cfg.messages.email_template = templateSelect.value;
      });
      messagesGrid.appendChild(templateSelect);

      // Oggetto email
      const emailSubjLabel = document.createElement('label');
      emailSubjLabel.textContent = 'Oggetto email';
      emailSubjLabel.style.display = 'block';
      emailSubjLabel.style.marginTop = '8px';
      emailSubjLabel.style.marginBottom = '4px';
      emailSubjLabel.style.fontSize = '13px';
      messagesGrid.appendChild(emailSubjLabel);

      const emailSubjInput = document.createElement('input');
      emailSubjInput.type = 'text';
      emailSubjInput.placeholder = 'Es: Nuova richiesta';
      emailSubjInput.value = cfg.messages.email_subject || '';
      emailSubjInput.style.width = '100%';
      emailSubjInput.addEventListener('change', () => {
        cfg.messages.email_subject = emailSubjInput.value.trim();
      });
      messagesGrid.appendChild(emailSubjInput);

      // Corpo email
      const emailBodyLabel = document.createElement('label');
      emailBodyLabel.textContent = 'Corpo email';
      emailBodyLabel.style.display = 'block';
      emailBodyLabel.style.marginTop = '8px';
      emailBodyLabel.style.marginBottom = '4px';
      emailBodyLabel.style.fontSize = '13px';
      messagesGrid.appendChild(emailBodyLabel);

      const emailBodyTextarea = document.createElement('textarea');
      emailBodyTextarea.placeholder = 'Inserisci il corpo della email...';
      emailBodyTextarea.value = cfg.messages.email_body || '';
      emailBodyTextarea.rows = 4;
      emailBodyTextarea.style.width = '100%';
      emailBodyTextarea.addEventListener('change', () => {
        cfg.messages.email_body = emailBodyTextarea.value.trim();
      });
      messagesGrid.appendChild(emailBodyTextarea);

      // Info placeholder (Legenda più chiara)
      const placeholderInfo = document.createElement('div');
      placeholderInfo.style.marginTop = '15px';
      placeholderInfo.style.padding = '10px';
      placeholderInfo.style.backgroundColor = 'rgba(0,0,0,0.05)';
      placeholderInfo.style.borderRadius = '6px';
      placeholderInfo.style.borderLeft = '4px solid #3498db';

      const placeholderTitle = document.createElement('strong');
      placeholderTitle.textContent = 'Legenda Placeholder:';
      placeholderTitle.style.display = 'block';
      placeholderTitle.style.marginBottom = '5px';
      placeholderTitle.style.fontSize = '12px';
      placeholderInfo.appendChild(placeholderTitle);

      const placeholderText = document.createElement('div');
      placeholderText.style.fontSize = '11px';
      placeholderText.style.color = '#555';
      placeholderText.lineHeight = '1.4';
      placeholderText.innerHTML = `
        <code style="background:#eee; padding: 2px 4px;">{id}</code>: ID del record<br>
        <code style="background:#eee; padding: 2px 4px;">{autore}</code>: Chi ha creato il record (Autore)<br>
        <code style="background:#eee; padding: 2px 4px;">{attore}</code>: Chi ha eseguito l'azione attuale<br>
        <code style="background:#eee; padding: 2px 4px;">{now}</code>: Data/ora attuale<br>
        <code style="background:#eee; padding: 2px 4px;">{link}</code>: Link diretto al record<br>
        <code style="background:#eee; padding: 2px 4px;">{record_table}</code>: Tabella con tutti i dati del record<br>
        <span style="color:#888; font-style:italic">Puoi usare anche i nomi dei campi del form, es: {titolo}, {commessa}...</span>
      `;
      placeholderInfo.appendChild(placeholderText);
      messagesGrid.appendChild(placeholderInfo);

      messagesSection.appendChild(messagesGrid);
      settingsContainer.appendChild(messagesSection);

      // === E) AZIONI ===
      const actionsSection = document.createElement('div');
      actionsSection.style.display = 'flex';
      actionsSection.style.gap = '10px';
      actionsSection.style.marginTop = '8px';

      const btnSave = document.createElement('button');
      btnSave.type = 'button';
      btnSave.className = 'button';
      btnSave.textContent = 'Salva configurazione notifiche';
      btnSave.addEventListener('click', async () => {
        try {
          // Validazione
          if (cfg.enabled) {
            const hasEmail = cfg.channels.email; // cfg.channels is an object, not an array
            if (hasEmail) {
              if (!cfg.messages.email_subject || !cfg.messages.email_body) {
                alert('Per attivare le notifiche email, inserisci Oggetto e Corpo del messaggio.');
                return;
              }
            }
            const hasInApp = cfg.channels.in_app; // cfg.channels is an object, not an array
            if (hasInApp && !cfg.messages.in_app_message) {
              alert('Per attivare le notifiche in-app, inserisci il testo del messaggio.');
              return;
            }
          }

          const payload = {
            form_name: currentName,
            config: cfg
          };
          await wait_for_customfetch();
          const res = await window.customFetch('page_editor', 'saveNotificationRules', payload);
          if (res?.success) {
            if (typeof window.showToast === 'function') showToast('Configurazione notifiche salvata', 'success');
          } else {
            if (typeof window.showToast === 'function') showToast(res?.message || 'Errore salvataggio', 'error');
          }
        } catch (e) {
          console.error('[saveNotificationConfig] Error:', e);
          if (typeof window.showToast === 'function') showToast('Errore di rete', 'error');
        }
      });

      const btnDisable = document.createElement('button');
      btnDisable.type = 'button';
      btnDisable.className = 'button btn-secondary';
      btnDisable.textContent = 'Disabilita notifiche per questo modulo';
      btnDisable.addEventListener('click', () => {
        if (confirm('Disabilitare tutte le notifiche per questo modulo?')) {
          cfg.enabled = false;
          cfg.events.on_submit = false;
          cfg.events.on_status_change = false;
          cfg.events.on_assignment_change = false;
          cfg.channels.in_app = false;
          cfg.channels.email = false;
          renderNotifyPanel(views.notify);
          if (typeof window.showToast === 'function') showToast('Notifiche disabilitate (non ancora salvato)', 'info');
        }
      });

      actionsSection.append(btnSave, btnDisable);
      wrap.appendChild(actionsSection);
    }

    async function setNotifyTab() {
      tabWizard && tabWizard.classList.remove('is-active');
      tabFields && tabFields.classList.remove('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabStates && tabStates.classList.remove('is-active');
      tabTabs && tabTabs.classList.remove('is-active');
      tabNotify && tabNotify.classList.add('is-active');
      panel.setAttribute('data-section', 'notify');
      titleEl.textContent = 'Notifiche';
      ensureViews();

      // Carica configurazione dal server se c'è un form e non è già caricata
      const currentName = (formNameHidden?.value || inputFormName?.value || '').trim();
      if (currentName && !window.peNotifyConfigLoaded) {
        try {
          views.notify.innerHTML = '<div class="loader">Caricamento impostazioni...</div>';
          showView('notify');

          await wait_for_customfetch();
          const resp = await window.customFetch('page_editor', 'getNotificationRules', { form_name: currentName });

          if (resp?.success && resp.rules) {
            const r = resp.rules;
            // Funzione di normalizzazione (gestisce sia array legacy che oggetti nuovi)
            const normalizeMap = (val, keys) => {
              const out = {};
              keys.forEach(k => out[k] = false);
              if (Array.isArray(val)) {
                val.forEach(v => { if (keys.includes(v)) out[v] = true; });
              } else if (val && typeof val === 'object') {
                keys.forEach(k => { if (val[k]) out[k] = true; });
              }
              return out;
            };

            window.peNotifyConfig = {
              enabled: (r.enabled == 1 || r.enabled === true),
              events: normalizeMap(r.events, ['on_submit', 'on_status_change', 'on_assignment_change']),
              channels: normalizeMap(r.channels, ['in_app', 'email']),
              recipients: normalizeMap(r.recipients, ['responsabile', 'assegnatario', 'autore', 'creatore', 'custom_email']),
              messages: r.messages || { in_app_message: '', email_subject: '', email_body: '' }
            };

            // Retrocompatibilità: se era salvato come 'creatore', mappalo su 'autore'
            if (window.peNotifyConfig.recipients.creatore) {
              window.peNotifyConfig.recipients.autore = true;
            }

            // Recupera valori extra (es. custom_email_value) se presenti nell'oggetto salvato
            if (r.recipients && !Array.isArray(r.recipients) && typeof r.recipients === 'object') {
              if (r.recipients.custom_email_value) {
                window.peNotifyConfig.recipients.custom_email_value = r.recipients.custom_email_value;
              }
            }
            // Inizializza defaults per messaggi se parziali
            if (!window.peNotifyConfig.messages.in_app_message) window.peNotifyConfig.messages.in_app_message = '';
            if (!window.peNotifyConfig.messages.email_subject) window.peNotifyConfig.messages.email_subject = '';
            if (!window.peNotifyConfig.messages.email_body) window.peNotifyConfig.messages.email_body = '';

            window.peNotifyConfigLoaded = true;
          } else {
            // Se non c'è regola salvata, resetta a defaults (verrà fatto da renderNotifyPanel)
            window.peNotifyConfig = null;
            window.peNotifyConfigLoaded = true; // caricato ma vuoto
          }
        } catch (e) {
          console.error("Error loading notification rules", e);
          if (typeof showToast === 'function') showToast("Errore caricamento notifiche", "error");
        }
      }

      if (views.notify) {
        renderNotifyPanel(views.notify);
      }
      showView('notify');
      panel.classList.remove('is-collapsed');
      toolbar.classList.add('is-open');
    }

    function closePanel() {
      panel.classList.add('is-collapsed');
      tabFields && tabFields.classList.remove('is-active');
      tabResp && tabResp.classList.remove('is-active');
      tabTabs && tabTabs.classList.remove('is-active');
      tabNotify && tabNotify.classList.remove('is-active');
      toolbar.classList.remove('is-open');
    }

    // apertura iniziale + handler (di default mostra Proprietà)
    setWizardTab().catch(e => console.warn('[initRightToolbar] Errore apertura wizard iniziale:', e));
    tabWizard && tabWizard.addEventListener('click', async (e) => { e.preventDefault(); await setWizardTab(); });
    tabFields && tabFields.addEventListener('click', (e) => { e.preventDefault(); setActiveTab(); });
    tabStates && tabStates.addEventListener('click', (e) => { e.preventDefault(); setStatesTab(); });
    tabTabs && tabTabs.addEventListener('click', (e) => { e.preventDefault(); setTabsTab(); });
    tabNotify && tabNotify.addEventListener('click', (e) => { e.preventDefault(); setNotifyTab(); });
    closeBtn && closeBtn.addEventListener('click', (e) => { e.preventDefault(); closePanel(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !panel.classList.contains('is-collapsed')) { e.preventDefault(); closePanel(); }
    });

    window.peToolbar = { setActiveTab, closePanel };
  }

  // — refs DOM —
  const formNameHidden = document.getElementById('form-name-editor');

  // Config form in memoria (per button_text e altre proprietà)
  let formConfig = {
    button_text: null
  };
  const preview = document.getElementById('form-fields-preview');

  const editorStage = document.getElementById('editor-stage');
  const wizardStage = document.getElementById('pe-wizard');
  const palette = document.getElementById('field-dashboard');

  const btnOpenWizard = document.getElementById('pe-open-wizard');
  const btnWizardBack = document.getElementById('pe-wizard-back');
  const btnWizardSave = document.getElementById('pe-wizard-save');
  const btnSaveChanges = document.getElementById('pe-save-changes');
  const btnUnifiedSave = document.getElementById('pe-unified-save');
  const statusText = document.getElementById('pe-status-text');

  const inputFormName = document.getElementById('pe-form-name');
  const inputFormDesc = document.getElementById('pe-form-desc');
  // const selFormResp = document.getElementById('pe-form-resp'); // Rimossa riga singola
  const responsabiliContainer = document.getElementById('pe-responsabili-container');
  const selSection = document.getElementById('mc-section');
  const selParent = document.getElementById('mc-parent');
  const chkAttivo = document.getElementById('mc-attivo');

  const pageFoglio = document.getElementById('form-editor-preview');

  // Salvataggio proprietà (wizard) – crea/aggiorna form e aggiorna UI senza reload
  btnWizardSave?.addEventListener('click', async (e) => {
    e.preventDefault();
    try {
      // Salva il nome del form originale per determinare se stiamo creando o modificando
      const originalFormName = formName;

      const rawname = (inputFormName?.value || '').trim();
      const rawdesc = (inputFormDesc?.value || '').trim();
      const colorHex = (document.getElementById('pe-form-color')?.value || '#CCCCCC');
      // Usa il valore dalla config in memoria (più affidabile dell'input che potrebbe non esistere)
      const buttonText = (formConfig.button_text || '').trim();
      const created = await ensure_form_exists(rawname, rawdesc, colorHex, buttonText);
      if (!created) return;

      // Determina se stiamo creando un nuovo form o modificando uno esistente
      const isNewForm = !originalFormName || originalFormName !== created;

      // Aggiorna stato locale formName e hidden
      formName = created;
      if (formNameHidden) formNameHidden.value = created;
      if (inputFormName && inputFormName.value !== created) inputFormName.value = created;
      statusText && (statusText.textContent = 'Pagina salvata');

      // Aggiorna titolo rtb alla modalità Modifica
      const titleEl = document.querySelector('#rtb-panel .rtb-title');
      if (titleEl) titleEl.textContent = 'Proprietà — Modifica';

      // 🔧 FIX: Aggiungi la voce di menu nella tabella menu_custom
      try {
        const section = selSection?.value || '';
        const parentTitle = selParent?.value || '';
        const attivo = chkAttivo?.checked ? 1 : 0;

        // Crea la voce di menu SOLO se non esiste già una con lo stesso link
        if (section && parentTitle) {
          const formLink = `?page=form&form_name=${encodeURIComponent(created)}`;

          try {
            // Controlla se esiste già una voce di menu con questo stesso link nello stesso menu padre
            console.log('🔍 Controllo esistenza menu per:', { formLink, parentTitle, created });
            const existingMenus = await window.customFetch('menu_custom', 'list', {});
            console.log('📋 Menu esistenti:', existingMenus?.data);
            const menuExists = existingMenus?.success && existingMenus?.data?.some(menu =>
              menu.link === formLink && menu.parent_title === parentTitle
            );
            console.log('🔍 Menu esistente trovato:', menuExists);

            if (!menuExists) {
              // Crea la voce di menu solo se non esiste e i dati sono validi
              if (!created || created.trim() === '' || !isValidMenuLink(formLink)) {
                console.warn('🚫 [MENU_UPSERT_1_BLOCKED] Salvataggio struttura - Upsert BLOCCATO per dati incompleti:', {
                  title: created,
                  link: formLink,
                  isValidLink: isValidMenuLink(formLink),
                  caller: 'save_structure - blocked by guard'
                });
                if (typeof window.showToast === 'function') {
                  showToast('Pagina salvata ma non aggiunta al menu: dati incompleti.', 'warning');
                }
              } else {
                console.log('🔧 [MENU_UPSERT_1] Salvataggio struttura - Chiamata upsert valida:', {
                  title: created,
                  link: formLink,
                  form_name: created,
                  caller: 'save_structure - menu creation',
                  stack: new Error().stack
                });
                const menuResult = await window.customFetch('menu_custom', 'upsert', {
                  menu_section: section,
                  parent_title: parentTitle,
                  title: created,
                  link: formLink,
                  attivo: attivo,
                  ordinamento: 100
                });

                if (!menuResult?.success) {
                  console.warn('Errore inserimento menu:', menuResult?.message);
                  if (typeof window.showToast === 'function') {
                    showToast('Pagina salvata ma non aggiunta al menu. Ricontrolla sezione e menu padre.', 'warning');
                  }
                }
              }
            } else {
              console.log('✅ Voce di menu già esistente per questo form, skip creazione');
            }
          } catch (menuCheckErr) {
            console.warn('Errore controllo menu esistente, procedo con creazione:', menuCheckErr);
            // In caso di errore nel controllo, procedi comunque con la creazione se i dati sono validi
            if (!created || created.trim() === '' || !isValidMenuLink(formLink)) {
              console.warn('🚫 [MENU_UPSERT_2_BLOCKED] Salvataggio struttura (catch) - Upsert BLOCCATO per dati incompleti:', {
                title: created,
                link: formLink,
                isValidLink: isValidMenuLink(formLink),
                caller: 'save_structure - blocked by guard (catch)'
              });
              if (typeof window.showToast === 'function') {
                showToast('Pagina salvata ma non aggiunta al menu: dati incompleti.', 'warning');
              }
            } else {
              console.log('🔧 [MENU_UPSERT_2] Salvataggio struttura (catch) - Chiamata upsert valida:', {
                title: created,
                link: formLink,
                form_name: created,
                caller: 'save_structure - menu creation catch',
                stack: new Error().stack
              });
              const menuResult = await window.customFetch('menu_custom', 'upsert', {
                menu_section: section,
                parent_title: parentTitle,
                title: created,
                link: formLink,
                attivo: attivo,
                ordinamento: 100
              });

              if (!menuResult?.success) {
                console.warn('Errore inserimento menu:', menuResult?.message);
                if (typeof window.showToast === 'function') {
                  showToast('Pagina salvata ma non aggiunta al menu. Ricontrolla sezione e menu padre.', 'warning');
                }
              }
            }
          }
        } else {
          console.warn('Sezione o menu padre non selezionati, voce di menu non creata');
          if (typeof window.showToast === 'function') {
            showToast('Pagina salvata ma non aggiunta al menu. Seleziona sezione e menu padre.', 'warning');
          }
        }
      } catch (menuErr) {
        console.error('Errore inserimento voce di menu:', menuErr);
      }

      // Aggiorna immediatamente la vista States (niente messaggio "salva prima")
      try {
        ensureViews();
        if (views.states) {
          views.states.innerHTML = '';
          await renderStatesPanel(views.states);
        }
      } catch (_) { }

      // Feedback
      if (typeof window.showToast === 'function') showToast('Pagina salvata e registrata nel menu', 'success');
    } catch (err) {
      console.error('Errore salvataggio wizard:', err);
      if (typeof window.showToast === 'function') showToast('Errore salvataggio proprietà', 'error');
    }
  });

  // — datasource cache —
  const dsCache = { tables: null, cols: {} };

  async function ds_getDatasources() {
    if (dsCache.tables) return dsCache.tables;
    try {
      await wait_for_customfetch();
      // Chiamata all'endpoint sicuro (whitelist)
      const r = await window.customFetch('datasource', 'getWhitelistedTables', {});
      dsCache.tables = Array.isArray(r && r.tables) ? r.tables : [];
    } catch (_) { dsCache.tables = []; }
    return dsCache.tables;
  }

  // Helper per ottenere colonne di una tabella (cached)
  async function ds_getColumns(tableName) {
    if (!tableName) return [];
    if (dsCache.cols[tableName]) return dsCache.cols[tableName];
    try {
      const r = await window.customFetch('datasource', 'adminListColumns', { table: tableName });
      let raw = (r.success && Array.isArray(r.columns)) ? r.columns : [];
      // Se sono oggetti, filtra attivi e prendi il nome. Se stringhe, prendi direttamente.
      const cols = raw
        .filter(c => (typeof c === 'string' || c.is_active !== false))
        .map(c => (typeof c === 'object' && c.name) ? c.name : c);

      dsCache.cols[tableName] = cols;
      return cols;
    } catch (e) { console.error(e); return []; }
  }

  function renderDbSelectConfig(fieldCtx, container) {
    // fieldCtx ?? l'oggetto "field" o "child" che contiene la prop "datasource"
    if (!fieldCtx.datasource) fieldCtx.datasource = {};
    const ds = fieldCtx.datasource;

    const cfg = document.createElement('div');
    cfg.className = 'ds-config';
    cfg.innerHTML = `
        <div class="ds-grid" style="display:grid; grid-template-columns:100px 1fr; gap:8px; align-items:center; margin-bottom:6px;">
            <label style="font-weight:600;">Tabella</label>
            <select class="ds-table-select" style="width:100%;">
                <option value="">Caricamento...</option>
            </select>
        </div>
        
        <div class="ds-cols-wrap" style="display:none; border-left:2px solid #ddd; padding-left:8px; margin-bottom:8px;">
             <div class="ds-grid" style="display:grid; grid-template-columns:100px 1fr; gap:8px; align-items:center;">
                <label>Colonna</label>
                <select class="ds-col-single" style="width:100%;"></select>
            </div>
        </div>

        <div style="font-size:11px; color:#666; margin-bottom:8px;">Seleziona una tabella e la colonna da visualizzare.</div>
      `;

    container.appendChild(cfg);

    const selTable = cfg.querySelector('.ds-table-select');
    const colsWrap = cfg.querySelector('.ds-cols-wrap');
    const selSingle = cfg.querySelector('.ds-col-single');

    // Checkbox Multiplo
    const multiWrap = document.createElement('label');
    multiWrap.className = 'ds-multiple-toggle';
    multiWrap.style.cssText = 'display:flex; align-items:center; gap:6px; margin-top:6px;';
    const multiChk = document.createElement('input');
    multiChk.type = 'checkbox';
    multiChk.checked = !!fieldCtx.multiple;
    multiChk.addEventListener('change', () => {
      fieldCtx.multiple = !!multiChk.checked;
      ds.multiple = fieldCtx.multiple ? 1 : 0;
      render_preview(); // Refresh anteprima
    });
    multiWrap.append(multiChk, document.createTextNode('Selezione multipla'));
    cfg.appendChild(multiWrap);

    // Checkbox Custom Valore
    const customWrap = document.createElement('label');
    customWrap.className = 'ds-custom-toggle';
    customWrap.style.cssText = 'display:flex; align-items:center; gap:6px; margin-top:4px;';
    const customChk = document.createElement('input');
    customChk.type = 'checkbox';
    customChk.checked = !!fieldCtx.allow_custom;
    customChk.addEventListener('change', () => {
      fieldCtx.allow_custom = !!customChk.checked;
      render_preview();
    });
    customWrap.append(customChk, document.createTextNode('Permetti valore personalizzato (Altro)'));
    cfg.appendChild(customWrap);

    // Logic
    const populateCols = async (tableName) => {
      colsWrap.style.display = 'block';
      selSingle.innerHTML = '<option>Caricamento...</option>';

      const cols = await ds_getColumns(tableName);
      const opts = cols.map(c => `<option value="${c}">${c}</option>`).join('');

      selSingle.innerHTML = `<option value="">-- Seleziona --</option>` + opts;

      // Restore (valueCol priority)
      if (ds.valueCol && cols.includes(ds.valueCol)) selSingle.value = ds.valueCol;
      else if (ds.labelCol && cols.includes(ds.labelCol)) selSingle.value = ds.labelCol;
    };

    (async () => {
      const tables = await ds_getDatasources();
      const currentTable = ds.table || '';

      selTable.innerHTML = `<option value="">-- Seleziona Tabella --</option>` +
        tables.map(t => `<option value="${t}">${t}</option>`).join('');

      if (currentTable && tables.includes(currentTable)) {
        selTable.value = currentTable;
        await populateCols(currentTable);
      } else if (currentTable) {
        // Caso legacy o tabella non più in whitelist
        selTable.innerHTML += `<option value="${currentTable}" selected disabled>⚠️ ${currentTable} (Non whitelisted)</option>`;
        await populateCols(currentTable);
      }
    })();

    selTable.addEventListener('change', async () => {
      const t = selTable.value;
      ds.table = t;
      ds.valueCol = '';
      ds.labelCol = '';
      delete ds.datasource;

      if (t) await populateCols(t);
      else colsWrap.style.display = 'none';

      render_preview();
    });

    selSingle.addEventListener('change', () => {
      // Single column maps to both
      ds.valueCol = selSingle.value;
      ds.labelCol = selSingle.value;
      render_preview();
    });

  }

  function wirePaletteFieldDrag() {
    if (!palette) return;

    if (!palette.querySelector('.field-card[data-type="section"]')) {
      const sec = document.createElement('div');
      sec.className = 'field-card field-card--section';
      sec.draggable = true;
      sec.dataset.type = 'section';
      sec.setAttribute('data-tooltip', 'trascina una sezione (contenitore)');
      const t = document.createElement('span'); t.className = 'fc-title'; t.textContent = 'sezione';
      const s = document.createElement('span'); s.className = 'fc-sub'; s.textContent = '(fieldset)';
      sec.append(t, s);
      palette.prepend(sec);
    }

    const cards = palette.querySelectorAll('.field-card[data-type]');
    cards.forEach(card => {
      if (card.dataset.wired === '1') return;
      card.dataset.wired = '1';
      card.draggable = true;
      card.addEventListener('dragstart', (e) => {
        const type = card.dataset.type;
        e.dataTransfer.setData('field-type', type);
        e.dataTransfer.setData('application/x-pe-drag', 'field');
        e.dataTransfer.setData('pe-origin', 'fields');
        e.dataTransfer.setData('pe-origin/fields', '1');
        try { e.dataTransfer.effectAllowed = 'copy'; } catch (_) { }
      });
    });
  }

  // — schema moduli —
  const MODULE_CONFIG_SCHEMAS = {
    gestione_richiesta: {
      title: 'Gestione della richiesta',
      fields: [
        {
          key: 'permessi', label: 'Chi può modificare', type: 'select', options: [
            { v: 'responsabile_o_assegnatario', l: 'Responsabile o assegnatario' },
            { v: 'solo_responsabile', l: 'Solo responsabile' },
            { v: 'admin_responsabile_assegnatario', l: 'Admin, responsabile o assegnatario' }
          ], default: 'responsabile_o_assegnatario'
        },
        { key: 'mostra_assegna', label: 'Mostra sezione "Assegna a"', type: 'checkbox', default: true },
        { key: 'consenti_forza_admin', label: 'Mostra pulsante "Risolvi come admin"', type: 'checkbox', default: true },
        {
          key: 'stati_visibili', label: 'Stati abilitati', type: 'checkbox-group', options: [
            { v: 1, l: 'Aperta' }, { v: 2, l: 'In corso' }, { v: 3, l: 'Sospesa' }, { v: 4, l: 'Rifiutata' }, { v: 5, l: 'Chiusa' }
          ], default: [1, 2, 3, 4, 5]
        },
        { key: 'nota_obbligatoria', label: 'Note obbligatorie quando si cambia stato', type: 'checkbox', default: false },
        {
          key: 'notifiche', label: 'Notifiche', type: 'group', fields: [
            {
              key: 'invio', label: 'Invia notifica quando', type: 'checkbox-group', options: [
                { v: 'on_assign', l: 'Viene cambiato l’assegnatario' },
                { v: 'on_status_change', l: 'Cambia lo stato esito' },
                { v: 'on_due_change', l: 'Cambia la data programmata di chiusura' }
              ], default: ['on_assign', 'on_status_change']
            },
            {
              key: 'canale', label: 'Canale', type: 'select', options: [
                { v: 'interno', l: 'Notifica interna' },
                { v: 'email', l: 'Email' },
                { v: 'entrambi', l: 'Entrambi' }
              ], default: 'interno'
            }
          ]
        },
        {
          key: 'ui', label: 'Aspetto', type: 'group', fields: [
            { key: 'mostra_avatar', label: 'Mostra avatar assegnatario', type: 'checkbox', default: true },
            { key: 'mostra_badge_assegnato', label: 'Mostra badge assegnatario', type: 'checkbox', default: true }
          ]
        }
      ]
    }
  };

  // — stato —
  // Inizializza formName dall'URL come fonte principale, campo nascosto come fallback
  // Normalizza query con spazi attorno a ?, & e = (es. "index.php ? section = ...")
  const normalizedSearch = (window.location.search || '').replace(/\s*([?&=])\s*/g, '$1');
  const urlParams = new URLSearchParams(normalizedSearch);
  let formName = (urlParams.get('form_name') || formNameHidden?.value || '').trim();
  let fields = [];

  let stagedModules = [];
  const stagedConfig = {};

  const CAMPI_FISSI = [
    { name: 'titolo', type: 'text', options: [], is_fixed: true },
    { name: 'descrizione', type: 'textarea', options: [], is_fixed: true },
    { name: 'deadline', type: 'date', options: [], is_fixed: true },
    { name: 'priority', type: 'select', options: ['bassa', 'media', 'alta'], is_fixed: true },
    { name: 'assegnato_a', type: 'text', options: [], is_fixed: true }
  ];

  // Fallback labels per i 5 campi fissi (solo se placeholder vuoto)
  const FIXED_LABELS = {
    titolo: 'Titolo',
    descrizione: 'Descrizione',
    deadline: 'Scadenza',
    priority: 'Priorità',
    assegnato_a: 'Assegnato a'
  };

  // — schede dinamiche —
  const DEFAULT_TAB_KEY = 'struttura';
  const PROPERTIES_TAB_KEY = 'proprieta';
  let tabs = [
    { key: DEFAULT_TAB_KEY, label: 'Struttura', fixed: true },
    { key: 'esito', label: 'Esito', fixed: true }
  ];
  const MAX_TABS_TOTAL = 5;

  // mappa: keyScheda -> { fields: [...], hasFixed:boolean, submitLabel: string|null, submitAction: 'submit'|'next_step' }
  const tabState = {};
  tabState[DEFAULT_TAB_KEY] = {
    fields: null,
    hasFixed: true,
    submitLabel: null,
    submitAction: 'submit',
    isMain: true,
    isClosureTab: false
  };
  tabState['esito'] = {
    fields: [],
    hasFixed: false,
    submitLabel: 'Concludi Segnalazione',
    submitAction: 'submit',
    isMain: false,
    isClosureTab: true,
    schedaType: 'chiusura'
  };
  tabState[PROPERTIES_TAB_KEY] = { fields: null, hasFixed: true, submitLabel: null, submitAction: 'submit' };

  let activeTabKey = DEFAULT_TAB_KEY;

  const boolish = (value) => {
    if (typeof value === 'string') {
      const normalized = value.trim().toLowerCase();
      return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'si';
    }
    return !!value;
  };

  // Helper to convert snake_case/kebab-case to Title Case
  const beautifyLabel = (str) => {
    if (!str) return '';
    return str
      .replace(/_/g, ' ')
      .replace(/-/g, ' ')
      .split(' ')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(' ');
  };

  // helper per slug del nome scheda
  function slugifyTabName(s) {
    return sanitize_slug(s || 'scheda');
  }

  // Funzioni per gestire la visualizzazione delle viste
  function showWizardView() {
    const editorStageEl = document.getElementById('editor-stage');
    const wizardStageEl = document.getElementById('pe-wizard');
    const paletteEl = document.getElementById('field-dashboard');
    const rightToolbar = document.getElementById('pe-right-toolbar');

    if (editorStageEl) editorStageEl.style.display = 'none';
    if (paletteEl) paletteEl.style.display = 'none';
    if (rightToolbar) rightToolbar.style.display = 'none';
    if (wizardStageEl) wizardStageEl.style.display = 'block';

    // Carica i dati del wizard se necessario
    loadSectionsAndParents().catch(() => { });
    loadResponsabiliOptions().catch(() => { });
    openWizardPrefilledForEdit().catch(() => { });
  }

  function showEditorView() {
    const editorStageEl = document.getElementById('editor-stage');
    const wizardStageEl = document.getElementById('pe-wizard');
    const paletteEl = document.getElementById('field-dashboard');
    const rightToolbar = document.getElementById('pe-right-toolbar');

    if (wizardStageEl) wizardStageEl.style.display = 'none';
    if (editorStageEl) editorStageEl.style.display = 'block';
    if (paletteEl) paletteEl.style.display = 'block';
    if (rightToolbar) rightToolbar.style.display = 'block';
  }

  // quando si cambia scheda, salvaguardiamo i campi correnti
  async function switchToTab(nextKey) {
    if (!nextKey || nextKey === activeTabKey) return;

    // salva i campi della scheda corrente (solo se non è la scheda proprietà)
    if (activeTabKey !== PROPERTIES_TAB_KEY) {
      tabState[activeTabKey] = tabState[activeTabKey] || {};
      // Salva i campi della scheda corrente
      if (activeTabKey === DEFAULT_TAB_KEY) {
        // Per la scheda principale, salva tutti i campi (fissi + personalizzati)
        // SICUREZZA: rimuovi eventuali undefined/null
        tabState[activeTabKey].fields = fields.filter(f => f); // Clone e pulizia
      } else {
        // Per le altre schede, salva solo i campi personalizzati (RIMUOVI fissi se presenti)
        const cleaned = fields.filter(f => f && !f.is_fixed); // SICUREZZA: salta undefined/null
        tabState[activeTabKey].fields = [...cleaned]; // Clone dell'array

        // DEBUG: Avvisa se ci sono stati campi fissi rimossi
        if (cleaned.length !== fields.length) {
          console.warn(`[switchToTab] Rimossi ${fields.length - cleaned.length} campi fissi dalla scheda "${activeTabKey}"`);
        }
      }
    }

    // carica i campi della scheda destinazione
    if (nextKey === PROPERTIES_TAB_KEY) {
      // Per la scheda proprietà, carichiamo prima i dati del form per prefillare il wizard
      await loadAndSetFormColor();
      activeTabKey = nextKey;
      showWizardView();
      return;
    } else {
      // Per le altre schede, carichiamo i campi normalmente
      const st = (tabState[nextKey] = tabState[nextKey] || { fields: [], hasFixed: false, submitLabel: null, submitAction: 'submit' });
      let customFields = Array.isArray(st.fields) ? st.fields : [];

      // DEBUG
      console.log(`[switchToTab] Caricamento scheda "${nextKey}"`);
      console.log(`[switchToTab] customFields.length:`, customFields.length);
      console.log(`[switchToTab] st:`, st);

      if (nextKey === DEFAULT_TAB_KEY) {
        // Per la scheda principale, carica tutti i campi salvati (fissi + personalizzati)
        // SICUREZZA: filtra undefined/null prima di elaborare
        customFields = customFields.filter(f => f);

        // Ma assicurati che ci siano i campi fissi
        const hasFixed = customFields.some(f => f && f.is_fixed);
        if (!hasFixed && customFields.length > 0) {
          // Aggiungi i campi fissi all'inizio
          fields = [...CAMPI_FISSI.map(f => ({ ...f })), ...customFields];
          tabState[nextKey].fields = fields; // Aggiorna lo stato
        } else if (!customFields.length) {
          // Scheda Struttura vuota: inizializza con i campi fissi
          fields = CAMPI_FISSI.map(f => ({ ...f }));
          tabState[nextKey].fields = fields;
        } else {
          fields = customFields.filter(f => f); // Clone e pulizia
        }
      } else {
        // Per le altre schede, carica solo i campi personalizzati (FILTRA i fissi)
        const cleaned = customFields.filter(f => f && !f.is_fixed); // SICUREZZA: salta undefined/null
        fields = [...cleaned]; // Clone dell'array

        // DEBUG: Avvisa se ci sono stati campi fissi filtrati
        if (cleaned.length !== customFields.length) {
          console.warn(`[switchToTab] Filtrati ${customFields.length - cleaned.length} campi fissi dalla scheda personalizzata "${nextKey}"`);
          tabState[nextKey].fields = cleaned; // Aggiorna lo stato pulito
        }

        // Aggiorna tabState con i campi puliti
        if (!tabState[nextKey]) {
          tabState[nextKey] = { fields: [], hasFixed: false, submitLabel: null, submitAction: 'submit' };
        }
        tabState[nextKey].fields = fields; // Assicura che tabState sia sincronizzato

        console.log(`[switchToTab] Dopo caricamento, fields.length:`, fields.length);
        console.log(`[switchToTab] fields:`, fields);
      }

      activeTabKey = nextKey;
      showEditorView();
      render_preview();
      // addSubmitButtonPreview viene già chiamato alla fine di render_preview()

      // Aggiorna il pannello "Schede" se è aperto
      if (typeof window.updateTabsPanelIfOpen === 'function') {
        window.updateTabsPanelIfOpen();
      }

      // Non salvare automaticamente: verrà salvato tutto con save_structure()
      // try { persistTabs(); } catch (_) { }
    }
  }

  // crea una nuova scheda vuota
  async function createNewTab(label) {
    if (tabs.length >= MAX_TABS_TOTAL) {
      showToast?.(`Limite massimo di ${MAX_TABS_TOTAL} schede raggiunto.`, 'info');
      return;
    }
    const key = (() => {
      let base = slugifyTabName(label || 'nuova_scheda').replace(/^struttura$/, 'struttura_custom');
      let k = base, i = 2;
      while (tabs.some(t => t.key === k)) k = `${base}_${i++}`;
      return k;
    })();

    tabs.push({ key, label: label || '', fixed: false });
    tabState[key] = {
      fields: [],
      hasFixed: false,
      submitLabel: null,
      submitAction: 'submit',
      // WORKFLOW: Inizializza proprietà workflow con default
      visibilityRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
      editRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
      visibilityCondition: { type: 'always' },
      redirectAfterSubmit: false,
      schedaType: 'utente' // Default: nuova scheda è "utente"
    };
    buildTabsBar();           // ricostruisce la UI dei tab
    switchToTab(key);         // attiva subito la nuova scheda

    // Non salvare automaticamente: verrà salvato tutto con save_structure()
    // try { persistTabs(); } catch (_) { }
  }

  function sanitize_slug(s) {
    return String(s || '')
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9_]+/g, '_')
      .replace(/^_+|_+$/g, '')
      || 'pagina';
  }

  // editing inline del nome scheda
  function startInlineEdit(tabButton) {
    const tabKey = tabButton.getAttribute('data-tab-key');
    const tab = tabs.find(t => t.key === tabKey);
    if (!tab) return;
    // Rimosso il controllo tab.fixed per permettere rinomina anche della scheda "Struttura"

    const originalText = tabButton.textContent;

    // Container per input e pulsanti
    const editContainer = document.createElement('div');
    editContainer.className = 'tab-edit-container';
    editContainer.style.cssText = `
      display: flex;
      align-items: center;
      gap: 4px;
    `;

    const input = document.createElement('input');
    input.type = 'text';
    input.value = tab.label || '';
    input.className = 'tab-inline-edit';
    input.style.cssText = `
      background: transparent;
      border: 1px solid #007bff;
      border-radius: 3px;
      padding: 2px 6px;
      font-size: inherit;
      font-family: inherit;
      color: inherit;
      width: 120px;
      outline: none;
    `;

    // Pulsante conferma
    const confirmBtn = document.createElement('button');
    confirmBtn.textContent = '✓';
    confirmBtn.className = 'tab-edit-confirm';
    confirmBtn.style.cssText = `
      background: #28a745;
      color: white;
      border: none;
      border-radius: 3px;
      padding: 2px 6px;
      font-size: 12px;
      cursor: pointer;
      line-height: 1;
    `;

    // Pulsante annulla
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = '✗';
    cancelBtn.className = 'tab-edit-cancel';
    cancelBtn.style.cssText = `
      background: #dc3545;
      color: white;
      border: none;
      border-radius: 3px;
      padding: 2px 6px;
      font-size: 12px;
      cursor: pointer;
      line-height: 1;
    `;

    // Aggiungi classe editing al pulsante durante la modifica
    tabButton.classList.add('editing');

    const finishEdit = () => {
      const newLabel = input.value.trim() || 'Nuova scheda';
      tab.label = newLabel;
      tabButton.textContent = newLabel;
      tabButton.title = newLabel;
      // Rimuovi classe editing
      tabButton.classList.remove('editing');
      // Non salvare automaticamente: verrà salvato tutto con save_structure()
      // try { persistTabs(); } catch (_) { }
    };

    const cancelEdit = () => {
      tabButton.textContent = originalText;
      tabButton.title = originalText;
      // Rimuovi classe editing
      tabButton.classList.remove('editing');
    };

    const closeEdit = () => {
      // Rimuovi classe editing anche qui per sicurezza
      tabButton.classList.remove('editing');
      if (typeof window.buildTabsBar === 'function') window.buildTabsBar();
    };

    // Event listeners per i pulsanti
    confirmBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      finishEdit();
      closeEdit();
    });

    cancelBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      cancelEdit();
      closeEdit();
    });

    // Impedisce che eventi del mouse o tastiera nell'input e nei pulsanti facciano scattare pulsanti padre o ri-renderizzazioni
    const stopEvents = ['click', 'mousedown', 'mouseup', 'keydown', 'keyup'];
    [input, confirmBtn, cancelBtn].forEach(element => {
      stopEvents.forEach(evt => {
        element.addEventListener(evt, (e) => {
          e.stopPropagation();
        });
      });
    });

    input.addEventListener('keydown', (e) => {
      // e.stopPropagation() è già gestito dal ciclo sopra, qui gestiamo i tasti speciali
      if (e.key === 'Enter') {
        e.preventDefault();
        finishEdit();
        closeEdit();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        cancelEdit();
        closeEdit();
      }
      // Ora spazio e altri tasti possono essere usati liberamente nell'input
    });

    // Aggiungi elementi al container
    editContainer.appendChild(input);
    editContainer.appendChild(confirmBtn);
    editContainer.appendChild(cancelBtn);

    tabButton.textContent = '';
    tabButton.appendChild(editContainer);
    input.focus();
    input.select();
  }

  // — meta-block parametrico (user vs esito) —
  function buildMetaBlockContent(mode) {
    const isEsito = (mode === 'esito');
    const esc = (s) => window.escapeHtml ? window.escapeHtml(String(s || '')) : String(s || '');

    // Titolo e descrizione: in esito sono readonly e letti dagli input utente
    const titolo = esc((document.getElementById('pe-meta-titolo')?.value || '').trim());
    const descrizione = esc((document.getElementById('pe-meta-descrizione')?.value || '').trim());

    // Suffisso per IDs e names
    const sfx = isEsito ? '-esito' : '';
    const idPfx = isEsito ? 'pe-meta-' : 'pe-meta-';

    // Titolo
    const titoloHtml = isEsito
      ? `<span class="meta-value" style="padding:8px 12px; color:#555;">${titolo || '<span style="color:#999;">—</span>'}</span>`
      : `<span class="meta-value"><input type="text" class="input-title" name="pe-meta-titolo" id="pe-meta-titolo" placeholder="Inserisci il titolo" value="${titolo}" disabled></span>`;

    // Descrizione
    const descHtml = isEsito
      ? `<span class="meta-value" style="padding:8px 12px; color:#555;">${descrizione || '<span style="color:#999;">—</span>'}</span>`
      : `<span class="meta-value"><textarea name="pe-meta-descrizione" id="pe-meta-descrizione" placeholder="Inserisci la descrizione..." disabled>${descrizione}</textarea></span>`;

    // Data apertura & deadline (se vuota, autocompile con data odierna)
    const todayStr = (() => {
      const n = new Date();
      return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
    })();
    const dataAperturaVal = isEsito ? (document.getElementById('pe-meta-data-apertura-esito')?.value || todayStr) : '';
    const dataAperturaHtml = isEsito
      ? `<input type="date" id="pe-meta-data-apertura-esito" name="data_apertura_esito" value="${esc(dataAperturaVal)}" disabled>`
      : `${new Date().toLocaleDateString('it-IT')}`;
    const deadlineHtml = isEsito
      ? `<input type="date" id="pe-meta-deadline-esito" name="deadline_esito" disabled>`
      : `<input type="date" name="pe-meta-deadline" id="pe-meta-deadline" value="${esc(document.getElementById('pe-meta-deadline')?.value || '')}" disabled>`;

    // Creato da / Assegnato a
    const creatoHtml = isEsito ? '—' : '—';
    const assegnatoHtml = isEsito
      ? `<input type="text" id="pe-meta-assegnato-esito" name="assegnato_a_esito" placeholder="Responsabile esito" disabled style="border:1px solid #d0d7de; border-radius:4px; padding:6px 8px; width:100%;">`
      : (document.getElementById('pe-meta-responsabile-display')?.innerHTML || '<span style="color:#999;">—</span>');

    // Priorita & Stato
    const prioritaId = isEsito ? 'pe-meta-priorita-esito' : 'pe-meta-priority';
    const prioritaName = isEsito ? 'priorita_esito' : 'pe-meta-priority';
    const statoHtml = isEsito
      ? `<select id="pe-meta-stato-esito" name="stato_esito" disabled>
           <option value="Aperta">Aperta</option>
           <option value="In corso">In corso</option>
           <option value="Chiusa">Chiusa</option>
         </select>`
      : `<select disabled>
           <option selected>Aperta</option>
           <option>In corso</option>
           <option>Chiusa</option>
         </select>`;

    const sectionLabel = isEsito
      ? `<div class="form-meta-header">
           Dati Esito del Responsabile
         </div>`
      : '';

    return `
      ${sectionLabel}
      <!-- 1. Titolo -->
      <div class="meta-row">
        <span class="label">Titolo:</span>
        ${titoloHtml}
      </div>
      <!-- 2. Descrizione -->
      <div class="meta-row">
        <span class="label">Descrizione:</span>
        ${descHtml}
      </div>
      <!-- 3. Data apertura | Deadline -->
      <div class="meta-row meta-row-split">
        <span class="label-split">${isEsito ? 'Data apertura (esito):' : 'Data di apertura:'}</span>
        <span class="value-split">${dataAperturaHtml}</span>
        <span class="label-split">${isEsito ? 'Scadenza (esito):' : 'Data di scadenza:'}</span>
        <span class="value-split">${deadlineHtml}</span>
      </div>
      <!-- 4. Creato da | Assegnato a -->
      <div class="meta-row meta-row-split">
        <span class="label-split">Creato da:</span>
        <span class="value-split">${creatoHtml}</span>
        <span class="label-split">${isEsito ? 'Assegnato a (esito):' : 'Assegnato a:'}</span>
        <span class="value-split">${assegnatoHtml}</span>
      </div>
      <!-- 5. Priorita | Stato -->
      <div class="meta-row meta-row-split">
        <span class="label-split">${isEsito ? 'Priorità (esito):' : 'Priorità:'}</span>
        <span class="value-split">
          <select name="${prioritaName}" id="${prioritaId}" disabled>
            <option value="Bassa">Bassa</option>
            <option value="Media" selected>Media</option>
            <option value="Alta">Alta</option>
          </select>
        </span>
        <span class="label-split">${isEsito ? 'Stato (esito):' : 'Stato:'}</span>
        <span class="value-split">${statoHtml}</span>
      </div>`;
  }

  // — render —
  function render_preview() {
    // Gestione visibilità blocco meta standard ed esito dell'editor
    const standardMeta = document.getElementById('pe-standard-meta-block');
    const esitoMeta = document.getElementById('pe-esito-meta-block');
    // === LOGICA VISIBILITÀ HEADER ESTERNI ===
    // 'standardMeta' è l'header fisso in alto (Titolo, Descrizione, etc.)
    // Lo mostriamo SEMPRE per Struttura, Generale ed Esito (Closure Tabs), così fa da "Meta Block" iniziale.
    // Nascondiamo invece 'esitoMeta' perché useremo il blocco interno "Yellow Block" per i campi di chiusura in fondo.

    const showStandardMeta = (activeTabKey === DEFAULT_TAB_KEY || activeTabKey === 'struttura' || activeTabKey === 'generale' || (tabState[activeTabKey] && tabState[activeTabKey].isClosureTab));

    if (standardMeta) {
      standardMeta.style.display = showStandardMeta ? '' : 'none';
      if (showStandardMeta) standardMeta.style.marginBottom = '12px';

      // Se siamo in un tab di chiusura, aggiorniamo eventuali label se necessario, 
      // ma di base standardMeta va bene per Titolo/Desc/Assegnatario(Readonly)
    }

    if (esitoMeta) {
      esitoMeta.style.display = 'none'; // SEMPRE NASCOSTO - I campi di chiusura sono ora nel blocco giallo in fondo
    }

    preview.innerHTML = '';

    const grid = document.createElement('div');
    grid.className = 'pe-grid';
    grid.style.display = 'grid';

    grid.style.gridTemplateColumns = '1fr 1fr';
    grid.style.gap = '12px 12px';
    grid.style.marginBottom = '12px';
    preview.appendChild(grid);

    // DEBUG: verifica fields
    console.log('[render_preview] activeTabKey:', activeTabKey);

    // SICUREZZA: assicura che fields sia un array valido
    if (!Array.isArray(fields)) {
      console.error('[render_preview] ERRORE: fields non è un array!', fields);
      fields = [];
    }

    // SICUREZZA: se fields è vuoto ma tabState ha campi, ricarica
    if (!fields.length && tabState[activeTabKey] && Array.isArray(tabState[activeTabKey].fields) && tabState[activeTabKey].fields.length > 0) {
      console.warn('[render_preview] fields vuoto ma tabState ha campi, ricarico...');
      fields = tabState[activeTabKey].fields.filter(f => f && !f.is_fixed);
    }

    // REMOVING DUPLICATE META PREVIEW
    // La parte che renderizzava manualmente il form-meta-block è stata rimossa 
    // perché ora usiamo 'standardMeta' esterno visibile.


    // EMPTY STATE: Se non ci sono campi, mostra placeholder
    // MA: Se è una tab di chiusura (Esito), il blocco giallo di chiusura viene comunque renderizzato dopo.
    // Quindi mostriamo il placeholder SOLO come invito a droppare, ma senza duplicare header o footer.
    if (!fields.length) {
      const emptyState = document.createElement('div');
      emptyState.style.gridColumn = "1 / -1";
      emptyState.style.border = "2px dashed #dce4ec";
      emptyState.style.borderRadius = "8px";
      emptyState.style.padding = "30px";
      emptyState.style.textAlign = "center";
      emptyState.style.backgroundColor = "#f9fbfd";
      emptyState.style.color = "#7f8c8d";
      emptyState.style.margin = "'0'";

      emptyState.innerHTML = `
        <div style="font-size:24px; margin-bottom:10px; color:#bdc3c7;">✚</div>
        <div style="font-weight:600; font-size:14px; margin-bottom:5px;">Area Campi Personalizzati</div>
        <div style="font-size:12px; color:#95a5a6;">Trascina qui i campi dalla barra laterale per aggiungerli a questa scheda.</div>
      `;

      // ABILITA DROP SULL'EMPTY STATE
      emptyState.addEventListener('dragover', (e) => {
        if (!isAllowedExternalDrag(e, 'structure')) return;
        e.preventDefault(); e.stopPropagation();
        try { e.dataTransfer.dropEffect = 'copy'; } catch { }
        emptyState.style.borderColor = '#007bff';
        emptyState.style.backgroundColor = '#eef6fc';
      });
      emptyState.addEventListener('dragleave', (e) => {
        emptyState.style.borderColor = '#dce4ec';
        emptyState.style.backgroundColor = '#f9fbfd';
      });
      emptyState.addEventListener('drop', (e) => {
        e.preventDefault(); e.stopPropagation();
        emptyState.style.borderColor = '#dce4ec';
        emptyState.style.backgroundColor = '#f9fbfd';

        if (!isAllowedExternalDrag(e, 'structure')) return;
        const paletteType = e.dataTransfer.getData('field-type');
        if (paletteType && paletteType !== 'section') {
          // Aggiungi nuovo campo
          const nuovo = {
            uid: 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
            name: '',
            type: paletteType,
            options: ['select', 'checkbox', 'radio'].includes(paletteType) ? ['opzione 1'] : [],
            is_fixed: false,
            colspan: 1
          };
          // Se è tab esito, assicurati che vada in fields
          if (!fields) fields = [];
          fields.push(nuovo);
          render_preview();
        }
      });

      grid.appendChild(emptyState);

      // NOTA: Il blocco di chiusura (se necessario) verrà aggiunto DOPO, outside this if,
      // dalla logica generale che controlla isClosureTab.
      // Quindi NON lo aggiungiamo qui per evitare duplicati.
    }

    fields.forEach((field, i) => {
      // SICUREZZA: Salta elementi undefined/null
      if (!field) return;

      // I campi fissi (titolo, descrizione, deadline, priority, assegnato_a) 
      // sono già nel form-meta-block, quindi non li renderizziamo qui
      if (field.is_fixed) {
        return; // Salta completamente - già nel form-meta-block
      }

      // — sezione —
      if (field.type === 'section') {
        // Assicura che il campo abbia un UID
        if (!field.uid) field.uid = 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        const wrap = document.createElement('div');
        wrap.className = 'editor-group editor-section full';
        wrap.dataset.index = i;
        wrap.dataset.uid = field.uid;
        wrap.draggable = true;
        // 🔧 FIX: Le sezioni DEVONO sempre occupare tutta la riga (2 colonne)
        field.colspan = 2;
        wrap.style.gridColumn = '1 / -1'; // Forza l'occupazione di tutte le colonne

        const drag = document.createElement('span');
        drag.className = 'drag-handle icon-handle';
        drag.innerHTML = '⠿';
        wrap.appendChild(drag);

        const remove = document.createElement('img');
        remove.src = 'assets/icons/delete.png';
        remove.alt = 'rimuovi sezione';
        remove.className = 'icon-delete';
        remove.onclick = () => { fields.splice(i, 1); render_preview(); };
        wrap.appendChild(remove);

        const legend = document.createElement('div');
        legend.className = 'section-legend';
        const muted = document.createElement('span');
        muted.className = 'muted';
        muted.textContent = 'sezione:';
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'section-title';
        titleInput.value = String(field.label || 'sezione');
        titleInput.addEventListener('input', (e) => { field.label = String(e.target.value || '').trim(); });
        legend.append(muted, titleInput);
        wrap.appendChild(legend);
        const sgrid = document.createElement('div');
        sgrid.className = 'section-grid';
        wrap.appendChild(sgrid);

        let secDraggedIndex = null;
        let secSlot = null;
        sgrid.style.minHeight = '48px';

        function ensureSecSlot() {
          if (!secSlot) { secSlot = document.createElement('div'); secSlot.className = 'pe-slot-marker'; }
          return secSlot;
        }
        function clearSecSlot() {
          if (secSlot && secSlot.parentNode) secSlot.parentNode.removeChild(secSlot);
          secSlot = null;
        }

        wrap.addEventListener('dragover', (e) => {
          // riordino figli → lascia alla grid
          if (isInternalDrag && e.dataTransfer?.getData?.('pe-internal-sec')) return;

          if (isInternalDrag) {
            e.preventDefault(); e.stopPropagation();
            try { e.dataTransfer.dropEffect = 'move'; } catch { }
            const slot = ensureSecSlot();
            slot.classList.toggle('full', !!e.altKey);
            const child = e.target.closest('.editor-group[data-index]');
            if (!child || !sgrid.contains(child)) {
              if (!slot.parentNode || slot.parentNode !== sgrid) sgrid.appendChild(slot);
            }
            return;
          }

          if (!isAllowedExternalDrag(e, 'structure')) return;
          e.preventDefault(); e.stopPropagation();
          try { e.dataTransfer.dropEffect = 'copy'; } catch { }
          const slot = ensureSecSlot();
          slot.classList.toggle('full', !!e.altKey);
          const child = e.target.closest('.editor-group[data-index]');
          if (!child || !sgrid.contains(child)) {
            if (!slot.parentNode || slot.parentNode !== sgrid) sgrid.appendChild(slot);
          }
        });

        wrap.addEventListener('drop', (e) => {
          // riordino figli → lascia alla grid
          if (isInternalDrag && e.dataTransfer?.getData?.('pe-internal-sec')) return;

          const computeInsertAt = () => {
            let insertAt = Array.isArray(field.children) ? field.children.length : 0;
            if (secSlot) {
              let n = 0;
              Array.from(sgrid.children).forEach(node => {
                if (node === secSlot) return;
                if (node.classList && node.classList.contains('editor-group') && node.hasAttribute('data-index')) n++;
              });
              insertAt = n;
            }
            return insertAt;
          };

          // ROOT → SEZIONE (anche se drop su bordo/header)
          const fromRootIdxStr = e.dataTransfer.getData('pe-from-root-index');
          if (fromRootIdxStr !== '') {
            e.preventDefault(); e.stopPropagation();
            try { e.dataTransfer.dropEffect = 'move'; } catch { }
            const src = parseInt(fromRootIdxStr, 10);
            if (!Number.isNaN(src)) {
              const moved = fields[src];
              if (moved?.is_fixed) { showToast('i campi fissi non possono essere spostati nelle sezioni.', 'error'); clearSecSlot(); return; }
              if (moved?.type === 'section') { showToast('non puoi annidare una sezione dentro un’altra.', 'error'); clearSecSlot(); return; }
              if (moved?.type === 'file') {
                const anyFile = fields.some(f => f.type === 'file' || (Array.isArray(f.children) && f.children.some(c => c.type === 'file')));
                if (anyFile) { showToast('è consentito un solo campo upload per scheda (anche dentro le sezioni).', 'error'); clearSecSlot(); return; }
              }
              const insertAt = computeInsertAt();
              fields.splice(src, 1);
              const nuovo = { ...moved, colspan: (secSlot && secSlot.classList.contains('full')) ? 2 : 1 };
              field.children = Array.isArray(field.children) ? field.children : [];
              field.children.splice(insertAt, 0, nuovo);
              clearSecSlot(); render_preview();
            }
            return;
          }

          // PALETTE → SEZIONE
          if (!isAllowedExternalDrag(e, 'structure')) return;
          if (!e.target.closest('.section-grid')) {
            e.preventDefault(); e.stopPropagation();
            try { e.dataTransfer.dropEffect = 'copy'; } catch { }
            const readPaletteType = () => {
              let t = e.dataTransfer.getData('field-type');
              if (!t) {
                const txt = e.dataTransfer.getData('text/plain') || '';
                const m = /^field:([a-z0-9_]+)$/i.exec(String(txt));
                if (m) t = m[1].toLowerCase();
              }
              return t;
            };

            const paletteType = readPaletteType();
            if (!paletteType || paletteType === 'section') { clearSecSlot(); return; }
            if (paletteType === 'file') {
              const anyFile = fields.some(f => f.type === 'file' || (Array.isArray(f.children) && f.children.some(c => c.type === 'file')));
              if (anyFile) { showToast('è consentito un solo campo upload per scheda.', 'error'); clearSecSlot(); return; }
            }

            const insertAt = computeInsertAt();
            const nuovo = {
              uid: 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
              name: '',
              type: paletteType,
              options: ['select', 'checkbox', 'radio'].includes(paletteType) ? ['opzione 1'] : [],
              is_fixed: false,
              colspan: (secSlot && secSlot.classList.contains('full')) ? 2 : 1
            };
            field.children = Array.isArray(field.children) ? field.children : [];
            field.children.splice(insertAt, 0, nuovo);
            clearSecSlot(); render_preview();
          }
        });

        // DnD figli sezione - USA UID
        sgrid.addEventListener('dragstart', (e) => {
          const t = e.target.closest('.editor-group[data-uid]');
          if (!t) return;
          const uid = t.dataset.uid;
          if (!uid) return;

          // Verifica che sia un child (ha ':' nell'index)
          const idxStr = String(t.dataset.index || '');
          if (!idxStr.includes(':')) return;

          isInternalDrag = true;
          e.dataTransfer.setData('pe-internal-sec', '1');
          e.dataTransfer.setData('pe-dragged-child-uid', uid);
          e.dataTransfer.setData('pe-section-index', String(i));
          e.dataTransfer.effectAllowed = 'move';
          setTimeout(() => t.classList.add('dragging'), 1);
        });

        sgrid.addEventListener('dragend', () => {
          isInternalDrag = false;
          clearSecSlot();
        });

        sgrid.addEventListener('dragover', (e) => {
          e.preventDefault(); e.stopPropagation();
          try { e.dataTransfer.dropEffect = isInternalDrag ? 'move' : 'copy'; } catch { }
          const slot = ensureSecSlot();
          slot.classList.toggle('full', !!e.altKey);

          const empty = sgrid.querySelector('.pe-slot-marker--empty');
          if (empty && empty.parentNode) empty.parentNode.removeChild(empty);

          const child = e.target.closest('.editor-group[data-index]');
          if (!child || !sgrid.contains(child)) {
            if (!slot.parentNode || slot.parentNode !== sgrid) sgrid.appendChild(slot);
            return;
          }

          const r = child.getBoundingClientRect();
          const before = e.clientY <= (r.top + r.height / 2);
          if (before) sgrid.insertBefore(slot, child);
          else { const next = child.nextElementSibling; if (next) sgrid.insertBefore(slot, next); else sgrid.appendChild(slot); }
        });

        sgrid.addEventListener('drop', (e) => {
          e.preventDefault(); e.stopPropagation();
          try { clearDropSlot(); pageFoglio?.classList.remove('pe-dropping'); } catch { }
          try { e.dataTransfer.dropEffect = isInternalDrag ? 'move' : 'copy'; } catch { }

          const readPaletteType = () => {
            let t = e.dataTransfer.getData('field-type');
            if (!t) {
              const txt = e.dataTransfer.getData('text/plain') || '';
              const m = /^field:([a-z0-9_]+)$/i.exec(String(txt));
              if (m) t = m[1].toLowerCase();
            }
            return t;
          };

          const fromSection = !!e.dataTransfer.getData('pe-internal-sec');
          const fromRootIdx = e.dataTransfer.getData('pe-from-root-index');

          let insertAt = Array.isArray(field.children) ? field.children.length : 0;
          if (secSlot) {
            let n = 0;
            Array.from(sgrid.children).forEach(node => {
              if (node === secSlot) return;
              if (node.classList && node.classList.contains('editor-group') && node.hasAttribute('data-index')) n++;
            });
            insertAt = n;
          }

          const paletteType = readPaletteType();
          if (paletteType) {
            if (paletteType === 'section') { clearSecSlot(); return; }
            if (paletteType === 'file') {
              const anyFile = fields.some(f => f.type === 'file' || (Array.isArray(f.children) && f.children.some(c => c.type === 'file')));
              if (anyFile) { showToast('è consentito un solo campo upload per scheda.', 'error'); clearSecSlot(); return; }
            }
            const nuovo = {
              uid: 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9),
              name: '',
              type: paletteType,
              options: ['select', 'checkbox', 'radio'].includes(paletteType) ? ['opzione 1'] : [],
              is_fixed: false,
              colspan: (secSlot && secSlot.classList.contains('full')) ? 2 : 1
            };
            field.children = Array.isArray(field.children) ? field.children : [];
            field.children.splice(insertAt, 0, nuovo);
            clearSecSlot(); render_preview();
            return;
          }

          // GESTIONE SPOSTAMENTO (Drag & Drop globale)
          const draggedUid = e.dataTransfer.getData('pe-dragged-uid') || e.dataTransfer.getData('pe-dragged-child-uid');

          if (draggedUid) {
            // 1. Trova il campo ovunque (Root o Sezioni)
            let movedField = fields.find(f => f.uid === draggedUid);
            let sourceSection = null;
            let isFromRoot = !!movedField;

            if (!movedField) {
              fields.forEach(f => {
                if (f.type === 'section' && Array.isArray(f.children)) {
                  const c = f.children.find(ch => ch.uid === draggedUid);
                  if (c) {
                    movedField = c;
                    sourceSection = f;
                  }
                }
              });
            }

            if (movedField) {
              if (movedField.is_fixed) { showToast('I campi fissi non possono essere spostati.', 'error'); clearSecSlot(); return; }
              if (movedField.type === 'section') { showToast('Non puoi annidare sezioni.', 'error'); clearSecSlot(); return; }

              // Calcola l'ordine dei children basandosi sul DOM
              const computeChildOrderFromDOM = (draggedUid) => {
                if (!secSlot || !sgrid) return null;

                const allChildren = Array.from(sgrid.children);
                const orderedUids = [];
                let draggedUidInserted = false;

                for (const node of allChildren) {
                  if (node === secSlot) {
                    orderedUids.push(draggedUid);
                    draggedUidInserted = true;
                    continue;
                  }

                  if (node.classList.contains('editor-group') &&
                    node.hasAttribute('data-uid')) {
                    const uid = node.dataset.uid;
                    if (uid && uid !== draggedUid) {
                      orderedUids.push(uid);
                    }
                  }
                }

                if (!draggedUidInserted) {
                  orderedUids.push(draggedUid);
                }

                return orderedUids;
              };

              const newOrder = computeChildOrderFromDOM(draggedUid);
              if (newOrder) {
                // Rimuovi dalla sorgente
                if (isFromRoot) {
                  fields = fields.filter(f => f.uid !== draggedUid);
                } else if (sourceSection) {
                  sourceSection.children = sourceSection.children.filter(c => c.uid !== draggedUid);
                }

                // Adatta colspan
                movedField.colspan = (secSlot && secSlot.classList.contains('full')) ? 2 : 1;

                // Ricostruisci children in ordine
                const currentChildren = [...(field.children || []), movedField];
                const reorderedChildren = [];

                newOrder.forEach(uid => {
                  const child = currentChildren.find(c => c.uid === uid);
                  if (child) {
                    reorderedChildren.push(child);
                  }
                });

                // Aggiungi eventuali children non trovati (sicurezza)
                currentChildren.forEach(c => {
                  if (!reorderedChildren.includes(c)) {
                    reorderedChildren.push(c);
                  }
                });

                field.children = reorderedChildren;
                clearSecSlot();
                render_preview();
                return;
              }
            }
          }

          clearSecSlot();
        });

        sgrid.addEventListener('dragleave', (e) => {
          const el = document.elementFromPoint(e.clientX, e.clientY);
          if (!sgrid.contains(el)) clearSecSlot();
        });

        const childs = Array.isArray(field.children) ? field.children : [];
        if (!childs.length) {
          const emp = document.createElement('div');
          emp.className = 'pe-slot-marker pe-slot-marker--empty';
          emp.style.pointerEvents = 'none';
          emp.style.minHeight = '36px';
          sgrid.appendChild(emp);
        } else {
          childs.forEach((c, ci) => {
            // Assicura che il child abbia un UID
            if (!c.uid) c.uid = 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

            const cg = document.createElement('div');
            cg.className = 'view-form-group editor-group editor-group--child';
            cg.draggable = true;
            cg.dataset.index = `${i}:${ci}`;
            cg.dataset.uid = c.uid;
            if (Number(c.colspan) === 2) cg.classList.add('full');

            // Header per child
            const childHeader = document.createElement('div');
            childHeader.className = 'editor-card-header';
            const h = document.createElement('span');
            h.className = 'drag-handle icon-handle';
            h.innerHTML = '⠿';
            childHeader.appendChild(h);

            // Badge tipo per child - cliccabile con menu diretto
            const childTypeBadgeContainer = document.createElement('div');
            childTypeBadgeContainer.style.position = 'relative';
            childTypeBadgeContainer.style.display = 'inline-block';

            const childTypeBadge = document.createElement('span');
            childTypeBadge.className = 'editor-card-type-badge';
            childTypeBadge.style.cursor = 'pointer';
            childTypeBadge.style.userSelect = 'none';
            const typeLabels = {
              'text': 'Testo',
              'textarea': 'Area testo',
              'select': 'Select',
              'checkbox': 'Checkbox',
              'radio': 'Radio',
              'file': 'File',
              'date': 'Data',
              'dbselect': 'DB Select'
            };
            childTypeBadge.textContent = typeLabels[c.type] || c.type;

            // Menu custom per selezione tipo
            const childTypeMenu = document.createElement('div');
            childTypeMenu.className = 'editor-type-menu';
            childTypeMenu.style.position = 'fixed';
            childTypeMenu.style.zIndex = '10000';
            childTypeMenu.style.display = 'none';
            childTypeMenu.style.background = '#ffffff';
            childTypeMenu.style.border = '1px solid #d1d5db';
            childTypeMenu.style.borderRadius = '4px';
            childTypeMenu.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
            childTypeMenu.style.minWidth = '140px';
            childTypeMenu.style.padding = '4px 0';

            const availableTypes = ['text', 'textarea', 'select', 'checkbox', 'radio', 'file', 'date', 'dbselect'];
            availableTypes.forEach(typeValue => {
              const menuItem = document.createElement('button');
              menuItem.type = 'button';
              menuItem.className = 'editor-type-menu-item';
              menuItem.textContent = typeLabels[typeValue] || typeValue;
              menuItem.style.display = 'block';
              menuItem.style.width = '100%';
              menuItem.style.padding = '6px 12px';
              menuItem.style.textAlign = 'left';
              menuItem.style.border = 'none';
              menuItem.style.background = 'transparent';
              menuItem.style.fontSize = '12px';
              menuItem.style.cursor = 'pointer';
              menuItem.style.color = typeValue === c.type ? '#cd211d' : '#374151';
              menuItem.style.fontWeight = typeValue === c.type ? '600' : '400';

              menuItem.onmouseenter = () => {
                if (typeValue !== c.type) {
                  menuItem.style.background = '#f9fafb';
                }
              };
              menuItem.onmouseleave = () => {
                if (typeValue !== c.type) {
                  menuItem.style.background = 'transparent';
                }
              };

              menuItem.onclick = (e) => {
                e.stopPropagation();
                const prevType = c.type;
                if (applyFieldTypeChange(c, typeValue, fields, prevType)) {
                  childTypeBadge.textContent = typeLabels[typeValue] || typeValue;
                  childTypeMenu.style.display = 'none';
                  // Aggiorna lo stile di tutti gli item
                  childTypeMenu.querySelectorAll('.editor-type-menu-item').forEach((item, itemIdx) => {
                    const itemType = availableTypes[itemIdx];
                    item.style.color = itemType === typeValue ? '#cd211d' : '#374151';
                    item.style.fontWeight = itemType === typeValue ? '600' : '400';
                  });
                  render_preview();
                }
              };

              childTypeMenu.appendChild(menuItem);
            });

            childTypeBadge.onclick = (e) => {
              e.stopPropagation();
              const isVisible = childTypeMenu.style.display === 'block';
              document.querySelectorAll('.editor-type-menu').forEach(menu => {
                if (menu !== childTypeMenu) {
                  menu.style.display = 'none';
                  if (menu.parentNode === document.body) {
                    document.body.removeChild(menu);
                  }
                }
              });

              if (!isVisible) {
                // Calcola posizione del badge rispetto al viewport
                const badgeRect = childTypeBadge.getBoundingClientRect();
                childTypeMenu.style.top = (badgeRect.bottom + 2) + 'px';
                childTypeMenu.style.left = badgeRect.left + 'px';
                childTypeMenu.style.display = 'block';
                // Appendi al body per evitare problemi di overflow
                if (childTypeMenu.parentNode !== document.body) {
                  document.body.appendChild(childTypeMenu);
                }
              } else {
                childTypeMenu.style.display = 'none';
                if (childTypeMenu.parentNode === document.body) {
                  document.body.removeChild(childTypeMenu);
                }
              }
            };

            // Chiudi menu quando si clicca fuori
            document.addEventListener('click', function closeChildMenu(e) {
              if (!childTypeBadgeContainer.contains(e.target) && !childTypeMenu.contains(e.target)) {
                childTypeMenu.style.display = 'none';
                if (childTypeMenu.parentNode === document.body) {
                  document.body.removeChild(childTypeMenu);
                }
              }
            });

            childTypeBadgeContainer.appendChild(childTypeBadge);
            childHeader.appendChild(childTypeBadgeContainer);

            const childActions = document.createElement('div');
            childActions.className = 'editor-card-header-actions';

            // 🔧 FIX ORDINE: Aggiungi PRIMA il size-ctrl, POI il delete
            // Size ctrl per child
            const childSizeCtrl = document.createElement('div');
            childSizeCtrl.className = 'size-ctrl';
            const cbHalf = document.createElement('button'); cbHalf.type = 'button'; cbHalf.textContent = '½';
            if (Number(c.colspan) !== 2) cbHalf.classList.add('is-active');
            const cbFull = document.createElement('button'); cbFull.type = 'button'; cbFull.textContent = '2×';
            if (Number(c.colspan) === 2) cbFull.classList.add('is-active');
            cbHalf.onclick = () => { c.colspan = 1; render_preview(); };
            cbFull.onclick = () => { c.colspan = 2; render_preview(); };
            childSizeCtrl.append(cbHalf, cbFull);
            childActions.appendChild(childSizeCtrl); // Aggiungi per primo

            // Delete button - aggiunto DOPO il size-ctrl
            const childDel = document.createElement('img');
            childDel.src = 'assets/icons/delete.png';
            childDel.alt = 'rimuovi';
            childDel.className = 'icon-delete';
            childDel.onclick = () => { field.children.splice(ci, 1); render_preview(); };
            childActions.appendChild(childDel); // Aggiungi per secondo

            childHeader.appendChild(childActions);
            cg.appendChild(childHeader);

            // Body per child
            const childBody = document.createElement('div');
            childBody.className = 'editor-card-body';
            const childConfig = document.createElement('div');
            childConfig.className = 'editor-config-section';

            // Etichetta visibile, name auto-generato in background
            const labelWrap = document.createElement('div');
            labelWrap.className = 'editor-namegrid';
            const labelLab = document.createElement('label'); labelLab.className = 'editor-label'; labelLab.textContent = 'Etichetta:';
            const labelInp = document.createElement('input');
            labelInp.type = 'text'; labelInp.value = c.label || (c.name ? beautifyLabel(c.name) : ''); labelInp.className = 'editor-fieldname-input editor-input';
            labelInp.placeholder = 'Es: Nome Completo';
            labelInp.addEventListener('input', (e) => {
              c.label = e.target.value;
              // Auto-genera sempre il name dalla label
              c.name = (e.target.value || '').replace(/[^a-z0-9_]/gi, '_').toLowerCase();
            });
            labelWrap.append(labelLab, labelInp);
            childConfig.appendChild(labelWrap);

            childBody.appendChild(childConfig);
            cg.appendChild(childBody);


            if (c.type === 'select') {
              // Vista compatta per select (children)
              const selectOptionsContainer = document.createElement('div');
              selectOptionsContainer.className = 'editor-select-options-container';
              renderSelectOptionsEditor(c, selectOptionsContainer);

              // Allow Custom Toggle (Select Child)
              const customWrap = document.createElement('label');
              customWrap.className = 'ds-custom-toggle';
              customWrap.style.cssText = 'display:flex; align-items:center; gap:6px; margin-top:8px; font-size:12px; color:#57606a;';

              const customChk = document.createElement('input');
              customChk.type = 'checkbox';
              customChk.checked = !!c.allow_custom;
              customChk.addEventListener('change', () => {
                c.allow_custom = !!customChk.checked;
                render_preview();
              });
              const customTxt = document.createElement('span');
              customTxt.textContent = 'Permetti valore personalizzato (Altro)';
              customWrap.append(customChk, customTxt);

              childBody.appendChild(selectOptionsContainer);
              childBody.appendChild(customWrap);
            } else if (['checkbox', 'radio'].includes(c.type)) {
              // Vista normale per checkbox/radio (children)
              const optList = document.createElement('div'); optList.className = 'option-list';
              (c.options || []).forEach((opt, oi) => {
                const row = document.createElement('div');
                row.className = 'option-row';
                row.setAttribute('data-type', c.type);

                // Icona per checkbox/radio
                const icon = document.createElement('div');
                icon.className = 'option-icon';
                row.appendChild(icon);

                const input = document.createElement('input');
                input.type = 'text';
                input.value = opt;
                input.className = 'option-input';
                input.oninput = (e) => {
                  if (!Array.isArray(c.options)) {
                    c.options = [];
                  }
                  c.options[oi] = e.target.value;
                };
                row.appendChild(input);

                const del = document.createElement('span');
                del.className = 'option-remove';
                del.innerHTML = '✖';
                del.onclick = () => {
                  if (!Array.isArray(c.options)) {
                    c.options = [];
                  }
                  c.options.splice(oi, 1);
                  render_preview();
                };
                row.appendChild(del);
                optList.appendChild(row);
              });
              const add = document.createElement('button');
              add.type = 'button';
              add.className = 'button small option-add';
              add.textContent = '+ aggiungi opzione';
              add.onclick = (e) => {
                e.preventDefault();
                if (!Array.isArray(c.options)) {
                  c.options = [];
                }
                c.options.push('');
                render_preview();
              };
              optList.appendChild(add);
              childBody.appendChild(optList);
            } else if (c.type === 'dbselect') {
              c.datasource = c.datasource || { table: '', valueCol: 'id' };
              const cfg = document.createElement('div'); cfg.className = 'ds-config';
              renderDbSelectConfig(c, cfg);
              childBody.appendChild(cfg);
            }

            sgrid.appendChild(cg);
          });
        }

        grid.appendChild(wrap);
        return;
      }

      // — campo dinamico top-level —
      // Assicura che il campo abbia un UID
      if (!field.uid) field.uid = 'field_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

      const group = document.createElement('div');
      group.className = 'view-form-group editor-group editor-group--top';
      if (field.type === 'section') {
        group.classList.add('editor-group--section');
        group.setAttribute('data-type', 'section');
      }
      group.draggable = true;
      group.dataset.index = i;
      group.dataset.uid = field.uid;
      group.classList.toggle('full', Number(field.colspan) === 2);

      // HEADER della card
      const header = document.createElement('div');
      header.className = 'editor-card-header';

      const drag = document.createElement('span');
      drag.className = 'drag-handle icon-handle';
      drag.innerHTML = '⠿';
      header.appendChild(drag);

      // Badge tipo campo - cliccabile con menu diretto
      const typeBadgeContainer = document.createElement('div');
      typeBadgeContainer.style.position = 'relative';
      typeBadgeContainer.style.display = 'inline-block';

      const typeBadge = document.createElement('span');
      typeBadge.className = 'editor-card-type-badge';
      typeBadge.style.cursor = 'pointer';
      typeBadge.style.userSelect = 'none';
      const typeLabels = {
        'text': 'Testo',
        'textarea': 'Area testo',
        'select': 'Select',
        'checkbox': 'Checkbox',
        'radio': 'Radio',
        'file': 'File',
        'date': 'Data',
        'dbselect': 'DB Select',
        'section': 'Sezione'
      };
      typeBadge.textContent = typeLabels[field.type] || field.type;

      // Menu custom per selezione tipo (si apre direttamente)
      const typeMenu = document.createElement('div');
      typeMenu.className = 'editor-type-menu';
      typeMenu.style.position = 'fixed';
      typeMenu.style.zIndex = '10000';
      typeMenu.style.display = 'none';
      typeMenu.style.background = '#ffffff';
      typeMenu.style.border = '1px solid #d1d5db';
      typeMenu.style.borderRadius = '4px';
      typeMenu.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
      typeMenu.style.minWidth = '140px';
      typeMenu.style.padding = '4px 0';

      const availableTypes = ['text', 'textarea', 'select', 'checkbox', 'radio', 'file', 'date', 'dbselect'];
      availableTypes.forEach(typeValue => {
        const menuItem = document.createElement('button');
        menuItem.type = 'button';
        menuItem.className = 'editor-type-menu-item';
        menuItem.textContent = typeLabels[typeValue] || typeValue;
        menuItem.style.display = 'block';
        menuItem.style.width = '100%';
        menuItem.style.padding = '6px 12px';
        menuItem.style.textAlign = 'left';
        menuItem.style.border = 'none';
        menuItem.style.background = 'transparent';
        menuItem.style.fontSize = '12px';
        menuItem.style.cursor = 'pointer';
        menuItem.style.color = typeValue === field.type ? '#cd211d' : '#374151';
        menuItem.style.fontWeight = typeValue === field.type ? '600' : '400';

        menuItem.onmouseenter = () => {
          if (typeValue !== field.type) {
            menuItem.style.background = '#f9fafb';
          }
        };
        menuItem.onmouseleave = () => {
          if (typeValue !== field.type) {
            menuItem.style.background = 'transparent';
          }
        };

        menuItem.onclick = (e) => {
          e.stopPropagation();
          const prevType = field.type;
          if (applyFieldTypeChange(field, typeValue, fields, prevType)) {
            typeBadge.textContent = typeLabels[typeValue] || typeValue;
            typeMenu.style.display = 'none';
            // Aggiorna lo stile di tutti gli item
            typeMenu.querySelectorAll('.editor-type-menu-item').forEach((item, itemIdx) => {
              const itemType = availableTypes[itemIdx];
              item.style.color = itemType === typeValue ? '#cd211d' : '#374151';
              item.style.fontWeight = itemType === typeValue ? '600' : '400';
            });
            render_preview();
          }
        };

        typeMenu.appendChild(menuItem);
      });

      typeBadge.onclick = (e) => {
        e.stopPropagation();
        const isVisible = typeMenu.style.display === 'block';
        // Chiudi altri menu aperti
        document.querySelectorAll('.editor-type-menu').forEach(menu => {
          if (menu !== typeMenu) {
            menu.style.display = 'none';
            if (menu.parentNode === document.body) {
              document.body.removeChild(menu);
            }
          }
        });

        if (!isVisible) {
          // Calcola posizione del badge rispetto al viewport
          const badgeRect = typeBadge.getBoundingClientRect();
          typeMenu.style.top = (badgeRect.bottom + 2) + 'px';
          typeMenu.style.left = badgeRect.left + 'px';
          typeMenu.style.display = 'block';
          // Appendi al body per evitare problemi di overflow
          if (typeMenu.parentNode !== document.body) {
            document.body.appendChild(typeMenu);
          }
        } else {
          typeMenu.style.display = 'none';
          if (typeMenu.parentNode === document.body) {
            document.body.removeChild(typeMenu);
          }
        }
      };

      // Chiudi menu quando si clicca fuori
      document.addEventListener('click', function closeMenu(e) {
        if (!typeBadgeContainer.contains(e.target) && !typeMenu.contains(e.target)) {
          typeMenu.style.display = 'none';
          if (typeMenu.parentNode === document.body) {
            document.body.removeChild(typeMenu);
          }
        }
      });

      typeBadgeContainer.appendChild(typeBadge);
      header.appendChild(typeBadgeContainer);

      // Container azioni (size-ctrl + delete)
      const headerActions = document.createElement('div');
      headerActions.className = 'editor-card-header-actions';

      // 🔧 FIX ORDINE: Aggiungi PRIMA il size-ctrl, POI il delete
      // Così nel flex container appaiono nell'ordine corretto: [size-ctrl] [delete]

      // Size selector per i campi top-level (solo se NON è una sezione)
      if (field.type !== 'section') {
        const sizeCtrl = document.createElement('div');
        sizeCtrl.className = 'size-ctrl';
        const bHalf = document.createElement('button'); bHalf.type = 'button'; bHalf.textContent = '½';
        if (Number(field.colspan) !== 2) bHalf.classList.add('is-active');
        const bFull = document.createElement('button'); bFull.type = 'button'; bFull.textContent = '2×';
        if (Number(field.colspan) === 2) bFull.classList.add('is-active');
        bHalf.onclick = () => { field.colspan = 1; render_preview(); };
        bFull.onclick = () => { field.colspan = 2; render_preview(); };
        sizeCtrl.append(bHalf, bFull);
        headerActions.appendChild(sizeCtrl); // Aggiungi per primo
      }

      // Delete button - aggiunto DOPO il size-ctrl
      const remove = document.createElement('img');
      remove.src = 'assets/icons/delete.png';
      remove.alt = 'rimuovi';
      remove.className = 'icon-delete';
      remove.onclick = () => { fields.splice(i, 1); render_preview(); };
      headerActions.appendChild(remove); // Aggiungi per secondo

      header.appendChild(headerActions);
      group.appendChild(header);

      // BODY della card
      const body = document.createElement('div');
      body.className = 'editor-card-body';

      // Sezione configurazione
      const configSection = document.createElement('div');
      configSection.className = 'editor-config-section';

      if (field.type === 'section') {
        // Per le sezioni, solo il titolo (label)
        const labelWrap = document.createElement('div'); labelWrap.className = 'editor-namegrid';
        const labelLab = document.createElement('label'); labelLab.className = 'editor-label';
        labelLab.textContent = 'Titolo sezione:';
        const labelInp = document.createElement('input');
        labelInp.type = 'text'; labelInp.value = field.label || (field.name ? beautifyLabel(field.name) : ''); labelInp.className = 'editor-fieldname-input editor-input';
        labelInp.addEventListener('input', (e) => {
          field.label = e.target.value;
          // Per le sezioni, name e label coincidono (normalizzato)
          field.name = (e.target.value || '').replace(/[^a-z0-9_]/gi, '_').toLowerCase();
        });
        labelWrap.append(labelLab, labelInp);
        configSection.appendChild(labelWrap);
      } else {
        // Per i campi normali: solo Etichetta visibile, name auto-generato in background
        const labelWrap = document.createElement('div'); labelWrap.className = 'editor-namegrid';
        const labelLab = document.createElement('label'); labelLab.className = 'editor-label';
        labelLab.textContent = 'Etichetta:';
        const labelInp = document.createElement('input');
        labelInp.type = 'text'; labelInp.value = field.label || (field.name ? beautifyLabel(field.name) : ''); labelInp.className = 'editor-fieldname-input editor-input';
        labelInp.placeholder = 'Es: Nome Completo';
        labelInp.addEventListener('input', (e) => {
          field.label = e.target.value; // Preserva esattamente come scritto
          // Auto-genera sempre il name dalla label
          field.name = (e.target.value || '').replace(/[^a-z0-9_]/gi, '_').toLowerCase();
        });
        labelWrap.append(labelLab, labelInp);
        configSection.appendChild(labelWrap);
      }
      body.appendChild(configSection);

      // Preview del controllo per tipi che non hanno già un editor specifico
      if (!['section', 'dbselect', 'select', 'checkbox', 'radio'].includes(field.type)) {
        const controlPreview = document.createElement('div');
        controlPreview.className = 'editor-control-preview';
        controlPreview.style.cssText = 'margin-top: 8px; padding: 8px; background: #f8f9fa; border-radius: 4px; pointer-events: none; opacity: 0.7;';

        let previewEl = null;
        if (field.type === 'date') {
          previewEl = document.createElement('input');
          previewEl.type = 'date';
          previewEl.disabled = true;
          previewEl.style.width = '100%';
        } else if (field.type === 'textarea') {
          previewEl = document.createElement('textarea');
          previewEl.disabled = true;
          previewEl.rows = 2;
          previewEl.style.width = '100%';
          previewEl.placeholder = 'Area di testo...';
        } else if (field.type === 'file') {
          previewEl = document.createElement('input');
          previewEl.type = 'file';
          previewEl.disabled = true;
          previewEl.style.width = '100%';
        }

        if (previewEl) {
          controlPreview.appendChild(previewEl);
          body.appendChild(controlPreview);
        }
      }

      // Sezione opzioni (senza anteprima)
      if (field.type === 'select') {
        // Vista compatta per select
        const selectOptionsContainer = document.createElement('div');
        selectOptionsContainer.className = 'editor-select-options-container';
        renderSelectOptionsEditor(field, selectOptionsContainer);

        // Allow Custom Toggle (Select Top)
        const customWrap = document.createElement('label');
        customWrap.className = 'ds-custom-toggle';
        customWrap.style.cssText = 'display:flex; align-items:center; gap:6px; margin-top:8px; font-size:12px; color:#57606a;';

        const customChk = document.createElement('input');
        customChk.type = 'checkbox';
        customChk.checked = !!field.allow_custom;
        customChk.addEventListener('change', () => {
          field.allow_custom = !!customChk.checked;
          render_preview();
        });
        const customTxt = document.createElement('span');
        customTxt.textContent = 'Permetti valore personalizzato (Altro)';
        customWrap.append(customChk, customTxt);

        body.appendChild(selectOptionsContainer);
        body.appendChild(customWrap);
      } else if (['checkbox', 'radio'].includes(field.type)) {
        // Vista normale per checkbox/radio
        const optList = document.createElement('div'); optList.className = 'option-list';

        // Assicurati che field.options sia sempre un array
        let options = field.options || [];
        if (typeof options === 'string') {
          try {
            options = JSON.parse(options);
          } catch (e) {
            options = [];
          }
        }
        if (!Array.isArray(options)) {
          options = [];
        }

        options.forEach((opt, idx) => {
          const row = document.createElement('div');
          row.className = 'option-row';
          row.setAttribute('data-type', field.type);

          // Icona per checkbox/radio
          const icon = document.createElement('div');
          icon.className = 'option-icon';
          row.appendChild(icon);

          const input = document.createElement('input');
          input.type = 'text';
          input.value = opt;
          input.className = 'option-input';
          input.oninput = (e) => {
            if (!Array.isArray(field.options)) {
              field.options = [];
            }
            field.options[idx] = e.target.value;
          };
          row.appendChild(input);

          const del = document.createElement('span');
          del.className = 'option-remove';
          del.innerHTML = '✖';
          del.onclick = () => {
            if (!Array.isArray(field.options)) {
              field.options = [];
            }
            field.options.splice(idx, 1);
            render_preview();
          };
          row.appendChild(del);
          optList.appendChild(row);
        });

        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'button small option-add';
        add.textContent = '+ aggiungi opzione';
        add.onclick = (e) => {
          e.preventDefault();
          if (!Array.isArray(field.options)) {
            field.options = [];
          }
          field.options.push('');
          render_preview();
        };
        optList.appendChild(add);
        body.appendChild(optList);
      }

      if (field.type === 'dbselect') {
        field.datasource = field.datasource || { table: '', valueCol: 'id' };
        const cfg = document.createElement('div'); cfg.className = 'ds-config';
        renderDbSelectConfig(field, cfg);
        body.appendChild(cfg);
      }
      // Chiudi body e aggiungi al group
      group.appendChild(body);
      grid.appendChild(group);
    });

    // === RENDER BLOCCO CHIUSURA FISSO (in coda ai campi custom) ===
    if (tabState[activeTabKey] && tabState[activeTabKey].isClosureTab) {
      const closureBlock = document.createElement('div');
      closureBlock.className = 'form-meta-block closure-fixed-block';
      closureBlock.style.gridColumn = '1 / -1';
      closureBlock.style.marginTop = '0';
      // closureBlock.style.backgroundColor = '#fffcf0';
      closureBlock.style.border = '1px solid #d5d5d5';
      closureBlock.style.borderLeft = '4px solid #e74c3c';
      closureBlock.style.borderRadius = '4px';

      closureBlock.innerHTML = `
          <div class="form-meta-header">
              Campi Fissi di Chiusura
          </div>
          <div class="meta-row">
              <span class="label">Esito <span style="color:#e74c3c">*</span>:</span>
              <span class="meta-value">
                  <select disabled style="background:#fff; cursor:not-allowed; width: 100%; border: 1px solid #ced4da; padding: 4px; border-radius: 4px;">
                      <option>-- Seleziona Esito --</option>
                      <option>Accettata</option>
                      <option>In Valutazione</option>
                      <option>Rifiutata</option>
                  </select>
              </span>
          </div>
          <div class="meta-row">
              <span class="label">Note Esito:</span>
              <span class="meta-value">
                  <textarea disabled placeholder="Note opzionali sull'esito..." style="background:#fff; cursor:not-allowed; width: 100%; border: 1px solid #ced4da; padding: 4px; border-radius: 4px;" rows="2"></textarea>
              </span>
          </div>
          <div class="meta-row meta-row-split">
              <span class="label-split">Data Chiusura:</span>
              <span class="value-split">
                  <input type="date" disabled style="background:#fff; cursor:not-allowed; border: 1px solid #ced4da; padding: 4px; border-radius: 4px;">
              </span>
              <span class="label-split">Assegnato a:</span>
              <span class="value-split">
                  <select disabled style="background:#fff; cursor:not-allowed; border: 1px solid #ced4da; padding: 4px; border-radius: 4px; min-width: 150px;">
                      <option>-- Utente --</option>
                  </select>
              </span>
          </div>
      `;
      grid.appendChild(closureBlock);
    }

    // Aggiungi il bottone personalizzabile alla fine (per la scheda corrente)
    addSubmitButtonPreview(activeTabKey);
  }

  function addSubmitButtonPreview(tabKey) {
    const preview = document.getElementById('form-fields-preview');
    if (!preview) return;

    // Non mostrare per la scheda proprietà
    if (tabKey === PROPERTIES_TAB_KEY) return;

    // Rimuovi bottone esistente se presente
    const existing = preview.querySelector('#pe-submit-btn-preview');
    if (existing) existing.remove();

    // Assicura che tabState[tabKey] esista
    if (!tabState[tabKey]) {
      tabState[tabKey] = { fields: [], hasFixed: false, submitLabel: null, submitAction: 'submit' };
    }

    // Crea contenitore per il bottone
    const btnContainer = document.createElement('div');
    btnContainer.id = 'pe-submit-btn-preview';
    btnContainer.style.cssText = 'margin-top: 0; padding: 16px; background: #f8f9fa; border-radius: 8px; border: 2px dashed #dee2e6;';

    // Label
    const label = document.createElement('label');
    label.style.cssText = 'display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 13px;';
    label.textContent = 'Testo bottone invio';

    // Input per personalizzare
    const input = document.createElement('input');
    input.type = 'text';
    input.id = 'pe-submit-btn-text';
    input.setAttribute('data-tab-key', tabKey);
    input.placeholder = 'Lascia vuoto per usare il fallback';
    input.maxLength = 50;

    // Bottone di anteprima
    const previewBtn = document.createElement('button');
    previewBtn.type = 'button';
    previewBtn.className = 'button';
    previewBtn.id = 'pe-submit-btn-demo';
    previewBtn.style.cssText = 'margin-top: 12px; pointer-events: none; opacity: 0.8;';

    // Select per azione bottone
    const actionLabel = document.createElement('label');
    actionLabel.style.cssText = 'display: block; margin-top: 16px; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 13px;';
    actionLabel.textContent = 'Azione bottone';

    const actionSelect = document.createElement('select');
    actionSelect.id = 'pe-submit-action-select';
    actionSelect.setAttribute('data-tab-key', tabKey);
    actionSelect.style.cssText = 'width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;';

    const optionSubmit = document.createElement('option');
    optionSubmit.value = 'submit';
    optionSubmit.textContent = 'Invia modulo';

    const optionNext = document.createElement('option');
    optionNext.value = 'next_step';
    optionNext.textContent = 'Vai alla scheda successiva (no submit)';

    actionSelect.appendChild(optionSubmit);
    actionSelect.appendChild(optionNext);

    // Funzione di fallback per il testo del bottone
    const getButtonLabelForTab = (key) => {
      const state = tabState[key];
      if (state && state.submitLabel && state.submitLabel.trim() !== '') {
        return state.submitLabel.trim();
      }
      // Cerca la prima submitLabel non vuota tra tutte le schede (esclusa proprietà)
      const allTabs = tabs.filter(t => t.key !== PROPERTIES_TAB_KEY);
      for (const tab of allTabs) {
        const s = tabState[tab.key];
        if (s && s.submitLabel && s.submitLabel.trim() !== '') {
          return s.submitLabel.trim();
        }
      }
      return 'Salva';
    };

    // Carica valori dalla config della scheda
    const loadButtonConfig = () => {
      const state = tabState[tabKey] || {};
      const submitLabel = state.submitLabel || null;
      const submitAction = state.submitAction || 'submit';

      // Popola input (vuoto se non impostato)
      input.value = submitLabel || '';

      // Popola select
      actionSelect.value = submitAction;

      // Aggiorna anteprima con fallback
      const previewLabel = getButtonLabelForTab(tabKey);
      previewBtn.textContent = previewLabel;
    };

    loadButtonConfig();

    // Aggiorna config e anteprima mentre scrivi
    input.addEventListener('input', () => {
      const val = (input.value || '').trim();
      // Salva in tabState (null se vuoto per distinguere "non impostato" da "vuoto")
      if (!tabState[tabKey]) {
        tabState[tabKey] = { fields: [], hasFixed: false, submitLabel: null, submitAction: 'submit' };
      }
      tabState[tabKey].submitLabel = val || null;

      // Aggiorna anteprima con fallback
      const previewLabel = getButtonLabelForTab(tabKey);
      previewBtn.textContent = previewLabel;
    });

    // Aggiorna config quando cambia l'azione
    actionSelect.addEventListener('change', () => {
      if (!tabState[tabKey]) {
        tabState[tabKey] = { fields: [], hasFixed: false, submitLabel: null, submitAction: 'submit' };
      }
      tabState[tabKey].submitAction = actionSelect.value;
    });

    // Helper text
    const helpText = document.createElement('p');
    helpText.style.cssText = 'margin: 8px 0 0; font-size: 12px; color: #6c757d;';
    helpText.textContent = 'Personalizza il testo che apparirà sul bottone di invio per questa scheda. Se lasciato vuoto, verrà usato il fallback.';

    btnContainer.append(label, input, helpText, actionLabel, actionSelect, previewBtn);
    preview.appendChild(btnContainer);

    return input;
  }

  function ensureTitoloHeader() {
    const stage = document.getElementById('editor-stage');
    const preview = document.getElementById('form-fields-preview');
    if (!stage || !preview) return;

    // già presente?
    if (stage.querySelector('#pe-titolo-header')) return;

    // crea riga stile "responsabile:", ma per "titolo:"
    const row = document.createElement('p');
    row.id = 'pe-titolo-header';
    row.className = 'responsabile-info';
    row.style.marginTop = '0';

    const lab = document.createElement('span');
    lab.className = 'label';
    lab.textContent = 'titolo:';

    const inp = document.createElement('input');
    inp.type = 'text';
    inp.disabled = true;
    // stesso look dei readonly nell’editor
    inp.className = 'editor-input editor-input--readonly';
    inp.placeholder = 'titolo';
    inp.style.marginLeft = '6px';
    inp.style.minWidth = '220px';

    row.appendChild(lab);
    row.appendChild(inp);

    const respInfo = stage.querySelector('p.responsabile-info');
    // vogliamo il "titolo" SOPRA il blocco responsabile
    if (respInfo && respInfo.parentNode) {
      respInfo.parentNode.insertBefore(row, respInfo);
    } else if (preview && preview.parentNode) {
      preview.parentNode.insertBefore(row, preview); // altrimenti sopra all’editor grid
    }
  }

  // — DnD —
  let isInternalDrag = false;

  // registra i listener root solo se il preview esiste
  if (preview) {
    // root: drag start - USA UID STABILE PER PRECISIONE
    preview.addEventListener('dragstart', (e) => {
      const t = e.target.closest('.editor-group[data-uid]');
      if (!t) return;
      const uid = t.dataset.uid;
      if (!uid) return;

      // Verifica che sia un campo top-level (non nested in sezione)
      const idxStr = String(t.dataset.index || '');
      if (idxStr.includes(':')) return;

      isInternalDrag = true;
      e.dataTransfer.setData('pe-internal', '1');
      e.dataTransfer.setData('pe-dragged-uid', uid);
      e.dataTransfer.effectAllowed = 'move';

      dndSnap = buildDynRectsSnapshot();
      setTimeout(() => t.classList.add('dragging'), 1);
    });

    preview.addEventListener('dragend', (e) => {
      e.target.closest('.editor-group[data-uid]')?.classList.remove('dragging');
      isInternalDrag = false;
      clearDynSnap();
    });

    // root: posiziona segnaposto
    preview.addEventListener('dragover', (e) => {
      if (!isInternalDrag) return;
      // SBLOCCATO: permette drop anche da sezioni verso root
      if (e.target.closest('.editor-section .section-grid')) return; // evita collisioni
      e.preventDefault();
      placeDropSlotAtPointer(e.clientX, e.clientY, e.altKey);
    });

    // root: completa riordino - PRECISIONE CHIRURGICA CON UID
    preview.addEventListener('drop', (e) => {
      if (!isInternalDrag) return;
      // SBLOCCATO: permette drop da sezioni
      if (e.target.closest('.editor-section .section-grid')) return;

      e.preventDefault();

      // Ottieni l'UID del campo trascinato (supporta root o child)
      const draggedUid = e.dataTransfer.getData('pe-dragged-uid') || e.dataTransfer.getData('pe-dragged-child-uid');
      if (!draggedUid) {
        console.warn('[DnD] No UID found');
        clearDropSlot();
        return;
      }

      // Calcola l'ordine dei campi basandosi sul DOM (UIDs prima del dropSlot)
      const newOrder = computeFieldOrderFromDOM(draggedUid);

      clearDropSlot();

      if (!newOrder) {
        console.warn('[DnD] Could not compute new order');
        return;
      }

      console.log('[DnD] New UID order:', newOrder);

      // Cerca il campo trascinato (potrebbe venire da una sezione!)
      let movedField = fields.find(f => f.uid === draggedUid);
      let sourceSection = null;

      if (!movedField) {
        // Cerca nelle sezioni
        fields.forEach(f => {
          if (f.type === 'section' && Array.isArray(f.children)) {
            const child = f.children.find(c => c.uid === draggedUid);
            if (child) {
              movedField = child;
              sourceSection = f;
            }
          }
        });
      }

      if (!movedField) return; // Non dovrebbe accadere

      // Se viene da una sezione, rimuovilo da lì
      if (sourceSection) {
        sourceSection.children = sourceSection.children.filter(c => c.uid !== draggedUid);
      }

      // Separa campi fissi e dinamici
      const dynamicFields = [];
      const fixedFields = [];

      fields.forEach(f => {
        if (f.is_fixed) {
          fixedFields.push(f);
        } else {
          dynamicFields.push(f);
        }
      });

      // Se il campo veniva da una sezione, aggiungilo al pool dei dinamici per il riordino
      if (sourceSection && !dynamicFields.includes(movedField)) {
        dynamicFields.push(movedField);
      }

      // Riordina i campi dinamici secondo il nuovo ordine di UIDs
      const reorderedDynamicFields = [];
      newOrder.forEach(uid => {
        const field = dynamicFields.find(f => f.uid === uid);
        if (field) {
          reorderedDynamicFields.push(field);
        }
      });

      // Aggiungi eventuali campi non trovati (sicurezza)
      dynamicFields.forEach(f => {
        if (!reorderedDynamicFields.includes(f)) {
          reorderedDynamicFields.push(f);
        }
      });

      // Ricostruisci l'array fields
      fields = [...fixedFields, ...reorderedDynamicFields];

      console.log('[DnD] Final order:', reorderedDynamicFields.map((f, i) => `[${i}] ${f.name || f.type}`));

      render_preview();
    });

    // se esci dal preview con il puntatore trascinando, pulisci il marker
    preview.addEventListener('dragleave', (e) => {
      if (!isInternalDrag) return;
      const el = document.elementFromPoint(e.clientX, e.clientY);
      if (!preview.contains(el)) clearDropSlot();
    });
  }

  // —— Snapshot DnD (root) ——
  let dndSnap = null; // { rects:[{key,rect}], gridRect:DOMRect }

  function buildDynRectsSnapshot() {
    const grid = getGrid();
    if (!grid) return null;
    const rects = dynGroupsInDom().map(el => ({ key: String(el.dataset.uid || ''), rect: el.getBoundingClientRect() }));
    return { rects, gridRect: grid.getBoundingClientRect() };
  }
  function ensureDynSnap() {
    if (!dndSnap) {
      dndSnap = buildDynRectsSnapshot();
      // Se ancora null, crea un snapshot vuoto per evitare errori
      if (!dndSnap) {
        dndSnap = { rects: [], gridRect: null };
      }
    }
  }
  function clearDynSnap() { dndSnap = null; }

  // segnaposto
  let dropSlotEl = null;
  let last_slot_ctx = { parent: null, before_key: null };
  let last_hover = { target_key: null, side: null };

  function getGrid() { return preview ? preview.querySelector('.pe-grid') : null; }
  function ensureDropSlot() {
    if (!dropSlotEl) {
      dropSlotEl = document.createElement('div');
      dropSlotEl.className = 'pe-slot-marker';
    }
    return dropSlotEl;
  }
  function clearDropSlot() {
    if (dropSlotEl?.parentNode) dropSlotEl.parentNode.removeChild(dropSlotEl);
    dropSlotEl = null;
    last_slot_ctx = { parent: null, before_key: null };
    last_hover = { target_key: null, side: null };
  }
  function clearAllSectionSlots() {
    document.querySelectorAll('.editor-section .pe-slot-marker').forEach(n => n.parentNode && n.parentNode.removeChild(n));
  }

  // soli gruppi dinamici top-level (con UID)
  function dynGroupsInDom() {
    const grid = getGrid();
    return grid
      ? Array.from(grid.querySelectorAll('.editor-group[data-uid]')).filter(el => !String(el.dataset.index || '').includes(':'))
      : [];
  }

  // posiziona marker in base al puntatore usando la snapshot
  let placeRootRaf = null;
  function placeDropSlotAtPointer(clientX, clientY, isFull) {
    ensureDynSnap();
    if (!dndSnap) return;

    if (placeRootRaf) cancelAnimationFrame(placeRootRaf);
    placeRootRaf = requestAnimationFrame(() => {
      const grid = getGrid();
      if (!grid) return;
      const slot = ensureDropSlot();
      slot.classList.toggle('full', !!isFull);

      const nextDynamicSibling = (beforeKey) => {
        const els = dynGroupsInDom();
        const idx = els.findIndex(el => String(el.dataset.uid || '') === beforeKey);
        return idx === -1 ? null : (els[idx + 1] || null);
      };

      const placeBeforeRef = (beforeKey) => {
        const parent = grid;
        const before_key = beforeKey || '__end__';
        if (last_slot_ctx.parent === parent && last_slot_ctx.before_key === before_key) return;
        last_slot_ctx = { parent, before_key };

        if (beforeKey && beforeKey !== '__end__') {
          const beforeNode = dynGroupsInDom().find(el => String(el.dataset.uid || '') === beforeKey);
          if (beforeNode) {
            parent.insertBefore(slot, beforeNode);
          } else {
            // Fallback: cerca closure block prima di appendere
            const closureBlock = parent.querySelector('.closure-fixed-block');
            if (closureBlock) parent.insertBefore(slot, closureBlock);
            else parent.appendChild(slot);
          }
        } else {
          // FIX: Se c'è il blocco di chiusura, il segnaposto deve andare PRIMA di esso
          const closureBlock = parent.querySelector('.closure-fixed-block');
          if (closureBlock) {
            parent.insertBefore(slot, closureBlock);
          } else {
            parent.appendChild(slot);
          }
        }
      };

      if (!dndSnap) { placeBeforeRef('__end__'); return; }
      const rects = dndSnap.rects || [];
      if (!Array.isArray(rects) || !rects.length) { placeBeforeRef('__end__'); return; }

      const rf = rects[0].rect;
      const rl = rects[rects.length - 1].rect;

      if (clientY < rf.top) { last_hover = { target_key: rects[0].key, side: 'before' }; placeBeforeRef(rects[0].key); return; }
      if (clientY > rl.bottom) { last_hover = { target_key: rects[rects.length - 1].key, side: 'after' }; placeBeforeRef('__end__'); return; }

      // nearest verticale
      let nearest = null, best = Infinity;
      for (const item of rects) {
        const r = item.rect;
        const dy = (clientY < r.top) ? (r.top - clientY) : (clientY > r.bottom ? (clientY - r.bottom) : 0);
        if (dy < best) { best = dy; nearest = item; }
      }
      if (!nearest) { placeBeforeRef('__end__'); return; }

      // isteresi orizzontale
      const r = nearest.rect;
      const mid = r.left + r.width / 2;
      const h = Math.min(24, r.width * 0.15);
      const key = nearest.key;
      let side = 'before';

      if (last_hover.target_key === key && last_hover.side) {
        side = (last_hover.side === 'before')
          ? (clientX < mid + h ? 'before' : 'after')
          : (clientX > mid - h ? 'after' : 'before');
      } else {
        side = (clientX <= mid) ? 'before' : 'after';
      }
      last_hover = { target_key: key, side };

      if (side === 'before') {
        placeBeforeRef(key);
      } else {
        const afterNode = nextDynamicSibling(key);
        // FIX: Usa UID anche qui
        afterNode ? placeBeforeRef(String(afterNode.dataset.uid || '__end__')) : placeBeforeRef('__end__');
      }
    });
  }

  /**
   * SOLUZIONE PROFESSIONALE: Calcola l'ordine finale dei campi basandosi sugli UIDs nel DOM.
   * Legge l'ordine ESATTO dal DOM e restituisce un array di UIDs.
   * Precisione chirurgica garantita.
   */
  function computeFieldOrderFromDOM(draggedUid) {
    const grid = getGrid();
    if (!dropSlotEl || !grid) return null;

    const allChildren = Array.from(grid.children);
    const orderedUids = [];
    let draggedUidInserted = false;

    for (const node of allChildren) {
      // Quando troviamo il dropSlot, inserisci l'UID del campo trascinato
      if (node === dropSlotEl) {
        orderedUids.push(draggedUid);
        draggedUidInserted = true;
        continue;
      }

      // Aggiungi gli UIDs dei campi dinamici (escludi quello trascinato)
      if (node.classList.contains('editor-group') &&
        node.hasAttribute('data-uid') &&
        !String(node.dataset.index || '').includes(':')) {
        const uid = node.dataset.uid;
        if (uid && uid !== draggedUid) {
          orderedUids.push(uid);
        }
      }
    }

    // Se il dropSlot non è stato trovato, aggiungi il campo trascinato alla fine
    if (!draggedUidInserted) {
      orderedUids.push(draggedUid);
    }

    return orderedUids;
  }

  async function handleEditorDrop(e) {
    e.preventDefault();
    e.stopPropagation?.();

    const wasFull = !!dropSlotEl?.classList?.contains('full');
    pageFoglio?.classList.remove('pe-dropping');

    const insertIndex = computeInsertIndexFromSlot();
    clearDropSlot();

    // 1) modulo
    const modk = e.dataTransfer.getData('module-key');
    if (modk) {
      const fname = (formNameHidden?.value || '').trim();
      if (!fname) {
        if (!stagedModules.includes(modk)) stagedModules.push(modk);
        showToast('modulo aggiunto in anteprima; verrà attivato al salvataggio.', 'info');
        await render_modules();
        await renderModulesPreview();
        return;
      }
      const r = await pe_api('attachModule', { form_name: fname, module_key: modk });
      if (r?.success) {
        showToast('Modulo aggiunto', 'success');
        await render_modules(); await load_fields_or_empty(); await renderModulesPreview();
      } else {
        showToast(r?.message || 'Errore aggiunta modulo', 'error');
      }
      return;
    }

    // 2) nuovo campo dalla palette
    const type = e.dataTransfer.getData('field-type');
    if (type) {
      if (type === 'file' && (
        fields.some(f => f.type === 'file') ||
        fields.some(f => Array.isArray(f.children) && f.children.some(c => c.type === 'file'))
      )) {
        showToast('è consentito un solo campo upload per scheda (anche dentro le sezioni).', 'error');
        return;
      }

      if (type === 'section') {
        fields.splice(insertIndex, 0, { name: '', type: 'section', label: 'sezione', is_fixed: false, colspan: 2, children: [] });
        render_preview();
        // Non salvare automaticamente: verrà salvato tutto con save_structure()
        // try { persistTabs(); } catch (_) { }
        return;
      }

      fields.splice(insertIndex, 0, {
        name: '',
        type,
        options: ['select', 'checkbox', 'radio'].includes(type) ? ['opzione 1'] : [],
        is_fixed: false,
        colspan: wasFull ? 2 : 1
      });
      render_preview();

      // focus sul nuovo campo
      setTimeout(() => {
        const inputs = preview.querySelectorAll('.editor-fieldname-input');
        const focusIdx = insertIndex - fields.filter(f => f.is_fixed).length;
        if (inputs.length && focusIdx >= 0 && inputs[focusIdx]) inputs[focusIdx].focus();
      }, 0);

      // Non salvare automaticamente: verrà salvato tutto con save_structure()
      // try { persistTabs(); } catch (_) { }
      return;
    }

    // 3) figlio sezione → root
    const fromSection = e.dataTransfer.getData('pe-from-section'); // "secIndex:childIndex"
    if (fromSection) {
      const [secIdx, childIdx] = fromSection.split(':').map(n => parseInt(n, 10));
      const sec = fields[secIdx];
      if (!Number.isNaN(secIdx) && !Number.isNaN(childIdx) && sec?.type === 'section' && Array.isArray(sec.children) && sec.children[childIdx]) {
        const moved = sec.children.splice(childIdx, 1)[0];
        moved.colspan = wasFull ? 2 : 1;

        if (moved.type === 'file') {
          const existsAnotherFile =
            fields.some(f => f.type === 'file') ||
            fields.some(f => f.type === 'section' && Array.isArray(f.children) && f.children.some(c => c.type === 'file'));
          if (existsAnotherFile) {
            sec.children.splice(childIdx, 0, moved);
            showToast('è consentito un solo campo upload per scheda (anche dentro le sezioni).', 'error');
            render_preview();
            return;
          }
        }

        fields.splice(insertIndex, 0, moved);
        render_preview();
      }
    }
  }

  // canvas/foglio
  if (pageFoglio) {
    pageFoglio.addEventListener('dragenter', (e) => {
      if (isInternalDrag) return;
      if (!isAllowedExternalDrag(e, 'structure')) return;
      pageFoglio.classList.add('pe-dropping');
    });

    pageFoglio.addEventListener('dragover', (e) => {
      const fromSection = !!e.dataTransfer?.getData?.('pe-from-section');
      if (isInternalDrag && !fromSection) return;
      if (!isInternalDrag && !isAllowedExternalDrag(e, 'structure')) return;

      // SE EMPTY STATE: Ignora dragover globale, lascia gestire all'emptyState
      // Questo previene la comparsa fantasma dello slot sotto il footer
      if (!fields || fields.length === 0) return;

      // non sporcare le section-grid (a meno che stia uscendo da lì)
      if ((e.target.closest('.editor-section .section-grid') || e.target.closest('.editor-section')) && !fromSection) {
        e.preventDefault();
        clearDropSlot();
        return;
      }

      e.preventDefault();
      placeDropSlotAtPointer(e.clientX, e.clientY, e.altKey);
    });

    pageFoglio.addEventListener('dragleave', (e) => {
      if (isInternalDrag) return;
      const el = document.elementFromPoint(e.clientX, e.clientY);
      if (!pageFoglio.contains(el)) {
        pageFoglio.classList.remove('pe-dropping');
        clearDropSlot();
        clearAllSectionSlots();
      }
    });

    pageFoglio.addEventListener('drop', (e) => {
      // 1. Dati drop
      const fromSection = !!e.dataTransfer?.getData?.('pe-internal-sec');
      const fromRootIdx = e.dataTransfer?.getData?.('pe-from-root-index');

      // 2. Se è drag interno e non da sezione intera, ok.
      // 3. Se Drop esterno, controlla 'structure'.
      if (isInternalDrag && !fromSection && !fromRootIdx) return;
      if (!isInternalDrag && !isAllowedExternalDrag(e, 'structure')) return;

      // 4. Se il target è DENTRO una sezione, lascia gestire alla sezione (a meno che non stiamo trascinando INTERA sezione)
      //    Se stiamo trascinando una sezione intera, essa non può essere droppata DENTRO un'altra sezione.
      const targetIsSection = (e.target.closest('.editor-section .section-grid') || e.target.closest('.editor-section'));
      if (targetIsSection && !fromSection && !(e.dataTransfer.getData('field-type') === 'section')) {
        // Lascia gestire alla grid interna della sezione
        return;
      }

      // 5. Determina indice inserimento nella root grid
      //    Calcola posizione rispetto ai figli diretti della grid (escludendo eventuali marker di drop)
      let insertAt = fields.length;

      // Trova la grid reale
      const grid = preview.querySelector('.pe-grid');
      if (grid) {
        // Trova il child sopra il quale siamo stati droppati
        const children = Array.from(grid.children).filter(el => el.classList.contains('editor-group'));
        let bestIndex = fields.length;

        let minY = Infinity;

        // Logica semplice: trova il primo elemento il cui centro verticale è > del puntatore Y
        for (let i = 0; i < children.length; i++) {
          const rect = children[i].getBoundingClientRect();
          const centerY = rect.top + rect.height / 2;
          if (e.clientY < centerY) {
            bestIndex = parseInt(children[i].dataset.index || i, 10);
            break;
          }
        }
        // Se bestIndex è valido e dentro range
        if (bestIndex !== undefined && !isNaN(bestIndex)) {
          insertAt = bestIndex;
        }
      }

      // 6. Esegui logica inserimento (handleEditorDrop o logica inline)
      handleEditorDrop(e, insertAt);
    });
  }

  // Nuova funzione dedicata per gestire l'inserimento effettivo i fields
  function handleEditorDrop(e, insertAt) {
    const paletteType = e.dataTransfer.getData('field-type');

    // CASO A: Nuovo campo da Palette
    if (paletteType) {
      if (paletteType === 'section') {
        // Logica specifica per sezione nuova ?? 
        // O standard:
      }
      if (paletteType === 'file') {
        const anyFile = fields.some(f => f.type === 'file' || (Array.isArray(f.children) && f.children.some(c => c.type === 'file')));
        if (anyFile) { showToast('è consentito un solo campo upload per scheda.', 'error'); clearDropSlot(); return; }
      }

      const nuovo = {
        name: '',
        type: paletteType,
        options: ['select', 'checkbox', 'radio'].includes(paletteType) ? ['opzione 1'] : [],
        is_fixed: false,
        colspan: 1
      };

      // Se 'section', colspan 2
      if (paletteType === 'section') {
        nuovo.colspan = 2;
        nuovo.children = [];
        nuovo.label = 'Nuova Sezione';
        nuovo.uid = 'sec_' + Date.now();
      }

      fields.splice(insertAt, 0, nuovo);
      render_preview();
      return;
    }

    // CASO B: Spostamento campo esistente (Root -> Root)
    const fromRootIdx = e.dataTransfer.getData('pe-from-root-index');
    if (fromRootIdx) {
      const src = parseInt(fromRootIdx, 10);
      if (!isNaN(src)) {
        const moved = fields[src];
        // Rimuovi da vecchia pos
        fields.splice(src, 1);
        // Ricalcola insertAt se necessario (se src < dest)
        const dest = (src < insertAt) ? insertAt - 1 : insertAt;
        fields.splice(dest, 0, moved);
        render_preview();
        return;
      }
    }

    // CASO C: Spostamento da Sezione -> Root (non implementato drag out ???) 
    // Se necessario implementarlo qui.
    clearDropSlot();
  }

  // cleanup globale
  document.addEventListener('dragend', () => {
    isInternalDrag = false;
    clearDropSlot();
    clearDynSnap();
    clearAllSectionSlots();
  });

  document.addEventListener('drop', (e) => {
    const t = e.target;
    if ((pageFoglio && t instanceof Node && pageFoglio.contains(t)) || (preview && t instanceof Node && preview.contains(t))) return;
    isInternalDrag = false;
    clearDropSlot();
    clearAllSectionSlots();
    clearDynSnap();
  }, false);

  // — API helpers —
  async function load_fields_or_empty() {
    if (!formName) {
      fields = CAMPI_FISSI.map(f => ({ ...f }));
      // Inizializza la scheda principale con i campi fissi
      tabState[DEFAULT_TAB_KEY] = { fields: fields, hasFixed: true };
      render_preview();
      return;
    }

    // Se i dati sono già stati caricati da restoreTabs, usa quelli
    const st = tabState[DEFAULT_TAB_KEY];
    if (st && Array.isArray(st.fields) && st.fields.length > 0) {
      // Dati già caricati, usa quelli
      fields = st.fields;
      render_preview();
      return;
    }

    // Se restoreTabs è disponibile, usalo (fa una sola chiamata getForm)
    if (typeof window.restoreTabs === 'function') {
      preview.innerHTML = '<div class="loader">caricamento...</div>';
      await window.restoreTabs();
      // Dopo restoreTabs, i dati sono già in tabState e fields
      const stAfter = tabState[DEFAULT_TAB_KEY];
      if (stAfter && Array.isArray(stAfter.fields)) {
        fields = stAfter.fields;
      } else {
        fields = CAMPI_FISSI.map(f => ({ ...f }));
      }
      render_preview();
      return;
    }

    // Fallback: se restoreTabs non è disponibile, fai la chiamata direttamente
    preview.innerHTML = '<div class="loader">caricamento...</div>';
    // UNA SOLA CHIAMATA: getForm restituisce form + tabs + fields
    const data = await window.customFetch('page_editor', 'getForm', { form_name: formName });

    // prefill intestazione
    if (inputFormName && formName) inputFormName.value = formName;
    if (inputFormDesc) inputFormDesc.value = String(data?.form?.description ?? '');
    // colore (sempre usa peWizardSetColor se disponibile, altrimenti imposta direttamente)
    const col = String(data?.form?.color ?? '#CCCCCC');
    if (window.peWizardSetColor) {
      window.peWizardSetColor(col);
    } else {
      const ci = document.getElementById('pe-form-color');
      const hi = document.getElementById('pe-form-color-hex');
      if (ci) ci.value = col.toLowerCase();
      if (hi) hi.value = col.toUpperCase();
    }
    // button_text: salva in config e aggiorna input se esiste
    formConfig.button_text = (data?.form?.button_text || '').trim();
    const submitBtnInput = document.getElementById('pe-submit-btn-text');
    if (submitBtnInput) {
      submitBtnInput.value = formConfig.button_text;
      const previewBtn = document.getElementById('pe-submit-btn-demo');
      if (previewBtn) {
        previewBtn.textContent = formConfig.button_text || 'Salva';
      }
    }

    if (!data || !data.success) {
      fields = CAMPI_FISSI.map(f => ({ ...f }));
      // Inizializza la scheda principale con i campi fissi
      tabState[DEFAULT_TAB_KEY] = { fields: fields, hasFixed: true };
      render_preview();
      return;
    }

    // responsabile (prefill quando si aprirà lo wizard)
    const currentResp = data?.form?.responsabile ?? '';
    if (selFormResp && currentResp) selFormResp.dataset.prefill = String(currentResp);

    // Usa tabs da getForm invece di fields flat
    // Se tabs non è disponibile, fallback a fields (retrocompatibilità)
    const tabsData = data.tabs || {};
    const fixedNames = CAMPI_FISSI.map(cf => cf.name);

    // Estrai fields flat SOLO dalla scheda Struttura (se tabs è disponibile).
    // NON usare data.fields perché contiene i campi di TUTTE le schede appiattiti,
    // causando duplicati quando li si assegna tutti a tabState[DEFAULT_TAB_KEY].
    let rows = [];
    if (tabsData && Object.keys(tabsData).length > 0 && tabsData['Struttura']) {
      // Usa SOLO i campi della scheda Struttura (fonte autorevole)
      const strutturaTab = tabsData['Struttura'];
      const strutturaFields = (Array.isArray(strutturaTab) ? strutturaTab : (strutturaTab.fields || []));
      strutturaFields.forEach(field => {
        rows.push({
          field_name: field.field_name || field.name,
          field_type: field.field_type || field.type,
          field_placeholder: field.field_placeholder || field.placeholder,
          field_options: field.field_options || field.options,
          required: field.required || 0,
          is_fixed: field.is_fixed || 0,
          sort_order: field.sort_order || 0,
          colspan: field.colspan || 1,
          parent_section_uid: field.parent_section_uid || null
        });
      });
    } else if (data.fields && Array.isArray(data.fields) && data.fields.length > 0) {
      // Fallback unico: vecchi form senza tabs → usa fields flat (retrocompatibilità)
      rows = data.fields;
    }
    // NOTA: NON esiste un terzo ramo che aggrega tutte le schede.
    // Se tabsData esiste ma non ha 'Struttura', rows resta [] e il form
    // viene inizializzato con i soli CAMPI_FISSI (linea più sotto).

    const parseArr = (v) => (typeof v === 'string' ? (() => { try { return JSON.parse(v) } catch { return [] } })() : (Array.isArray(v) ? v : []));
    const parseObj = (v) => (typeof v === 'string' ? (() => { try { return JSON.parse(v) } catch { return {} } })() : (v && typeof v === 'object' ? v : {}));

    // indicizza SEZIONI per uid
    const sectionsByUid = {};
    rows.forEach(r => {
      if (String(r.field_type || '').toLowerCase() !== 'section') return;
      const cfg = parseObj(r.field_options);
      const uid = String(cfg.uid || '').trim();
      const label = String(cfg.label || 'sezione');
      if (uid) sectionsByUid[uid] = { name: '', type: 'section', label, is_fixed: false, colspan: 2, _sec_uid: uid, children: [] };
    });

    // campi (non fissi, non section) con eventuale parent_section_uid
    const flat = rows
      .filter(f => !fixedNames.includes(String(f.field_name || '').toLowerCase()) &&
        String(f.field_type || '').toLowerCase() !== 'section')
      .map(f => {
        const ft = String(f.field_type || '').toLowerCase();
        // CORREZIONE: Per dbselect, field_options è un oggetto, non un array
        // Assicurati che venga sempre parsato correttamente
        let optionsObj = {};
        if (ft === 'dbselect') {
          // Per dbselect, field_options deve essere un oggetto
          if (typeof f.field_options === 'object' && !Array.isArray(f.field_options)) {
            optionsObj = f.field_options;
          } else if (typeof f.field_options === 'string') {
            try {
              const parsed = JSON.parse(f.field_options);
              optionsObj = (typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
            } catch {
              optionsObj = {};
            }
          } else {
            optionsObj = parseObj(f.field_options);
          }
        } else {
          optionsObj = parseObj(f.field_options);
        }

        let options = parseArr(f.field_options);
        let ds = null;
        let multi = false;
        if (ft === 'dbselect') {
          const raw = optionsObj;
          multi = boolish(raw.multiple);
          ds = {
            table: raw.table || '',
            valueCol: raw.valueCol || raw.labelCol || 'id',
            labelCol: raw.labelCol || raw.valueCol || 'id',
            multiple: multi ? 1 : 0,
            q: raw.q || '',
            limit: raw.limit || 200
          };
          options = [];
        }
        const parent_uid = f.parent_section_uid || optionsObj.parent_section_uid || null;
        return {
          name: String(f.field_name || '').toLowerCase(),
          type: ft,
          options,
          datasource: ds || undefined,
          multiple: multi,
          is_fixed: !!(f.is_fixed === 1 || f.is_fixed === true),
          colspan: (Number(f.colspan) === 2 ? 2 : 1),
          parent_section_uid: parent_uid
        };
      });

    // distribuisci figli nelle sezioni
    flat.forEach(c => {
      const uid = String(c.parent_section_uid || '').trim();
      if (uid && sectionsByUid[uid]) {
        sectionsByUid[uid].children.push({
          name: c.name, type: c.type, options: c.options || [], datasource: c.datasource,
          multiple: !!c.multiple,
          is_fixed: false, colspan: (Number(c.colspan) === 2 ? 2 : 1)
        });
      }
    });

    // ordine per sort_order
    const ordered = [];
    rows
      .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
      .forEach(r => {
        const t = String(r.field_type || '').toLowerCase();
        if (t === 'section') {
          const uid = String(parseObj(r.field_options).uid || '').trim();
          if (uid && sectionsByUid[uid]) ordered.push(sectionsByUid[uid]);
          return;
        }
        const isFixed = fixedNames.includes(String(r.field_name || '').toLowerCase());
        const hasParent = !!(r.parent_section_uid || parseObj(r.field_options).parent_section_uid);
        if (!isFixed && !hasParent) {
          const ft = t;
          // CORREZIONE: Per dbselect, field_options è un oggetto, non un array
          // Assicurati che venga sempre parsato correttamente
          let optionsObj = {};
          if (ft === 'dbselect') {
            // Per dbselect, field_options deve essere un oggetto
            if (typeof r.field_options === 'object' && !Array.isArray(r.field_options)) {
              optionsObj = r.field_options;
            } else if (typeof r.field_options === 'string') {
              try {
                const parsed = JSON.parse(r.field_options);
                optionsObj = (typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
              } catch {
                optionsObj = {};
              }
            } else {
              optionsObj = parseObj(r.field_options);
            }
          } else {
            optionsObj = parseObj(r.field_options);
          }

          let options = parseArr(r.field_options);
          let ds = null;
          let multi = false;
          if (ft === 'dbselect') {
            const raw = optionsObj;
            multi = boolish(raw.multiple);
            ds = {
              table: raw.table || '',
              valueCol: raw.valueCol || raw.labelCol || 'id',
              labelCol: raw.labelCol || raw.valueCol || 'id',
              multiple: multi ? 1 : 0,
              q: raw.q || '',
              limit: raw.limit || 200
            };
            options = [];
          }
          ordered.push({
            name: String(r.field_name || '').toLowerCase(),
            type: ft,
            options,
            datasource: ds || undefined,
            multiple: multi,
            is_fixed: false,
            colspan: (Number(r.colspan) === 2 ? 2 : 1)
          });
        }
      });

    fields = CAMPI_FISSI.map(cf => ({ ...cf })).concat(ordered);
    // Inizializza la scheda principale con tutti i campi (fissi + personalizzati)
    tabState[DEFAULT_TAB_KEY] = { fields: fields, hasFixed: true };
    render_preview();
  }

  async function ensure_form_exists(req_name, req_desc, req_color, req_button_text) {
    const clean = sanitize_slug(req_name);
    const desc = (req_desc && req_desc.trim()) ? req_desc.trim() : null;
    const colorHex = (req_color || '#CCCCCC').toUpperCase();
    const buttonText = (req_button_text && req_button_text.trim()) ? req_button_text.trim() : null;
    console.log('🔧 ensure_form_exists chiamato con:', { clean, desc, colorHex, buttonText });
    if (!clean) { showToast('inserisci un nome pagina valido.', 'error'); return null; }

    // esiste già?
    const already = await window.customFetch('page_editor', 'getForm', { form_name: clean });
    if (already?.success) {
      const patch = { form_name: clean };
      if (desc) patch.description = desc;
      if (req_color) patch.color = colorHex;
      if (buttonText !== null) patch.button_text = buttonText;
      if (Object.keys(patch).length > 1) await window.customFetch('page_editor', 'ensureForm', patch);
      showToast('Questa pagina esiste già. Apro in modifica…', 'info');
      return clean;
    }

    // crea
    const r1 = await window.customFetch('page_editor', 'ensureForm', {
      form_name: clean,
      description: desc,
      color: colorHex,
      button_text: buttonText
    });
    if (!r1?.success) { showToast(r1?.message || 'impossibile creare la pagina.', 'error'); console.warn('ensureForm fallita', r1); return null; }

    const gf = await window.customFetch('page_editor', 'getForm', { form_name: clean });
    if (!gf?.success) { showToast('pagina creata ma non ancora disponibile; riprova.', 'error'); return null; }
    return clean;
  }

  // Helper function per caricare e impostare il colore del form
  async function loadAndSetFormColor() {
    if (!formName) return;
    try {
      const data = await window.customFetch('page_editor', 'getForm', { form_name: formName });
      if (data?.form?.color) {
        const col = String(data.form.color);
        if (window.peWizardSetColor) {
          window.peWizardSetColor(col);
        } else {
          const ci = document.getElementById('pe-form-color');
          const hi = document.getElementById('pe-form-color-hex');
          if (ci) ci.value = col.toLowerCase();
          if (hi) hi.value = col.toUpperCase();
        }
      }
    } catch (e) {
      console.warn('[loadAndSetFormColor] Errore caricamento colore:', e);
    }
  }

  // Helper function per costruire la gerarchia sezione-children dai campi flat
  function buildSectionHierarchy(flatFields) {
    const parseArr = (v) => (typeof v === 'string' ? (() => { try { return JSON.parse(v) } catch { return [] } })() : (Array.isArray(v) ? v : []));
    const parseObj = (v) => (typeof v === 'string' ? (() => { try { return JSON.parse(v) } catch { return {} } })() : (v && typeof v === 'object' ? v : {}));

    // 1. Indicizza SEZIONI per uid
    const sectionsByUid = {};
    flatFields.forEach(r => {
      const ft = String(r.type || '').trim().toLowerCase();
      if (ft !== 'section') return;
      const cfg = parseObj(r.options);
      const uid = String(cfg.uid || '').trim();
      const label = String(cfg.label || 'sezione');
      if (uid) {
        sectionsByUid[uid] = {
          name: '',
          type: 'section',
          label,
          is_fixed: false,
          colspan: 2,
          _sec_uid: uid,
          children: []
        };
      }
    });

    // 2. Raccogli campi con parent_section_uid e associali alle sezioni
    const fieldsWithoutParent = [];
    flatFields.forEach(f => {
      // Validazione tipo campo: se è vuoto o invalido, usa 'text' come default
      let ft = String(f.type || '').trim().toLowerCase();
      if (!ft) {
        console.warn(`[buildSectionHierarchy] Campo senza tipo valido: `, {
          name: f.name || f.field_name,
          type: f.type,
          field: f
        });
        ft = 'text'; // Default sicuro
      }

      if (ft === 'section') return; // Le sezioni sono già state processate
      if (f.is_fixed) return; // I campi fissi vengono gestiti separatamente

      let options = [];
      let ds = null;
      let multi = false;
      if (ft === 'dbselect') {
        // CORREZIONE: Per dbselect, options è un oggetto con table, valueCol, labelCol
        // Può venire come oggetto già decodificato o come stringa JSON
        const raw = typeof f.options === 'object' && !Array.isArray(f.options)
          ? f.options
          : parseObj(f.options);
        // Preserva il datasource completo
        if (raw && (raw.table || raw.valueCol || raw.labelCol)) {
          multi = boolish(raw.multiple);
          ds = {
            table: raw.table || '',
            valueCol: raw.valueCol || 'id',
            labelCol: raw.labelCol || raw.valueCol || 'id',
            multiple: multi ? 1 : 0,
            q: raw.q || '',
            limit: raw.limit || 200
          };
        }
        options = [];
      } else {
        options = parseArr(f.options);
      }

      const parent_uid = String(f.parent_section_uid || '').trim();
      const fieldObj = {
        name: String(f.name || '').toLowerCase(),
        label: f.label || f.field_label || '',  // Preserva label con caratteri accentati
        type: ft,
        options,
        datasource: ds || undefined,
        multiple: multi,
        is_fixed: false,
        colspan: (Number(f.colspan) === 2 ? 2 : 1),
        allow_custom: (f.allow_custom === 1 || f.allow_custom === '1' || f.allow_custom === true)
      };

      if (parent_uid && sectionsByUid[parent_uid]) {
        // Questo campo appartiene a una sezione
        sectionsByUid[parent_uid].children.push(fieldObj);
      } else {
        // Questo campo è top-level
        fieldsWithoutParent.push(fieldObj);
      }
    });

    // 3. Costruisci l'array ordinato: sezioni con i loro figli + campi top-level
    //    GUARDIA DEDUP: evita che lo stesso field_name o section_uid venga aggiunto più volte
    const ordered = [];
    const addedNames = new Set();
    const addedSectionUids = new Set();
    flatFields
      .sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0))
      .forEach(r => {
        const ft = String(r.type || '').trim().toLowerCase();
        if (ft === 'section') {
          const cfg = parseObj(r.options);
          const uid = String(cfg.uid || '').trim();
          if (uid && sectionsByUid[uid] && !addedSectionUids.has(uid)) {
            addedSectionUids.add(uid);
            ordered.push(sectionsByUid[uid]);
          }
        } else if (!r.is_fixed && !r.parent_section_uid) {
          // Campo top-level (non fisso, non figlio di sezione)
          const name = String(r.name || '').toLowerCase();
          if (addedNames.has(name)) return; // DEDUP: skip duplicato
          const existing = fieldsWithoutParent.find(f => f.name === name);
          if (existing) {
            addedNames.add(name);
            ordered.push(existing);
          }
        }
      });

    return ordered;
  }

  // Mutex: impedisce salvataggi concorrenti
  let _isSavingStructure = false;

  async function save_structure() {
    // --- MUTEX: impedisce doppia invocazione concorrente ---
    if (_isSavingStructure) {
      console.warn('[save_structure] salvataggio già in corso, skip');
      return false;
    }
    _isSavingStructure = true;

    // Disabilita i bottoni di salvataggio durante l'operazione
    const saveButtons = [
      document.getElementById('pe-wizard-save'),
      document.getElementById('pe-unified-save')
    ].filter(Boolean);
    saveButtons.forEach(b => b.disabled = true);

    try {
    // --- fine guard ---
    if (!formName) { showToast('prima crea/seleziona la pagina.', 'error'); return false; }
    try { await wait_for_customfetch(); } catch { showToast('api non inizializzate (customFetch).', 'error'); return false; }

    // ============================================================================
    // STEP 1: NON ricaricare le schede dal database prima di salvare
    // L'utente potrebbe aver rimosso delle schede che devono essere eliminate
    // Invece, salva solo quello che è presente in memoria (tabs e tabState)
    // ============================================================================
    // RIMOSSO: Il codice che ricaricava le schede dal DB causava il ripristino
    // di schede che l'utente aveva appena eliminato

    // IMPORTANTE: Salva i campi della scheda attiva in tabState prima di procedere
    // E assicurati che le proprietà (isMain, visibilityMode, unlockAfterSubmitPrev) siano preservate
    if (activeTabKey && activeTabKey !== PROPERTIES_TAB_KEY) {
      const existingState = tabState[activeTabKey] || {};
      tabState[activeTabKey] = {
        ...existingState, // Preserva tutte le proprietà esistenti (isMain, visibilityMode, unlockAfterSubmitPrev, submitLabel, submitAction)
        fields: (activeTabKey === DEFAULT_TAB_KEY)
          ? fields  // Per la scheda principale, salva tutti i campi (fissi + personalizzati)
          : fields.filter(f => !f.is_fixed)  // Per le altre schede, salva solo i campi personalizzati (SENZA fissi)
      };
    }

    // PULIZIA: Rimuovi da tabState le schede che non esistono più in tabs
    const validTabKeys = new Set(tabs.map(t => t.key));
    validTabKeys.add(PROPERTIES_TAB_KEY); // Mantieni la scheda proprietà
    Object.keys(tabState).forEach(key => {
      if (!validTabKeys.has(key)) {
        console.debug(`[save_structure] Rimuovo scheda obsoleta: "${key}"`);
        delete tabState[key];
      }
    });

    // PULIZIA: Rimuovi i campi fissi da tutte le schede NON-Struttura
    Object.keys(tabState).forEach(tabKey => {
      if (tabKey !== DEFAULT_TAB_KEY && tabKey !== PROPERTIES_TAB_KEY) {
        const st = tabState[tabKey];
        if (st && Array.isArray(st.fields)) {
          const cleaned = st.fields.filter(f => !f.is_fixed);
          if (cleaned.length !== st.fields.length) {
            console.debug(`[save_structure] Rimossi ${st.fields.length - cleaned.length} campi fissi dalla scheda "${tabKey}"`);
            st.fields = cleaned;
          }
        }
      }
    });

    // VALIDAZIONE PRELIMINARE: Raccogli TUTTI i nomi di campo da TUTTE le schede e verifica duplicati
    const allFieldsByTab = new Map(); // tabKey -> array di nomi campo
    const globalFieldNames = new Map(); // fieldName -> [tabKey1, tabKey2, ...]

    tabs.forEach(tab => {
      if (tab.key === PROPERTIES_TAB_KEY) return;
      const st = tabState[tab.key];
      if (!st || !Array.isArray(st.fields)) return;

      const fieldNames = [];
      st.fields.forEach(f => {
        if (f.is_fixed) return; // I campi fissi non vengono validati

        const name = String(f.name || '').trim().toLowerCase();
        if (name) {
          fieldNames.push(name);
          if (!globalFieldNames.has(name)) {
            globalFieldNames.set(name, []);
          }
          globalFieldNames.get(name).push(tab.label);
        }

        // Controlla anche i figli delle sezioni
        if (f.type === 'section' && Array.isArray(f.children)) {
          f.children.forEach(c => {
            const childName = String(c.name || '').trim().toLowerCase();
            if (childName) {
              fieldNames.push(childName);
              if (!globalFieldNames.has(childName)) {
                globalFieldNames.set(childName, []);
              }
              globalFieldNames.get(childName).push(tab.label);
            }
          });
        }
      });

      allFieldsByTab.set(tab.key, fieldNames);
    });

    // Trova duplicati
    const duplicates = [];
    globalFieldNames.forEach((tabLabels, fieldName) => {
      if (tabLabels.length > 1) {
        duplicates.push({ name: fieldName, tabs: tabLabels });
      }
    });

    if (duplicates.length > 0) {
      console.error('[save_structure] DUPLICATI RILEVATI:', duplicates);
      const msg = duplicates.map(d => `"${d.name}" presente in: ${d.tabs.join(', ')} `).join('; ');
      showToast(`Campi duplicati tra schede! ${msg} `, 'error');
      return false;
    }

    // 1) unico campo file PER SCHEDA (anche annidato)
    let fileTabOverflow = null;
    tabs.forEach(tab => {
      if (tab.key === PROPERTIES_TAB_KEY) return;
      const st = tabState[tab.key];
      if (!st || !Array.isArray(st.fields)) return;
      let fileCount = 0;
      st.fields.forEach(f => {
        if (f.type === 'file') fileCount++;
        if (f.type === 'section' && Array.isArray(f.children)) {
          f.children.forEach(c => {
            if (c.type === 'file') fileCount++;
          });
        }
      });
      if (fileCount > 1 && !fileTabOverflow) {
        fileTabOverflow = tab.label || tab.key;
      }
    });
    if (fileTabOverflow) {
      showToast(`è consentito un solo campo upload per scheda ("${fileTabOverflow}").`, 'error');
      return false;
    }

    // 2) Funzioni helper per normalizzazione (ROBUSTA - mantiene nomi esistenti validi)
    const slug = s => (s || 'campo').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '') || 'campo';
    const usedNames = new Set(); // Nomi già riservati GLOBALMENTE

    const claim = (desired, currentName) => {
      // Se il campo ha già un nome valido (sluggato) e non è in conflitto, mantienilo
      const current = String(currentName || '').trim();
      const currentSlug = current ? slug(current) : '';

      // Se il nome corrente è già valido e univoco, mantienilo
      if (currentSlug && currentSlug === current && !usedNames.has(current.toLowerCase())) {
        usedNames.add(current.toLowerCase());
        return current;
      }

      // Altrimenti genera un nuovo nome basato su "desired"
      let base = slug(desired);
      let n = base, i = 2;
      while (usedNames.has(n.toLowerCase())) {
        n = `${base}_${i++} `;
      }
      usedNames.add(n.toLowerCase());
      return n;
    };

    // 4) serializza (flat + parent_section_uid) con UID sezione UNICI
    const payload = [];
    let sort = 0;

    const usedSecUid = new Set();
    const makeSecUid = (label, idx) => {
      const s = (String(label || 'sezione').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')) || 'sezione';
      let uid = `sec_${s}_${idx + 1} `, i = 2;
      while (usedSecUid.has(uid)) uid = `sec_${s}_${idx + 1}_${i++} `;
      usedSecUid.add(uid);
      return uid;
    };

    const serializeChildFlat = (c) => {
      // Validazione tipo campo (DEVE essere presente e valido)
      if (!c.type || typeof c.type !== 'string' || !c.type.trim()) {
        console.error(`[serializeChildFlat] Campo senza tipo valido: `, c);
        throw new Error(`Tipo mancante per campo ${c.name || 'senza nome'} `);
      }

      if (c.type === 'dbselect') {
        // CORREZIONE: Recupera datasource da c.datasource o da c.options se è un oggetto
        let ds = c?.datasource || {};

        // Se datasource non è presente o è vuoto, prova a recuperarlo da options
        if (!ds || (!ds.table && !ds.valueCol)) {
          if (c.options && typeof c.options === 'object' && !Array.isArray(c.options)) {
            ds = c.options;
          }
        }

        const multi = boolish(c.multiple ?? ds.multiple);
        return {
          field_name: c.name,
          field_label: c.label || '',  // Preserva label con caratteri accentati
          field_type: 'dbselect',
          field_options: {
            table: ds.table || '',
            valueCol: ds.valueCol || 'id',
            labelCol: ds.labelCol || ds.valueCol || 'id',
            multiple: multi ? 1 : 0,
            q: ds.q || '',
            limit: ds.limit || 200
          },
          colspan: (Number(c.colspan) === 2 ? 2 : 1)
        };
      }
      return {
        field_name: c.name,
        field_label: c.label || '',  // Preserva label con caratteri accentati
        field_type: c.type,
        field_options: Array.isArray(c.options) ? c.options : [],
        colspan: (Number(c.colspan) === 2 ? 2 : 1)
      };
    };

    // Salva tutti i campi di tutte le schede VALIDE (solo quelle in tabs)
    // IMPORTANTE: Crea un set delle chiavi delle schede valide per verificare che tabState appartenga a una scheda valida
    const activeTabKeysSet = new Set(tabs.filter(t => t.key !== PROPERTIES_TAB_KEY).map(t => t.key));

    try {
      tabs.forEach(tab => {
        // Salta la scheda proprietà (non ha campi)
        if (tab.key === PROPERTIES_TAB_KEY) return;

        const tabKey = tab.key;
        const tabLabel = tab.label;

        // IMPORTANTE: Per la scheda fissa, usa sempre "Struttura" come tab_label nel DB
        // anche se l'utente l'ha rinominata
        const dbTabLabel = (tabKey === DEFAULT_TAB_KEY) ? 'Struttura' : tabLabel;

        const st = tabState[tabKey];

        // Se la scheda non ha stato, salta (scheda vuota)
        if (!st || !Array.isArray(st.fields)) {
          console.debug(`[save_structure] Scheda "${tabLabel}" vuota, salto`);
          return;
        }

        // SICUREZZA: Verifica che la scheda sia ancora presente in tabs (non è stata rimossa)
        if (!activeTabKeysSet.has(tabKey)) {
          console.debug(`[save_structure] Scheda "${tabLabel}"(key: ${tabKey}) non più presente in tabs, salto`);
          return;
        }

        const tabFields = st.fields;
        console.debug(`[save_structure] Processando scheda "${tabLabel}" → DB: "${dbTabLabel}"(${tabFields.length} campi)`);

        tabFields.forEach((f, idx) => {
          // SKIP: I campi fissi non devono essere salvati qui (gestiti da ensureFixedFields)
          if (f.is_fixed) {
            console.debug(`[save_structure] Skipping campo fisso: ${f.name} `);
            return;
          }

          if (f.type === 'section') {
            f.colspan = 2;
            f.label = (f.label || 'sezione').toString().trim() || 'sezione';
            const sec_uid = (f._sec_uid && typeof f._sec_uid === 'string') ? f._sec_uid : makeSecUid(f.label, idx);
            sort += 10;
            payload.push({
              field_name: '__section__',
              field_type: 'section',
              field_options: { label: String(f.label || 'sezione'), uid: sec_uid },
              is_fixed: 0,
              sort_order: sort,
              colspan: 2,
              tab_label: dbTabLabel  // ← USA dbTabLabel invece di tabLabel
            });

            (Array.isArray(f.children) ? f.children : []).forEach((c) => {
              // Validazione tipo campo figlio (DEVE essere presente e valido)
              if (!c.type || typeof c.type !== 'string' || !c.type.trim()) {
                console.error(`[save_structure] Campo figlio senza tipo valido nella sezione '${f.label}': `, c);
                showToast(`il campo nella sezione '${f.label}'(${c.name || 'senza nome'}) non ha un tipo valido.`, 'error');
                throw new Error(`Tipo mancante per campo figlio ${c.name || 'senza nome'} `);
              }

              // Normalizza il nome del campo figlio
              const desired = (c.name && String(c.name).trim()) ? c.name : (c.type || 'campo');
              c.name = claim(desired, c.name);

              // Validazione opzioni per select/checkbox/radio
              if (['select', 'checkbox', 'radio'].includes(c.type)) {
                c.options = Array.isArray(c.options) ? c.options.map(String) : [];
                if (!c.options.length || c.options.some(o => !String(o).trim())) {
                  showToast(`il campo nella sezione '${f.label}'(${c.name}) richiede almeno una opzione valida.`, 'error');
                  throw new Error(`Opzioni mancanti per ${c.name} `);
                }
                delete c.datasource;
              }

              // Validazione dbselect
              if (c.type === 'dbselect') {
                // Recupera datasource da c.datasource
                let ds = c.datasource || {};

                // Compatibility check: if options is an object, it might be the datasource config
                if ((!ds || Object.keys(ds).length === 0) && c.options && typeof c.options === 'object' && !Array.isArray(c.options)) {
                  ds = c.options;
                }

                // 1. Check for Datasource Rule Code (New Way)
                const dsCode = (ds.datasource || '').trim();

                // 2. Check for Legacy Config (Old Way)
                const legacyTable = (ds.table || '').replace(/[^\w]/g, '');
                const legacyValCol = (ds.valueCol || '').replace(/[^\w]/g, '');

                // Validation: Must have EITHER code OR legacy config
                if (!dsCode && (!legacyTable || !legacyValCol)) {
                  showToast(`dbselect nella sezione '${f.label}'(${c.name}): seleziona un Datasource.`, 'error');
                  console.error(`❌ Datasource mancante per campo figlio '${c.name}': `, ds);
                  throw new Error(`Datasource mancante per ${c.name}`);
                }

                const multi = boolish(c.multiple ?? ds.multiple);
                c.multiple = multi;

                // Update structure based on what we have
                if (dsCode) {
                  c.datasource = {
                    datasource: dsCode,
                    multiple: multi ? 1 : 0
                  };
                } else {
                  c.datasource = {
                    table: legacyTable,
                    valueCol: legacyValCol,
                    labelCol: ds.labelCol || legacyValCol,
                    multiple: multi ? 1 : 0,
                    q: ds.q || '',
                    limit: ds.limit || 200
                  };
                }
                c.options = [];
              }
              c.colspan = (Number(c.colspan) === 2 ? 2 : 1);

              const row = serializeChildFlat(c);

              // CONTROLLO FINALE: verifica che row.field_type sia valido
              if (!row.field_type || typeof row.field_type !== 'string' || !row.field_type.trim()) {
                console.error(`[save_structure] Campo figlio senza tipo valido PRIMA del push: `, {
                  child: c,
                  row: row,
                  section_label: f.label,
                  tab_label: dbTabLabel
                });
                showToast(`il campo nella sezione '${f.label}'(${c.name || 'senza nome'}) non ha un tipo valido.`, 'error');
                throw new Error(`Tipo mancante per campo figlio ${c.name || 'senza nome'} nella sezione ${f.label} `);
              }

              sort += 10;
              payload.push({
                ...row,
                field_type: row.field_type.trim(), // Assicura che sia una stringa pulita
                is_fixed: 0,
                sort_order: sort,
                parent_section_uid: sec_uid,
                tab_label: dbTabLabel
              });  // ← USA dbTabLabel
            });
            return;
          }

          // Validazione tipo campo (DEVE essere presente e valido)
          if (!f.type || typeof f.type !== 'string' || !f.type.trim()) {
            console.error(`[save_structure] Campo senza tipo valido: `, f);
            showToast(`il campo '${f.name || 'senza nome'}' non ha un tipo valido.`, 'error');
            throw new Error(`Tipo mancante per campo ${f.name || 'senza nome'} `);
          }

          // Normalizza il nome del campo
          const desired = (f.name && String(f.name).trim()) ? f.name : (f.type || 'campo');
          f.name = claim(desired, f.name);

          // Validazione opzioni per select/checkbox/radio
          if (['select', 'checkbox', 'radio'].includes(f.type)) {
            if (!Array.isArray(f.options) || !f.options.length || f.options.some(o => !String(o).trim())) {
              showToast(`il campo '${f.name}' richiede almeno una opzione valida.`, 'error');
              throw new Error(`Opzioni mancanti per ${f.name} `);
            }
          }

          // Validazione dbselect
          if (f.type === 'dbselect') {
            console.log(`🔍 Validazione dbselect '${f.name}': `, {
              datasource: f.datasource,
              options: f.options,
              field: f
            });

            // CORREZIONE: Recupera datasource da f.datasource o da f.options se è un oggetto
            let ds = f.datasource || {};

            // Se datasource non è presente o è vuoto, prova a recuperarlo da options
            if (!ds || (!ds.table && !ds.valueCol)) {
              if (f.options && typeof f.options === 'object' && !Array.isArray(f.options)) {
                ds = f.options;
                console.log(`🔧 Recuperato datasource da options per '${f.name}': `, ds);
              }
            }

            ds.table = (ds.table || '').replace(/[^\w]/g, '');
            ds.valueCol = (ds.valueCol || '').replace(/[^\w]/g, '');
            if (!ds.table || !ds.valueCol) {
              showToast(`dbselect '${f.name}': seleziona tabella e colonna.`, 'error');
              console.error(`❌ Datasource mancante o incompleto per '${f.name}': `, ds, 'field completo:', f);
              throw new Error(`Datasource mancante per ${f.name} `);
            }
            const multi = boolish(f.multiple ?? ds.multiple);
            f.multiple = multi;
            f.datasource = {
              table: ds.table,
              valueCol: ds.valueCol,
              labelCol: ds.labelCol || ds.valueCol,
              multiple: multi ? 1 : 0,
              q: ds.q || '',
              limit: ds.limit || 200
            };
          }

          // CONTROLLO FINALE prima di aggiungere al payload: assicura che f.type sia valido
          if (!f.type || typeof f.type !== 'string' || !f.type.trim()) {
            console.error(`[save_structure] Campo senza tipo valido PRIMA del push: `, {
              field: f,
              name: f.name,
              type: f.type,
              tab_label: dbTabLabel
            });
            showToast(`il campo '${f.name || 'senza nome'}' nella scheda '${dbTabLabel}' non ha un tipo valido.`, 'error');
            throw new Error(`Tipo mancante per campo ${f.name || 'senza nome'} nella scheda ${dbTabLabel} `);
          }

          sort += 10;
          payload.push({
            field_name: f.name,
            field_label: f.label || '',  // Preserva label con caratteri accentati
            field_type: f.type.trim(), // Assicura che sia una stringa pulita
            field_options: (f.type === 'dbselect') ? (f.datasource || {}) : (Array.isArray(f.options) ? f.options : []),
            is_fixed: 0,
            sort_order: sort,
            colspan: (Number(f.colspan) === 2 ? 2 : 1),
            tab_label: dbTabLabel  // ← USA dbTabLabel invece di tabLabel
          });
        });
      });
    } catch (e) {
      console.error('[save_structure] Errore durante la preparazione del payload:', e);
      // Il messaggio di errore è già stato mostrato dal throw
      return false;
    }

    if (!payload.length) { showToast('aggiungi almeno un campo personalizzato prima di salvare.', 'error'); return false; }

    try {
      JSON.stringify(payload);
    } catch (e) {
      console.error('[save_structure] JSON invalido:', e);
      showToast('json non valido.', 'error');
      return false;
    }

    // Debug: mostra riepilogo payload DETTAGLIATO
    const payloadByTab = {};
    const payloadDetails = {};
    const invalidFields = [];
    payload.forEach((p, idx) => {
      const tl = p.tab_label || 'Struttura';
      payloadByTab[tl] = (payloadByTab[tl] || 0) + 1;
      if (!payloadDetails[tl]) payloadDetails[tl] = [];
      payloadDetails[tl].push({
        name: p.field_name,
        type: p.field_type,
        parent: p.parent_section_uid || '-',
        sort: p.sort_order
      });

      // CONTROLLO CRITICO: Verifica che field_type sia presente e valido
      if (!p.field_type || typeof p.field_type !== 'string' || !p.field_type.trim()) {
        invalidFields.push({
          index: idx,
          field: p,
          field_name: p.field_name,
          field_type: p.field_type,
          tab_label: tl
        });
      }
    });
    console.debug('[save_structure] 📊 Payload riepilogo per scheda:', payloadByTab);
    console.debug('[save_structure] 📋 Payload DETTAGLIO:', payloadDetails);
    console.debug('[save_structure] 📦 Payload totale:', payload.length, 'campi');
    console.debug('[save_structure] 🔍 Stato schede (tabState keys):', Object.keys(tabState));

    // DEBUG CRITICO: Mostra quante schede e quanti campi per scheda
    const tabDebug = tabs.filter(t => t.key !== PROPERTIES_TAB_KEY).map(t => ({
      key: t.key,
      label: t.label,
      campi_in_stato: (tabState[t.key]?.fields || []).length,
      nomi_campi: (tabState[t.key]?.fields || []).map(f => f.name).join(', ')
    }));
    console.debug('[save_structure] 🗂️ DEBUG SCHEDE:', tabDebug);

    // ===== LOG CONFLITTO: Prima di chiamare saveFormStructure =====
    console.log('🔵 [PageEditor] ===== PRIMA DI saveFormStructure =====');
    console.log('🔵 [PageEditor] Form:', formName);
    tabs.forEach(tab => {
      if (tab.key === PROPERTIES_TAB_KEY) return;
      const dbTabLabel = (tab.key === DEFAULT_TAB_KEY) ? 'Struttura' : tab.label;
      const st = tabState[tab.key];
      const tabFields = (st && Array.isArray(st.fields)) ? st.fields.filter(f => !f.is_fixed) : [];
      const fieldList = tabFields.map(f => `${f.name} (${f.type})`).join(', ');
      console.log(`🔵[PageEditor] Tab "${dbTabLabel}"(key: ${tab.key}): ${tabFields.length} campi →[${fieldList}]`);
    });
    const payloadByTabLog = {};
    payload.forEach(p => {
      const tl = p.tab_label || 'Struttura';
      if (!payloadByTabLog[tl]) payloadByTabLog[tl] = [];
      payloadByTabLog[tl].push(`${p.field_name} (${p.field_type})`);
    });
    Object.keys(payloadByTabLog).forEach(tl => {
      console.log(`🔵[PageEditor] Payload per "${tl}": ${payloadByTabLog[tl].length} campi →[${payloadByTabLog[tl].join(', ')}]`);
    });
    console.log('🔵 [PageEditor] ======================================');

    // CONTROLLO FINALE: Se ci sono campi senza tipo valido, blocca il salvataggio
    if (invalidFields.length > 0) {
      console.error('[save_structure] ❌ Campi senza tipo valido trovati:', invalidFields);
      console.error('[save_structure] ❌ Payload completo:', JSON.stringify(payload, null, 2));
      showToast(`Errore: ${invalidFields.length} campo / i senza tipo valido trovato / i.Controlla la console per i dettagli.`, 'error');
      return false;
    }

    const pre = await window.customFetch('page_editor', 'beforeSaveStructure', { form_name: formName });
    if (!pre?.success) { showToast(pre?.message || 'errore pre-salvataggio (moduli)', 'error'); return false; }

    // Costruisci tabsConfig indicizzato per tab.key (identità stabile)
    const tabsConfig = {};
    tabs.forEach((tab) => {
      if (tab.key === PROPERTIES_TAB_KEY) return;
      const tabStateData = tabState[tab.key] || {};
      const tabConfig = {
        label: tab.label,
        submit_label: tabStateData.submitLabel || null,
        submit_action: tabStateData.submitAction || 'submit',
        is_main: (tabStateData.isMain === true || tabStateData.isMain === 1) ? 1 : 0,
        visibility_mode: (tabStateData.visibilityMode || 'all'),
        unlock_after_submit_prev: (tabStateData.unlockAfterSubmitPrev === true || tabStateData.unlockAfterSubmitPrev === 1) ? 1 : 0,
        visibility_roles: tabStateData.visibilityRoles || ['utente', 'responsabile', 'assegnatario', 'admin'],
        edit_roles: tabStateData.editRoles || tabStateData.visibilityRoles || ['utente', 'responsabile', 'assegnatario', 'admin'],
        visibility_condition: tabStateData.visibilityCondition || { type: 'always' },
        redirect_after_submit: tabStateData.redirectAfterSubmit || false,
        is_closure_tab: (tabStateData.isClosureTab === true) ? 1 : 0
      };
      if (tabStateData.schedaType && (tabStateData.schedaType === 'chiusura' || tabStateData.schedaType === 'utente' || tabStateData.schedaType === 'responsabile')) {
        tabConfig.scheda_type = tabStateData.schedaType;
      }
      tabsConfig[tab.key] = tabConfig;
    });

    console.debug?.('[pe] save_structure payload', payload);

    const res = await window.customFetch('page_editor', 'saveFormStructure', { 
      form_name: formName, 
      fields: payload,
      tabs_config: JSON.stringify(tabsConfig)
    });
    if (res?.success) {
      if (typeof res.saved_dynamic === 'number') showToast(`struttura salvata(${res.saved_dynamic} campi)`, 'success');

      // persistTabs non viene più chiamata separatamente: save_structure invia
      // sia campi sia tabs_config in un'unica transazione via saveFormStructure.

      return true;
    }
    showToast(res?.message || 'errore nel salvataggio struttura.', 'error');
    return false;
    } finally {
      // --- MUTEX RELEASE: sempre, anche in caso di errore ---
      _isSavingStructure = false;
      saveButtons.forEach(b => b.disabled = false);
    }
  }

  async function loadSectionsAndParents() {
    if (!selSection || !selParent) return;
    try { await wait_for_customfetch(); } catch { showToast('api non disponibili per caricare sezioni/parent.', 'error'); return; }

    // tentativo principale
    try {
      const r = await window.customFetch('menu_custom', 'getSectionsAndParents', {});
      if (r?.success) {
        const map = r.data || r.map || {};
        selSection.innerHTML = '';
        Object.keys(map).forEach(k => {
          const opt = document.createElement('option'); opt.value = k; opt.textContent = k; selSection.appendChild(opt);
        });
        const refresh_parents = () => {
          const s = selSection.value; selParent.innerHTML = '';
          (map[s] || []).forEach(p => {
            const o = document.createElement('option'); o.value = p; o.textContent = p; selParent.appendChild(o);
          });
        };
        selSection.onchange = refresh_parents;
        refresh_parents();
        return;
      }
      if (r && r.message) console.warn('menu_custom/getSectionsAndParents:', r.message);
      throw new Error('fallback');
    } catch {
      // fallback: sidebar
      try {
        const sec = await window.customFetch('sidebar', 'getSectionsList', {});
        const sections = (sec?.success && Array.isArray(sec.data)) ? sec.data : [];
        selSection.innerHTML = '';
        sections.forEach(k => {
          const opt = document.createElement('option'); opt.value = k; opt.textContent = k; selSection.appendChild(opt);
        });

        const refresh_parents = async () => {
          const s = selSection.value || 'archivio';
          selParent.innerHTML = '';
          const par = await window.customFetch('sidebar', 'getParentMenus', { targetSection: s });
          const parents = (par?.success && Array.isArray(par.data)) ? par.data : [];
          parents.forEach(p => {
            const o = document.createElement('option'); o.value = p; o.textContent = p; selParent.appendChild(o);
          });
        };
        selSection.onchange = refresh_parents;
        await refresh_parents();
      } catch (e2) {
        console.error('Impossibile caricare sezioni/parent:', e2);
        showToast('Impossibile caricare sezioni e menu padre.', 'error');
      }
    }
  }

  // ========== Creazione nuovo menu padre inline ==========
  const btnNewParentMenu = document.getElementById('btn-new-parent-menu');
  const newParentMenuForm = document.getElementById('new-parent-menu-form');
  const newParentMenuTitle = document.getElementById('new-parent-menu-title');
  const btnCreateParentMenu = document.getElementById('btn-create-parent-menu');
  const btnCancelParentMenu = document.getElementById('btn-cancel-parent-menu');

  function showNewParentMenuForm() {
    if (newParentMenuForm) {
      newParentMenuForm.classList.remove('hidden');
      setTimeout(() => newParentMenuTitle?.focus(), 50);
    }
  }

  function hideNewParentMenuForm(reset = true) {
    if (newParentMenuForm) {
      newParentMenuForm.classList.add('hidden');
      if (reset && newParentMenuTitle) newParentMenuTitle.value = '';
    }
  }

  async function createNewParentMenu() {
    const title = (newParentMenuTitle?.value || '').trim();
    const section = selSection?.value || '';

    if (!title) {
      showToast('Inserisci un nome per il menu', 'error');
      newParentMenuTitle?.focus();
      return;
    }
    if (title.length < 2) {
      showToast('Il nome del menu deve avere almeno 2 caratteri', 'error');
      newParentMenuTitle?.focus();
      return;
    }
    if (title.length > 80) {
      showToast('Il nome del menu non può superare 80 caratteri', 'error');
      newParentMenuTitle?.focus();
      return;
    }
    if (!section) {
      showToast('Seleziona prima una sezione', 'error');
      return;
    }

    // Chiama API per creare il menu
    try {
      const res = await window.customFetch('page_editor', 'createParentMenu', {
        menu_section: section,
        title: title
      });

      if (res?.success) {
        showToast('Menu "' + title + '" creato con successo', 'success');

        // Aggiungi l'opzione al select e selezionala
        if (selParent) {
          const opt = document.createElement('option');
          opt.value = title;
          opt.textContent = title;
          selParent.appendChild(opt);
          selParent.value = title;
        }

        hideNewParentMenuForm(true);
      } else {
        showToast(res?.message || 'Errore durante la creazione del menu', 'error');
        newParentMenuTitle?.focus();
      }
    } catch (e) {
      console.error('createNewParentMenu error:', e);
      showToast('Errore di connessione durante la creazione del menu', 'error');
    }
  }

  // Event listeners per creazione menu padre
  btnNewParentMenu?.addEventListener('click', () => {
    if (newParentMenuForm?.classList.contains('hidden')) {
      showNewParentMenuForm();
    } else {
      hideNewParentMenuForm(true);
    }
  });

  btnCreateParentMenu?.addEventListener('click', createNewParentMenu);
  btnCancelParentMenu?.addEventListener('click', () => hideNewParentMenuForm(true));

  // Permetti invio con Enter nell'input
  newParentMenuTitle?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      createNewParentMenu();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      hideNewParentMenuForm(true);
    }
  });
  // ========== Fine creazione menu padre ==========

  // Gestore responsabili multipli nel wizard
  function initResponsabiliRows(responsabiliIds = []) {
    const container = document.getElementById('pe-responsabili-container');
    if (!container) return;

    container.innerHTML = '';
    const ids = Array.isArray(responsabiliIds) ? responsabiliIds : (String(responsabiliIds).split(',').filter(Boolean));
    
    if (ids.length === 0) ids.push(''); // Almeno una riga vuota

    ids.forEach((id, idx) => {
      addResponsabileRow(container, id, idx === 0);
    });
    
    // Sincronizza l'anteprima dopo l'inizializzazione
    syncResponsabiliPreview();
  }

  function addResponsabileRow(container, prefillId = '', isFirst = false) {
    const rowCount = container.querySelectorAll('.pe-responsabile-row').length;
    if (rowCount >= 3) {
      showToast('Massimo 3 responsabili consentiti.', 'info');
      return;
    }

    const row = document.createElement('div');
    row.className = 'pe-responsabile-row';
    row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px; align-items:center;';

    const select = document.createElement('select');
    select.className = 'pe-form-resp';
    select.style.cssText = 'width:100%; max-width:450px;';
    select.innerHTML = '<option value="">— seleziona responsabile —</option>';
    if (prefillId) select.dataset.prefill = prefillId;

    row.appendChild(select);

    if (isFirst) {
      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'button pe-add-resp';
      addBtn.style.cssText = 'padding:4px 12px; height:32px;';
      addBtn.textContent = '+';
      addBtn.title = 'Aggiungi responsabile';
      addBtn.onclick = () => addResponsabileRow(container);
      row.appendChild(addBtn);
    } else {
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'button btn-secondary';
      delBtn.style.cssText = 'padding:4px 10px; height:32px; color:#c0392b;';
      delBtn.textContent = '✖';
      delBtn.onclick = () => row.remove();
      row.appendChild(delBtn);
    }

    container.appendChild(row);
    // Popola il nuovo select
    fillResponsabileSelect(select);
  }

  let cachedResponsabiliOptions = null;
  async function loadResponsabiliOptions() {
    try {
      await wait_for_customfetch();
      const r = await window.customFetch('page_editor', 'listResponsabili', {});
      cachedResponsabiliOptions = (r?.success && Array.isArray(r.options)) ? r.options : [];
      
      const selects = document.querySelectorAll('.pe-form-resp');
      selects.forEach(sel => fillResponsabileSelect(sel));
    } catch (e) { console.error('loadResponsabiliOptions error:', e); }
  }

  function fillResponsabileSelect(select) {
    if (!cachedResponsabiliOptions) return;
    const currentVal = select.value;
    select.innerHTML = '<option value="">— seleziona responsabile —</option>';
    cachedResponsabiliOptions.forEach(o => {
      const op = document.createElement('option');
      op.value = String(o.id);
      op.textContent = o.label;
      select.appendChild(op);
    });
    const pf = select.dataset.prefill;
    if (pf) select.value = pf;
    else if (currentVal) select.value = currentVal;

    // Aggiungi listener per aggiornare l'anteprima
    select.addEventListener('change', syncResponsabiliPreview);
  }

  function syncResponsabiliPreview() {
    const displayEl = document.getElementById('pe-meta-responsabile-display');
    if (!displayEl) return;

    const selects = document.querySelectorAll('.pe-form-resp');
    let html = '';
    const added = new Set();

    selects.forEach(sel => {
        const val = sel.value;
        if (val && !added.has(val) && cachedResponsabiliOptions) {
            const opt = cachedResponsabiliOptions.find(o => String(o.id) === String(val));
            if (opt) {
                added.add(val);
                html += `<div class="resp-preview-item" style="display:inline-flex; align-items:center; gap:4px; margin-right:12px;">
                    <img src="${opt.img || 'assets/images/default_profile.png'}" class="profile-image-small" alt="responsabile">
                    <span>${opt.label}</span>
                </div>`;
            }
        }
    });

    displayEl.innerHTML = html || '<span style="color: #999;">—</span>';
    
    // Se siamo in anteprima, aggiorna anche la preview globale
    if (typeof render_preview === 'function') render_preview();
  }

  async function fetchCurrentMenuPlacementForForm(formName) {
    try {
      await wait_for_customfetch();
      const res = await window.customFetch('page_editor', 'getMenuPlacementForForm', { form_name: formName });
      if (res?.success && res.placement) {
        return { section: res.placement.section || '', parent: res.placement.parent_title || '' };
      }
      return null;
    } catch (e) {
      console.warn('fetchCurrentMenuPlacementForForm error:', e);
      return null;
    }
  }

  async function openWizardPrefilledForEdit() {
    const fname = (formNameHidden?.value || '').trim();
    if (!fname) return;
    try {
      const place = await fetchCurrentMenuPlacementForForm(fname);
      if (place) {
        if (selSection) {
          const has = Array.from(selSection.options).some(o => o.value === place.section);
          if (has) selSection.value = place.section;
          selSection.dispatchEvent(new Event('change'));
        }
        setTimeout(() => {
          if (selParent && place.parent) {
            const hasP = Array.from(selParent.options).some(o => o.value === place.parent);
            if (hasP) selParent.value = place.parent;
          }
        }, 0);
      }
    } catch (e) { console.warn('[page_editor] fetchCurrentMenuPlacementForForm error:', e); }
  }

  // ————————— wiring: flusso nuovo (wizard sostitutivo) —————————
  function wire_flow() {
    // Il wizard è ora gestito tramite le schede, non più come funzione separata
    window.peOpenWizard = async function (prefilled) {
      // Cambia alla scheda proprietà
      await switchToTab(PROPERTIES_TAB_KEY);
    };

    // Bottone "Avanti" per aprire il wizard delle proprietà
    if (btnOpenWizard) {
      btnOpenWizard.addEventListener('click', async () => {
        // Cambia alla scheda proprietà
        await switchToTab(PROPERTIES_TAB_KEY);
      });
    }

    if (btnWizardBack) {
      btnWizardBack.addEventListener('click', async () => {
        // Torna alla scheda struttura
        await switchToTab(DEFAULT_TAB_KEY);
      });
    }

    // Salva proprietà (crea/modifica)
    if (btnWizardSave) {
      btnWizardSave.addEventListener('click', async () => {
        // Verifica che la pagina sia stata creata (ensureForm chiamato)
        if (!formName) {
          showToast('Prima crea la pagina cliccando su "Avanti" o salvando la struttura.', 'info');
          return;
        }

        const rawname = (inputFormName?.value || '').trim();
        if (!rawname) { showToast('inserisci il nome pagina.', 'error'); return; }

        const rawdesc = (inputFormDesc?.value || '').trim();
        const colorHex = (window.peWizardColor ? window.peWizardColor() : '#CCCCCC');
        // Usa il valore dalla config in memoria (più affidabile dell'input che potrebbe non esistere)
        const buttonText = (formConfig.button_text || '').trim();
        console.log('📝 Salvataggio proprietà form:', { rawname, rawdesc, colorHex, buttonText });
        const created = await ensure_form_exists(rawname, rawdesc, colorHex, buttonText);
        if (!created) return;

        formName = created;
        if (formNameHidden) formNameHidden.value = created;

        // Aggiorna il testo del bottone "Avanti" dopo aver creato la pagina
        if (btnOpenWizard) {
          btnOpenWizard.innerHTML = '⚙️ Proprietà';
        }
        // Aggiorna lo stato di salvataggio
        if (statusText) {
          statusText.textContent = 'Pagina creata - Pronto per salvare';
          statusText.style.color = '#666';
        }

        const menu_section = selSection?.value || '';
        const parent_title = selParent?.value || '';
        const attivo = chkAttivo?.checked ? 1 : 0;
        if (!menu_section || !parent_title) { showToast('seleziona sezione e menu padre.', 'error'); return; }

        // Raccogli tutti i responsabili selezionati
        const respSelects = document.querySelectorAll('.pe-form-resp');
        const respIds = [];
        respSelects.forEach(sel => {
          const val = (sel.value || '').trim();
          if (val && !respIds.includes(val)) respIds.push(val);
        });

        if (respIds.length > 0) {
          const rr = await window.customFetch('page_editor', 'setFormResponsabile', {
            form_name: formName, user_ids: respIds.join(',')
          });
          if (!rr?.success) { showToast(rr?.message || 'Errore salvataggio responsabili.', 'error'); return; }
        }

        const hasDyn = Array.isArray(fields) && fields.some(f => !f.is_fixed);
        if (hasDyn) {
          const ok = await save_structure();
          if (!ok) return;
          await render_modules();
          // BUGFIX: Usa restoreTabs invece di load_fields_or_empty per mantenere le schede separate
          await window.restoreTabs();
          // Ricostruisci la UI delle schede
          if (window.buildTabsBar) window.buildTabsBar();
          // Ricarica i campi della scheda attiva
          const st = tabState[activeTabKey];
          if (st && Array.isArray(st.fields)) {
            fields = st.fields.filter(f => f); // Filtra undefined/null
          }
          render_preview();
        }

        // Usa SEMPRE il formName pulito impostato dopo ensureForm (non dall'URL)
        if (!formName || formName.trim() === '') {
          console.warn('🚫 [MENU_UPSERT_3_BLOCKED] Salvataggio proprietà - Upsert BLOCCATO: formName non disponibile:', {
            formName,
            caller: 'save_properties - blocked by guard (formName not set)',
            stack: new Error().stack
          });
          showToast('Proprietà salvate ma voce di menu non aggiornata: form non creato.', 'warning');
        } else {
          const link = `index.php?section=${encodeURIComponent(menu_section)}&page=gestione_segnalazioni&form_name=${encodeURIComponent(formName)}`;
          if (!isValidMenuLink(link)) {
            console.warn('🚫 [MENU_UPSERT_3_BLOCKED] Salvataggio proprietà - Upsert BLOCCATO per link invalido:', {
              formName,
              link,
              isValidLink: isValidMenuLink(link),
              caller: 'save_properties - blocked by invalid link',
              stack: new Error().stack
            });
            showToast('Proprietà salvate ma voce di menu non aggiornata: link invalido.', 'warning');
          } else {
            console.log('🔧 [MENU_UPSERT_3] Salvataggio proprietà - Chiamata upsert valida:', {
              title: formName,
              link: link,
              form_name: formName,
              caller: 'save_properties - menu update',
              stack: new Error().stack
            });
            const res = await window.customFetch('menu_custom', 'upsert', {
              menu_section, parent_title, title: formName, link, attivo, ordinamento: 100
            });
            if (!res?.success) { showToast(res?.message || 'errore nel salvataggio del menu.', 'error'); return; }
          }
        }

        showToast('Proprietà salvate.', 'success');
        // Torna alla scheda struttura invece di reindirizzare
        switchToTab(DEFAULT_TAB_KEY);
      });
    }

    // Salva modifiche struttura (modalità modifica)
    if (btnSaveChanges) {
      btnSaveChanges.addEventListener('click', async () => {
        const ok = await save_structure();
        if (!ok) return;

        showToast('Modifiche salvate con successo', 'success');
        // BUGFIX: Usa restoreTabs invece di load_fields_or_empty per mantenere le schede separate
        await restoreTabs();
        // Ricostruisci la UI delle schede
        if (window.buildTabsBar) window.buildTabsBar();
        // Ricarica i campi della scheda attiva
        const st = tabState[activeTabKey];
        if (st && Array.isArray(st.fields)) {
          fields = st.fields.filter(f => f); // Filtra undefined/null
        }
        render_preview();

        try {
          const place = await fetchCurrentMenuPlacementForForm(formName);
          if (place?.section) {
            const link = `index.php?section=${encodeURIComponent(place.section)}&page=gestione_segnalazioni&form_name=${encodeURIComponent(formName)}`;
            window.location = link;
          }
        } catch (_) { }
      });
    }

    // Bottone unificato di salvataggio (sempre visibile nella barra inferiore)
    if (btnUnifiedSave) {
      btnUnifiedSave.addEventListener('click', async () => {
        if (!formName) {
          showToast('Prima crea la pagina cliccando su "Avanti"', 'info');
          return;
        }

        if (statusText) statusText.textContent = 'Salvataggio in corso...';
        btnUnifiedSave.disabled = true;

        const ok = await save_structure();

        if (ok) {
          showToast('Modifiche salvate con successo', 'success');
          if (statusText) statusText.textContent = 'Salvato ✓';

          // BUGFIX: Usa restoreTabs invece di load_fields_or_empty per mantenere le schede separate
          await window.restoreTabs();
          // Ricostruisci la UI delle schede
          if (window.buildTabsBar) window.buildTabsBar();
          // Ricarica i campi della scheda attiva
          const st = tabState[activeTabKey];
          if (st && Array.isArray(st.fields)) {
            fields = st.fields.filter(f => f); // Filtra undefined/null
          }
          render_preview();

          // Resetta lo stato dopo 2 secondi
          setTimeout(() => {
            if (statusText) statusText.textContent = 'Pronto per salvare';
          }, 2000);
        } else {
          if (statusText) statusText.textContent = 'Errore nel salvataggio';
        }

        btnUnifiedSave.disabled = false;
      });
    }

    // Aggiorna lo stato quando ci sono modifiche
    function markAsModified() {
      if (statusText && formName) {
        statusText.textContent = 'Modifiche non salvate';
        statusText.style.color = '#ff9800';
      }
    }

    // Aggiungi listener per rilevare modifiche
    const previewContainer = document.getElementById('form-fields-preview');
    if (previewContainer) {
      // Usa MutationObserver per rilevare quando vengono aggiunti/rimossi campi
      const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
          if (mutation.type === 'childList' && (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0)) {
            markAsModified();
            break;
          }
        }
      });
      observer.observe(previewContainer, { childList: true, subtree: true });
    }
  }

  // ————————— init + UI moduli —————————
  const elAvail = document.getElementById('available-modules');
  const elActive = document.getElementById('active-modules');
  async function pe_api(action, payload) { return await window.customFetch('page_editor', action, payload || {}); }
  let modulesRegistryCache = null, currentModuleKey = null, currentFormName = null;

  function buildFieldControl(field, value) {
    const wrap = document.createElement('div');
    wrap.className = 'form-group';

    if (field.type === 'group') {
      const legend = document.createElement('h4');
      legend.textContent = field.label || field.key;
      wrap.appendChild(legend);
      const inner = document.createElement('div');
      (field.fields || []).forEach(sf => inner.appendChild(buildFieldControl(sf, value && typeof value === 'object' ? value[sf.key] : undefined)));
      wrap.appendChild(inner);
      return wrap;
    }

    if (field.label && field.type !== 'checkbox' && field.type !== 'checkbox-group') {
      const lab = document.createElement('label'); lab.textContent = field.label; wrap.appendChild(lab);
    }

    const key = field.key;
    const def = (value !== undefined) ? value : field.default;

    if (field.type === 'checkbox') {
      const lbl = document.createElement('label'); lbl.style.display = 'inline-flex'; lbl.style.gap = '6px';
      const inp = document.createElement('input'); inp.type = 'checkbox'; inp.dataset.key = key; inp.checked = !!def;
      const span = document.createElement('span'); span.textContent = field.label || key;
      lbl.append(inp, span); wrap.appendChild(lbl); return wrap;
    }

    if (field.type === 'select') {
      const sel = document.createElement('select'); sel.dataset.key = key;
      (field.options || []).forEach(o => { const opt = document.createElement('option'); opt.value = o.v; opt.textContent = o.l; sel.appendChild(opt); });
      sel.value = def ?? (field.options?.[0]?.v ?? ''); wrap.appendChild(sel); return wrap;
    }

    if (field.type === 'checkbox-group') {
      const box = document.createElement('div'); const arr = Array.isArray(def) ? def.map(String) : [];
      (field.options || []).forEach(o => {
        const lbl = document.createElement('label'); lbl.style.display = 'inline-flex'; lbl.style.gap = '6px';
        const c = document.createElement('input'); c.type = 'checkbox'; c.dataset.key = key; c.dataset.value = String(o.v); c.checked = arr.includes(String(o.v));
        const span = document.createElement('span'); span.textContent = o.l; lbl.append(c, span); box.appendChild(lbl);
      });
      wrap.appendChild(box); return wrap;
    }

    const inp = document.createElement('input'); inp.type = 'text'; inp.dataset.key = key; inp.value = def ?? ''; wrap.appendChild(inp);
    return wrap;
  }

  function collectFieldValue(field, container) {
    if (field.type === 'group') {
      const obj = {}; (field.fields || []).forEach(sf => obj[sf.key] = collectFieldValue(sf, container)); return obj;
    }
    if (field.type === 'checkbox') return !!container.querySelector(`[data - key= "${field.key}"]`)?.checked;
    if (field.type === 'select') return container.querySelector(`select[data - key= "${field.key}"]`)?.value ?? null;
    if (field.type === 'checkbox-group') {
      const vals = []; container.querySelectorAll(`input[type = "checkbox"][data - key= "${field.key}"]`).forEach(e => { if (e.checked) vals.push(e.dataset.value); });
      return vals.map(v => (/^\d+$/.test(v) ? parseInt(v, 10) : v));
    }
    return container.querySelector(`[data - key= "${field.key}"]`)?.value ?? '';
  }

  function switchCfgTab(tab) {
    const vGuid = document.getElementById('module-config-form');
    const vAdv = document.getElementById('module-config-raw');
    const isGuid = tab === 'guidata';
    if (vGuid && vAdv) { vGuid.style.display = isGuid ? 'block' : 'none'; vAdv.style.display = isGuid ? 'none' : 'block'; }
    document.querySelectorAll('#cfg-tabs .cfg-tab').forEach(b => {
      const t = b.getAttribute('data-tab');
      b.classList.toggle('is-active', t === tab);
      b.classList.toggle('btn-secondary', t !== tab);
      b.style.borderBottom = (t === tab) ? '2px solid #7aa8ff' : '2px solid transparent';
    });
  }

  function openModuleConfigModal(moduleKey, config, formName) {
    currentModuleKey = moduleKey; currentFormName = formName;
    const modal = document.getElementById('module-config-modal');
    const title = document.getElementById('module-config-title');
    const cont = document.getElementById('module-config-form');
    const rawArea = document.getElementById('module-config-json');
    if (title) title.textContent = `configurazione modulo: ${moduleKey} `;
    if (cont) cont.innerHTML = '';

    const schema = MODULE_CONFIG_SCHEMAS[moduleKey];
    try {
      if (schema && cont) {
        (schema.fields || []).forEach(f => cont.appendChild(buildFieldControl(f, (config && typeof config === 'object') ? config[f.key] : undefined)));
        if (rawArea) rawArea.value = JSON.stringify(config || {}, null, 2);
        switchCfgTab('guidata');
      } else {
        if (cont) cont.innerHTML = `<p class="muted">configurazione guidata non disponibile per questo modulo.</p>`;
        if (rawArea) rawArea.value = JSON.stringify(config || {}, null, 2);
        switchCfgTab('avanzate');
      }
    } catch (_) {
      if (cont) cont.innerHTML = `<p class="muted">config non disponibile; usa la scheda "avanzate".</p>`;
      if (rawArea) rawArea.value = '{}';
      switchCfgTab('avanzate');
    }

    if (modal) {
      modal.classList.remove('hidden'); modal.classList.add('show');
      document.documentElement.style.overflow = 'hidden'; document.body.style.overflow = 'hidden';
    }
  }

  function closeModuleConfigModal() {
    const modal = document.getElementById('module-config-modal');
    if (modal) { modal.classList.remove('show'); modal.classList.add('hidden'); }
    document.documentElement.style.overflow = ''; document.body.style.overflow = '';
    currentModuleKey = null; currentFormName = null;
  }

  async function saveModuleConfig() {
    const rawArea = document.getElementById('module-config-json');
    const cont = document.getElementById('module-config-form');
    const schema = MODULE_CONFIG_SCHEMAS[currentModuleKey];
    let obj = {};
    if (schema) {
      (schema.fields || []).forEach(f => { obj[f.key] = collectFieldValue(f, cont); });
      try { rawArea.value = JSON.stringify(obj, null, 2); } catch { }
    } else {
      try { obj = rawArea.value.trim() ? JSON.parse(rawArea.value) : {}; }
      catch { showToast('JSON non valido', 'error'); return; }
    }

    if (!currentFormName) {
      stagedConfig[currentModuleKey] = obj;
      showToast('Configurazione salvata in anteprima', 'success');
      await renderModulesPreview();
    } else {
      const r = await pe_api('saveModuleConfig', { form_name: currentFormName, module_key: currentModuleKey, config: obj });
      if (r?.success) { showToast('Configurazione salvata', 'success'); await renderModulesPreview(); }
      else { showToast(r?.message || 'Errore salvataggio configurazione', 'error'); }
    }
    closeModuleConfigModal();
  }

  async function render_modules() {
    if (!elAvail || !elActive) return;
    const fname = (formNameHidden?.value || '').trim();

    if (!modulesRegistryCache) {
      const reg = await pe_api('getModulesRegistry', {});
      modulesRegistryCache = reg?.success ? reg.modules : [];
    }
    const registry = modulesRegistryCache;

    let attached = [];
    if (fname) {
      const att = await pe_api('getAttachedModules', { form_name: fname });
      attached = att?.success ? att.modules.map(m => ({ key: m.key, config: m.config || null })) : [];
    } else {
      attached = stagedModules.map(k => ({ key: k, config: stagedConfig[k] || null }));
    }

    const attachedMap = {}; attached.forEach(m => attachedMap[m.key] = true);
    elAvail.innerHTML = ''; elActive.innerHTML = '';

    // disponibili
    registry.forEach(m => {
      if (attachedMap[m.key]) return;
      const card = document.createElement('div');
      card.className = 'field-card module-card'; card.draggable = true; card.dataset.moduleKey = m.key;
      if (m.icon) { const i = new Image(); i.src = m.icon; i.alt = ''; card.appendChild(i); }
      card.appendChild(document.createTextNode(m.label || m.key));

      card.addEventListener('dragstart', (e) => {
        e.dataTransfer.setData('module-key', m.key);
        e.dataTransfer.setData('application/x-pe-drag', 'module');
        e.dataTransfer.setData('text/plain', `module:${m.key} `);
        e.dataTransfer.effectAllowed = 'copy';
      });

      card.addEventListener('dblclick', async () => {
        if (!fname) {
          if (!stagedModules.includes(m.key)) stagedModules.push(m.key);
          await render_modules(); await renderModulesPreview();
          showToast('modulo in anteprima; si attiverà al salvataggio.', 'info');
          return;
        }
        const r = await pe_api('attachModule', { form_name: fname, module_key: m.key });
        if (r?.success) { showToast('modulo aggiunto', 'success'); await render_modules(); await load_fields_or_empty(); await renderModulesPreview(); }
        else { showToast(r?.message || 'errore aggiunta modulo', 'error'); }
      });

      elAvail.appendChild(card);
    });

    // attivi
    attached.forEach(m => {
      const meta = registry.find(x => x.key === m.key) || { key: m.key, label: m.key, icon: null };
      const card = document.createElement('div'); card.className = 'field-card module-card';

      const left = document.createElement('div');
      if (meta.icon) { const i = new Image(); i.src = meta.icon; i.alt = ''; left.appendChild(i); }
      left.appendChild(document.createTextNode(meta.label || meta.key));

      const btncfg = document.createElement('button'); btncfg.type = 'button'; btncfg.className = 'button small'; btncfg.textContent = '⚙︎';
      btncfg.addEventListener('click', () => openModuleConfigModal(m.key, m.config, fname));

      const btndel = document.createElement('button'); btndel.type = 'button'; btndel.className = 'button small'; btndel.textContent = '✖';
      btndel.addEventListener('click', async () => {
        if (!fname) {
          stagedModules = stagedModules.filter(k => k !== m.key);
          delete stagedConfig[m.key];
          await render_modules(); await renderModulesPreview(); return;
        }
        if (!confirm('rimuovere il modulo? i dati esistenti non verranno cancellati.')) return;
        const r = await pe_api('detachModule', { form_name: fname, module_key: m.key });
        if (r?.success) { showToast('modulo rimosso', 'success'); await render_modules(); await renderModulesPreview(); }
        else { showToast(r?.message || 'errore rimozione', 'error'); }
      });

      const right = document.createElement('div'); right.append(btncfg, btndel);
      card.append(left, right);
      elActive.appendChild(card);
    });

    await renderModulesPreview();
  }

  function modulePreviewFactory(mod) {
    const wrap = document.createElement('div'); wrap.style.marginTop = '14px';
    if (mod.key === 'gestione_richiesta') {
      wrap.innerHTML = `
        <div class="esito-segnalazione-block loaded">
        <div class="esito-title-bar">
          <h3 class="esito-title">Gestione della richiesta</h3>
          <span class="edit-gestione-icon"><img src="assets/icons/edit.png" alt="Modifica"></span>
        </div>
        <div class="esito-fields-grid">
          <div class="esito-row-flex"><span class="esito-label">Stato esito:</span><span class="esito-value">–</span></div>
          <div class="esito-row-flex"><span class="esito-label">Data programmata chiusura:</span><span class="esito-value">–</span></div>
          <div class="esito-row-flex"><span class="esito-label">Note del responsabile:</span><span class="esito-value">–</span></div>
        </div>
      </div>`;
      return wrap;
    }
    wrap.innerHTML = `<div class="field-card"><strong>Anteprima modulo:</strong> ${mod.key} <div class="muted" style="margin-top:6px;">(Nessuna anteprima specifica)</div></div>`;
    return wrap;
  }

  async function renderModulesPreview(options = {}) {
    const cont = document.getElementById('modules-preview'); if (!cont) return;
    cont.innerHTML = '';
    const scope = String(options.scope || '').toLowerCase();

    let mods = [];
    const fname = (formNameHidden?.value || '').trim();
    if (!fname) mods = stagedModules.map(k => ({ key: k, config: stagedConfig[k] || {} }));
    else {
      const att = await pe_api('getAttachedModules', { form_name: fname });
      if (att?.success) mods = att.modules || [];
    }
    if (scope === 'struttura') mods = mods.filter(m => String(m.key) !== 'gestione_richiesta');
    if (!mods.length) { cont.innerHTML = (scope === 'struttura') ? '' : `<p class="empty">Nessun modulo attivo in anteprima.</p>`; return; }

    const h = document.createElement('h3'); h.textContent = 'Anteprima moduli'; cont.appendChild(h);
    mods.forEach(m => cont.appendChild(modulePreviewFactory(m)));
  }

  // ————————— bootstrap —————————
  document.addEventListener('DOMContentLoaded', function () {
    // modal
    const modal = document.getElementById('module-config-modal');
    const saveBtn = document.getElementById('save-config');
    if (modal) {
      modal.addEventListener('click', (e) => { if (e.target === modal) closeModuleConfigModal(); });
      modal.querySelector('.close-modal')?.addEventListener('click', (e) => { e.preventDefault(); closeModuleConfigModal(); });
      document.getElementById('cancel-config')?.addEventListener('click', (e) => { e.preventDefault(); closeModuleConfigModal(); });
      const tabs = document.getElementById('cfg-tabs');
      if (tabs) {
        tabs.addEventListener('click', (e) => {
          const btn = e.target.closest('.cfg-tab'); if (!btn) return;
          e.preventDefault(); const tab = btn.getAttribute('data-tab'); if (tab) switchCfgTab(tab);
        });
      }
      // ESC gestito dal KeyboardManager globale (main_core.js)
    }
    saveBtn?.addEventListener('click', (e) => { e.preventDefault(); saveModuleConfig(); });

    wait_for_customfetch().then(async () => {
      initRightToolbar();
      wirePaletteFieldDrag();

      // restoreTabs() viene chiamato alla fine del file (riga 4730), non qui per evitare duplicati
      // Qui inizializziamo solo se non c'è form (caso creazione nuovo form)
      const fname = (formNameHidden?.value || '').trim();
      if (!fname) {
        // Se non esiste un form, inizializza con campi fissi
        fields = CAMPI_FISSI.map(f => ({ ...f }));
        tabState[DEFAULT_TAB_KEY] = { fields: fields, hasFixed: true };
        render_preview();
      }
      // Se c'è un form, restoreTabs() verrà chiamato alla fine (riga 4730) e caricherà tutto con UNA SOLA chiamata getForm
      wire_flow();
      await render_modules();
      await renderModulesPreview({ scope: 'struttura' });

      const qp = new URLSearchParams(location.search);
      const wantProps = qp.get('open');
      if (fname && wantProps && /^(props?|properties|wizard)$/i.test(wantProps)) {
        // Cambia alla scheda proprietà invece di aprire il wizard separatamente
        switchToTab(PROPERTIES_TAB_KEY);
      }
    }).catch((e) => {
      console.error(e);
      alert('impossibile inizializzare: api ajax non disponibile.');
    });

    // colore form (unica init)
    const colorInput = document.getElementById('pe-form-color');
    const hexInput = document.getElementById('pe-form-color-hex');
    const normHex = (v) => {
      v = (v || '').trim(); if (!v) return '#CCCCCC'; if (v[0] !== '#') v = '#' + v;
      return /^#([A-Fa-f0-9]{6})$/.test(v) ? v.toUpperCase() : '#CCCCCC';
    };
    const setBoth = (v) => { const n = normHex(v); if (colorInput) colorInput.value = n.toLowerCase(); if (hexInput) hexInput.value = n.toUpperCase(); };
    colorInput?.addEventListener('input', (e) => { if (hexInput) hexInput.value = (e.target.value || '#CCCCCC').toUpperCase(); });
    hexInput?.addEventListener('change', (e) => setBoth(e.target.value));

    // Inizializza sempre con un valore valido
    // Se non c'è già un valore impostato dal database, usa il default
    const currentValue = (colorInput?.value || hexInput?.value || '').trim();
    if (!currentValue || currentValue === '#CCCCCC' || currentValue === '#cccccc') {
      setBoth('#CCCCCC'); // Imposta il default se non c'è un valore valido
    }

    window.peWizardColor = () => normHex((hexInput?.value) || (colorInput?.value) || '#CCCCCC');
    window.peWizardSetColor = (v) => setBoth(v);
  });

  function isAllowedExternalDrag(e, scope = 'structure') {
    if (!e?.dataTransfer) return false;
    const types = Array.from(e.dataTransfer.types || []);
    if (types.includes('Files')) return false;

    // riconosci le nostre origini
    const isOurDrag =
      types.includes('application/x-pe-drag') ||
      types.includes('pe-origin/fields') ||
      types.includes('pe-origin/module') ||
      types.includes('module-key') ||
      types.includes('pe-origin/response');

    if (!isOurDrag) return false;

    // individua l'origine
    let origin = '';
    if (types.includes('pe-origin/fields')) origin = 'fields';
    else if (types.includes('pe-origin/module') || types.includes('module-key')) origin = 'module';
    else if (types.includes('pe-origin/response')) origin = 'response';

    // policy per scope
    if (scope === 'structure') {
      // nel canvas STRUTTURA accettiamo solo campi e moduli
      return origin === 'fields' || origin === 'module';
    }
    if (scope === 'response') {
      // nel canvas RISPOSTE accettiamo solo i blocchi azione
      return origin === 'response';
    }
    // fallback: prudente
    return false;
  }

  (function setupTabs() {
    const tabsBar = document.getElementById('pe-tabs');
    const editorStageEl = document.getElementById('editor-stage');
    const paletteEl = document.getElementById('field-dashboard');
    const responseStageEl = document.getElementById('response-stage');
    if (responseStageEl) responseStageEl.style.display = 'none';

    async function setActiveTab(nameOrKey) {
      // se ci arriva "struttura"/qualunque cosa dal pannello destro, forziamo il key reale
      const targetKey = tabs.find(t => t.key === nameOrKey) ? nameOrKey : DEFAULT_TAB_KEY;
      await switchToTab(targetKey);

      // La gestione della visualizzazione è ora gestita da switchToTab
      if (responseStageEl) responseStageEl.style.display = 'none';

      if (targetKey !== PROPERTIES_TAB_KEY) {
        renderModulesPreview({ scope: 'struttura' }).catch?.(() => { });
      }
    }


    /**
     * @deprecated persistTabs ora delega a save_structure() (unico percorso di salvataggio).
     * save_structure invia sia i campi che tabs_config al backend in un'unica transazione.
     */
    window.persistTabs = async function persistTabs() {
      console.debug('[persistTabs] delegating to save_structure()');
      return await save_structure();
    };

    function resetToCleanState() {
      // Azzera tutto per la creazione di una nuova pagina
      tabs = [
        { key: DEFAULT_TAB_KEY, label: 'Struttura', fixed: true },
        { key: 'esito', label: 'Esito', fixed: false }
      ];

      // Svuota e ricostruisce tabState (non può essere riassegnato perché è const)
      Object.keys(tabState).forEach(key => delete tabState[key]);

      // Scheda Struttura
      tabState[DEFAULT_TAB_KEY] = {
        fields: null,
        hasFixed: true,
        submitLabel: null,
        submitAction: 'submit',
        isMain: true,  // La prima scheda è sempre principale
        visibilityMode: 'all',
        unlockAfterSubmitPrev: false
      };

      // Scheda Esito (Chiusura)
      tabState['esito'] = {
        fields: [],
        hasFixed: false,
        submitLabel: 'Concludi Segnalazione',
        submitAction: 'submit',
        isMain: false,
        isClosureTab: true,
        schedaType: 'chiusura'
      };

      // niente scheda 'proprietà' tra i tab principali
      activeTabKey = DEFAULT_TAB_KEY;

      // Azzera anche i campi personalizzati, mantieni solo i fissi
      fields = CAMPI_FISSI.map(f => ({ ...f }));
    }

    async function restoreTabs() {
      const formName = (formNameHidden?.value || '').trim();

      // Se siamo in modalità creazione (nessun form_name), azzera tutto
      if (!formName) {
        resetToCleanState();
        return;
      }

      try {
        await wait_for_customfetch();

        // UNA SOLA CHIAMATA: getForm restituisce form + tabs + struttura_display_label
        const fullData = await window.customFetch('page_editor', 'getForm', {
          form_name: formName
        });

        if (!fullData?.success) {
          console.warn('Errore caricamento form:', fullData?.message);
          resetToCleanState();
          return;
        }

        // --- POPOLA METADATI FORM ---
        if (fullData.form) {
            const form = fullData.form;
            if (inputFormDesc) inputFormDesc.value = String(form.description || '');
            
            // Colore
            const col = String(form.color || '#CCCCCC');
            if (window.peWizardSetColor) {
                window.peWizardSetColor(col);
            } else {
                const ci = document.getElementById('pe-form-color');
                const hi = document.getElementById('pe-form-color-hex');
                if (ci) ci.value = col.toLowerCase();
                if (hi) hi.value = col.toUpperCase();
            }

            // Responsabile
            if (typeof initResponsabiliRows === 'function') {
                initResponsabiliRows(form.responsabile || '');
            }
            
            // Button text
            if (form.button_text) {
                formConfig.button_text = form.button_text.trim();
                const submitBtnInput = document.getElementById('pe-submit-btn-text');
                if (submitBtnInput) submitBtnInput.value = formConfig.button_text;
            }
        }

        // Estrai tabs da getForm (converti formato per page_editor.js se necessario)
        const tabsDataRaw = fullData.tabs || {};
        const tabsData = {};
        Object.keys(tabsDataRaw).forEach(tabLabel => {
          const tabData = tabsDataRaw[tabLabel];
          // Converti formato da field_name/field_type a name/type per page_editor.js
          const convertedFields = (Array.isArray(tabData) ? tabData : (tabData.fields || [])).map(field => {
            // Assicura che type sia sempre presente e valido
            let fieldType = field.field_type || field.type;
            // Controlla anche se è una stringa vuota o solo spazi
            if (!fieldType || typeof fieldType !== 'string' || !fieldType.trim()) {
              console.warn(`[restoreTabs] Campo senza tipo valido nella scheda "${tabLabel}": `, {
                field_name: field.field_name || field.name,
                field_type: field.field_type,
                type: field.type,
                field: field
              });
              // Se il tipo non è presente, prova a inferirlo dal nome o usa 'text' come default
              fieldType = 'text';
            } else {
              // Pulisci il tipo rimuovendo spazi extra
              fieldType = fieldType.trim();
            }

            return {
              name: field.field_name || field.name,
              type: fieldType,
              placeholder: field.field_placeholder || field.placeholder,
              options: field.field_options || field.options,
              required: field.required || 0,
              is_fixed: field.is_fixed || 0,
              sort_order: field.sort_order || 0,
              colspan: field.colspan || 1,
              parent_section_uid: field.parent_section_uid || null
            };
          });
          tabsData[tabLabel] = {
            fields: convertedFields,
            // tab_key stabile dal backend; fallback a slugify locale
            tab_key: tabData.tab_key || null,
            submit_label: tabData.submit_label || null,
            submit_action: tabData.submit_action || 'submit',
            is_main: tabData.is_main,
            visibility_mode: tabData.visibility_mode || 'all',
            unlock_after_submit_prev: tabData.unlock_after_submit_prev || 0,
            // WORKFLOW: Carica scheda_type se presente
            scheda_type: tabData.scheda_type || null,
            visibility_roles: tabData.visibility_roles || null,
            edit_roles: tabData.edit_roles || null,
            visibility_condition: tabData.visibility_condition || null,
            redirect_after_submit: tabData.redirect_after_submit || false,
            // CLOSURE TAB: compatibilità naming
            is_closure_tab: (tabData.is_closure_tab === 1 || tabData.is_closure_tab === true) ? 1
              : (tabData.isClosureTab === 1 || tabData.isClosureTab === true) ? 1 : 0
          };
        });

        const strutturaDisplayLabel = fullData.struttura_display_label || null;

        // Debug: mostra cosa stiamo caricando
        console.log('📥 Caricamento schede dal DB:', JSON.stringify(tabsData, null, 2));
        console.log('🏷️ Display label Struttura:', strutturaDisplayLabel);

        // Conserva le schede fisse principali
        const fixedTabs = tabs.filter(t => t.fixed && t.key !== PROPERTIES_TAB_KEY);
        tabs = [];
        const seen = new Set();

        // Aggiungi prima le schede fisse
        fixedTabs.forEach(t => {
          seen.add(t.key);
          tabs.push(t);
        });

        // Aggiungi le schede dal database
        Object.keys(tabsData).forEach(tabLabel => {
          if (tabLabel === 'Struttura') return; // La scheda Struttura è già gestita

          const tabData = tabsData[tabLabel];
          // Usa tab_key dal backend; fallback a slugifyTabName per retrocompat
          const key = tabData.tab_key || slugifyTabName(tabLabel);
          if (seen.has(key)) {
            // Se esiste già (tab fissa), aggiorna comunque lo stato dal DB
            const existingTab = tabs.find(t => t.key === key);
            if (existingTab && tabLabel && existingTab.label !== tabLabel) {
              existingTab.label = tabLabel;
            }

            const rawFieldsSeen = (Array.isArray(tabData) ? tabData : (tabData.fields || []));
            const fieldsWithHierarchy = buildSectionHierarchy(rawFieldsSeen);

            let visibilityRoles = tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'];
            let editRoles = tabData.edit_roles || tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'];
            let visibilityCondition = tabData.visibility_condition || { type: 'always' };

            if (visibilityCondition.type === 'after_step_saved') {
              visibilityCondition = { type: 'after_step_submitted', depends_on: visibilityCondition.depends_on };
            }

            if (visibilityRoles.includes('responsabile') && !visibilityRoles.includes('assegnatario')) {
              visibilityRoles = [...visibilityRoles, 'assegnatario'];
            }
            if (editRoles.includes('responsabile') && !editRoles.includes('assegnatario')) {
              editRoles = [...editRoles, 'assegnatario'];
            }

            let schedaType = 'utente';
            if (tabData.scheda_type) {
              schedaType = tabData.scheda_type;
            } else {
              const hasUtente = editRoles.includes('utente');
              const hasResponsabile = editRoles.includes('responsabile') || editRoles.includes('assegnatario');
              if (!hasUtente && hasResponsabile) {
                schedaType = 'responsabile';
              }
            }

            tabState[key] = {
              fields: fieldsWithHierarchy,
              hasFixed: false,
              submitLabel: (tabData.submit_label || null),
              submitAction: (tabData.submit_action || 'submit'),
              isMain: (tabData.is_main === 1 || tabData.is_main === true),
              isClosureTab: (tabData.is_closure_tab === 1 || tabData.is_closure_tab === true),
              visibilityMode: (tabData.visibility_mode || 'all'),
              unlockAfterSubmitPrev: (tabData.unlock_after_submit_prev === 1 || tabData.unlock_after_submit_prev === true),
              visibilityRoles: visibilityRoles,
              editRoles: editRoles,
              visibilityCondition: visibilityCondition,
              redirectAfterSubmit: tabData.redirect_after_submit || false,
              schedaType: schedaType
            };

            seen.add(key);
            return;
          }

          // BUGFIX: Costruisci la gerarchia sezione-children
          const rawFields = (Array.isArray(tabData) ? tabData : (tabData.fields || []));
          const fieldsWithHierarchy = buildSectionHierarchy(rawFields);

          tabs.push({ key, label: tabLabel, fixed: false });

          // FASE 4 - MVP: Mappa meta vecchi durante il caricamento
          let visibilityRoles = tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'];
          let editRoles = tabData.edit_roles || tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'];
          let visibilityCondition = tabData.visibility_condition || { type: 'always' };

          // Mappa "after_step_saved" (obsoleto) a "after_step_submitted"
          if (visibilityCondition.type === 'after_step_saved') {
            visibilityCondition = { type: 'after_step_submitted', depends_on: visibilityCondition.depends_on };
          }

          // Assicura che "assegnatario" sia presente se "responsabile" è presente
          if (visibilityRoles.includes('responsabile') && !visibilityRoles.includes('assegnatario')) {
            visibilityRoles = [...visibilityRoles, 'assegnatario'];
          }
          if (editRoles.includes('responsabile') && !editRoles.includes('assegnatario')) {
            editRoles = [...editRoles, 'assegnatario'];
          }

          // WORKFLOW: Determina schedaType da scheda_type esplicito o calcolalo da editRoles
          let schedaType = 'utente'; // default
          if (tabData.scheda_type) {
            schedaType = tabData.scheda_type;
          } else {
            // Retrocompatibilità: calcola da editRoles
            const hasUtente = editRoles.includes('utente');
            const hasResponsabile = editRoles.includes('responsabile') || editRoles.includes('assegnatario');
            if (!hasUtente && hasResponsabile) {
              schedaType = 'responsabile';
            }
          }

          tabState[key] = {
            fields: fieldsWithHierarchy,
            hasFixed: false,
            submitLabel: (tabData.submit_label || null),
            submitAction: (tabData.submit_action || 'submit'),
            // Carica le nuove proprietà delle schede (con default)
            isMain: (tabData.is_main === 1 || tabData.is_main === true),
            isClosureTab: (tabData.isClosureTab === 1 || tabData.isClosureTab === true),
            visibilityMode: (tabData.visibility_mode || 'all'),
            unlockAfterSubmitPrev: (tabData.unlock_after_submit_prev === 1 || tabData.unlock_after_submit_prev === true),
            // FASE 4 - MVP: Carica proprietà workflow con mappatura meta vecchi
            visibilityRoles: visibilityRoles,
            editRoles: editRoles,
            visibilityCondition: visibilityCondition,
            redirectAfterSubmit: tabData.redirect_after_submit || false,
            // WORKFLOW: Carica schedaType esplicito
            schedaType: schedaType
          };

          console.log(`[restoreTabs] Scheda "${tabLabel}" caricata: `, {
            isMain: tabState[key].isMain,
            isClosureTab: tabState[key].isClosureTab,
            visibilityMode: tabState[key].visibilityMode,
            unlockAfterSubmitPrev: tabState[key].unlockAfterSubmitPrev,
            tabData: tabData
          });

          seen.add(key);
        });

        if (!tabs.length) tabs = fixedTabs;

        // Carica i campi della scheda Struttura
        if (tabsData['Struttura']) {
          const strutturaData = tabsData['Struttura'];
          // BUGFIX: Costruisci la gerarchia sezione-children per la scheda Struttura
          const rawFields = (Array.isArray(strutturaData) ? strutturaData : (strutturaData.fields || []));
          const customFields = buildSectionHierarchy(rawFields);

          // Aggiungi i campi fissi all'inizio
          const allFields = [...CAMPI_FISSI.map(f => ({ ...f })), ...customFields];

          tabState[DEFAULT_TAB_KEY] = {
            fields: allFields,
            hasFixed: true,
            submitLabel: (strutturaData.submit_label || null),
            submitAction: (strutturaData.submit_action || 'submit'),
            // Carica le nuove proprietà delle schede (con default)
            isMain: (strutturaData.is_main === 1 || strutturaData.is_main === true || strutturaData.is_main === undefined),
            visibilityMode: (strutturaData.visibility_mode || 'all'),
            unlockAfterSubmitPrev: false  // La prima scheda non può avere unlockAfterSubmitPrev
          };

          // Se c'è un display_label personalizzato, aggiorna il label della tab fissa
          if (strutturaDisplayLabel) {
            const fixedTab = tabs.find(t => t.key === DEFAULT_TAB_KEY);
            if (fixedTab) {
              fixedTab.label = strutturaDisplayLabel;
              console.log('✅ Scheda Struttura rinominata in:', strutturaDisplayLabel);
            }
          }
        } else {
          // Se non ci sono schede salvate, inizializza con i campi fissi
          tabState[DEFAULT_TAB_KEY] = {
            fields: CAMPI_FISSI.map(f => ({ ...f })),
            hasFixed: true,
            submitLabel: null,
            submitAction: 'submit',
            // Default per la prima scheda
            isMain: true,
            visibilityMode: 'all',
            unlockAfterSubmitPrev: false
          };
        }

        activeTabKey = DEFAULT_TAB_KEY;

        // Aggiorna i campi globali con quelli della scheda attiva
        const currentTabFields = tabState[activeTabKey]?.fields || [];
        fields = currentTabFields;

        // Aggiorna la barra delle schede
        if (typeof window.buildTabsBar === 'function') {
          window.buildTabsBar();
        }

        // Renderizza la preview con i dati caricati
        render_preview();

        // Aggiorna il pannello "Schede" se è aperto per riflettere i valori caricati
        if (typeof window.updateTabsPanelIfOpen === 'function') {
          window.updateTabsPanelIfOpen();
        }

      } catch (e) {
        console.warn('Errore restoreTabs:', e);
        resetToCleanState();
      }
    }

    // Rendi restoreTabs accessibile globalmente (necessario per i bottoni di salvataggio)
    window.restoreTabs = restoreTabs;

    // Menu contestuale per le schede
    function showTabContextMenu(e, tab, tabButton) {
      const menu = document.getElementById("custom-context-menu");
      if (!menu) return;

      menu.innerHTML = "";

      // Voce "Modifica nome"
      const modificaLi = document.createElement("li");
      modificaLi.textContent = "Modifica nome";
      modificaLi.addEventListener("click", () => {
        startInlineEdit(tabButton);
        menu.classList.add("hidden");
      });
      menu.appendChild(modificaLi);

      // Voce "Elimina" - DISABILITATA per la scheda fissa (Struttura)
      const eliminaLi = document.createElement("li");
      const isFixedTab = (tab.key === DEFAULT_TAB_KEY);

      if (isFixedTab) {
        eliminaLi.textContent = "Elimina (scheda obbligatoria)";
        eliminaLi.style.color = "#999";
        eliminaLi.style.cursor = "not-allowed";
        eliminaLi.style.opacity = "0.5";
        eliminaLi.title = "La scheda principale non può essere eliminata";
        // Nessun event listener - non è cliccabile
      } else {
        eliminaLi.textContent = "Elimina";
        eliminaLi.style.color = "#dc3545";
        eliminaLi.style.cursor = "pointer";
        eliminaLi.addEventListener("click", () => {
          showConfirm(`Sei sicuro di voler eliminare la scheda "${tab.label}" ? `, () => {
            deleteTab(tab.key);
          });
          menu.classList.add("hidden");
        });
      }

      menu.appendChild(eliminaLi);

      // Posiziona il menu
      menu.style.top = `${e.pageY} px`;
      menu.style.left = `${e.pageX} px`;
      menu.classList.remove("hidden");
    }

    // Elimina una scheda
    function deleteTab(tabKey) {
      if (tabKey === DEFAULT_TAB_KEY) return; // Non eliminare la scheda Struttura

      // Rimuovi la scheda dall'array tabs
      const tabIndex = tabs.findIndex(t => t.key === tabKey);
      if (tabIndex === -1) return;

      tabs.splice(tabIndex, 1);

      // Rimuovi dal tabState
      delete tabState[tabKey];

      // Se era la scheda attiva, passa alla Struttura
      if (activeTabKey === tabKey) {
        activeTabKey = DEFAULT_TAB_KEY;
        // Per la scheda Struttura, carica tutti i campi (fissi + personalizzati)
        fields = tabState[DEFAULT_TAB_KEY]?.fields || CAMPI_FISSI.map(f => ({ ...f }));
      }

      // Ricostruisci la UI
      buildTabsBar();
      render_preview();

      // Non salvare automaticamente: verrà salvato tutto con save_structure()
      // try { persistTabs(); } catch (_) { }

      showToast(`Scheda eliminata`, 'success');
    }

    window.buildTabsBar = function buildTabsBar() {
      if (!tabsBar) return;
      tabsBar.innerHTML = '';

      // wrapper sinistro (schede + '+')
      const leftWrap = document.createElement('div');
      leftWrap.className = 'tabs-left';

      const ul = document.createElement('div');
      ul.className = 'tabs-list';

      tabs.forEach((t, index) => {
        const b = document.createElement('button');
        b.className = 'tab-link' + (t.key === activeTabKey ? ' active' : '');
        b.setAttribute('data-tab-key', t.key);
        b.setAttribute('data-tab-index', index);
        b.title = t.label;                 // utile quando viene troncata
        b.textContent = t.label || 'Nuova scheda';

        // Aggiungi drag-and-drop per riordinare le schede
        b.draggable = true;

        b.addEventListener('dragstart', (e) => {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', index.toString());
          b.classList.add('dragging');
          console.log('🎯 Drag started for tab:', t.label, 'at index:', index);
        });

        b.addEventListener('dragend', () => {
          b.classList.remove('dragging');
        });

        b.addEventListener('dragover', (e) => {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';

          // Rimuovi evidenziazione da tutti gli altri elementi
          ul.querySelectorAll('.tab-link').forEach(tab => {
            if (tab !== b) tab.classList.remove('drag-over', 'valid-drop-target');
          });

          b.classList.add('drag-over');

          // Mostra un'anteprima visiva della posizione di inserimento
          const fromIndex = parseInt(e.dataTransfer.getData('text/plain') || '-1');
          if (fromIndex !== -1 && fromIndex !== index) {
            // Aggiungi una classe speciale per indicare che è una posizione valida
            b.classList.add('valid-drop-target');
            console.log('✅ Valid drop target:', t.label, 'from index:', fromIndex, 'to index:', index);
          }
        });

        b.addEventListener('dragleave', (e) => {
          // Verifica che stiamo effettivamente lasciando l'elemento (non un suo figlio)
          if (!b.contains(e.relatedTarget)) {
            b.classList.remove('drag-over', 'valid-drop-target');
          }
        });

        b.addEventListener('drop', (e) => {
          e.preventDefault();

          // Rimuovi tutte le classi di evidenziazione
          ul.querySelectorAll('.tab-link').forEach(tab => {
            tab.classList.remove('drag-over', 'valid-drop-target');
          });

          const fromIndex = parseInt(e.dataTransfer.getData('text/plain'));
          const toIndex = index;

          if (fromIndex !== toIndex && !isNaN(fromIndex)) {
            // Riordina l'array tabs
            const [movedTab] = tabs.splice(fromIndex, 1);
            tabs.splice(toIndex, 0, movedTab);

            // Ricostruisci la UI
            buildTabsBar();

            // Mostra un feedback visivo
            if (typeof showToast === 'function') {
              showToast('Scheda riordinata - Salva per confermare', 'info');
            }

            // Marca come modificato (il salvataggio avverrà con save_structure())
            if (typeof markAsModified === 'function') markAsModified();
          }
        });

        // Click normale per cambiare scheda (solo se non stiamo editando)
        b.addEventListener('click', (e) => {
          // Se stiamo editando il nome della scheda, ignora il click
          if (b.classList.contains('editing')) return;

          e.preventDefault();
          e.stopPropagation();
          setActiveTab(t.key);
          buildTabsBar();
        });

        // Click destro per menu contestuale (ora anche per schede fisse)
        b.addEventListener('contextmenu', (e) => {
          e.preventDefault();
          e.stopPropagation();
          showTabContextMenu(e, t, b);
        });

        ul.appendChild(b);
      });

      // pulsante '+'
      const add = document.createElement('button');
      add.className = 'tab-link tab-add';
      add.textContent = '+';
      add.title = 'Aggiungi scheda';
      add.addEventListener('click', async () => {
        if (tabs.length >= MAX_TABS_TOTAL) {
          showToast?.(`Puoi avere al massimo ${MAX_TABS_TOTAL} schede.`, 'info');
          return;
        }
        // Crea direttamente una scheda vuota
        await createNewTab('');
        // Non salvare automaticamente: verrà salvato tutto con save_structure()
        // try { persistTabs(); } catch (_) { }
        buildTabsBar();
        // Attiva l'editing inline del nome
        setTimeout(() => {
          const newTabBtn = tabsBar.querySelector('.tab-link[data-tab-key]:last-child');
          if (newTabBtn) startInlineEdit(newTabBtn);
        }, 0);
      });
      if (tabs.length >= MAX_TABS_TOTAL) add.style.display = 'none';

      // pulsante Proprietà (ingranaggio accanto a '+')
      const propsBtn = document.createElement('button');
      propsBtn.className = 'tab-link tab-add';
      propsBtn.innerHTML = '⚙︎';
      propsBtn.title = 'Proprietà pagina (disabilitato)';
      propsBtn.disabled = true;
      propsBtn.style.opacity = '0.5';
      propsBtn.style.cursor = 'not-allowed';

      // monta sinistra (ordine IMPORTANTE: prima lista, poi '+', poi proprietà)
      leftWrap.appendChild(ul);
      leftWrap.appendChild(add);
      leftWrap.appendChild(propsBtn);

      // svuota e monta tutta la barra
      tabsBar.innerHTML = '';
      tabsBar.appendChild(leftWrap);

      // handlers click standard
      // Event listeners gestiti direttamente sui singoli tab
    }

    // esponi per pannello destro: qualunque chiamata manda alla scheda principale
    window.peSetMainTab = (tabKey) => setActiveTab(tabKey || DEFAULT_TAB_KEY);

    // Inizializza i tab (restoreTabs gestisce già il caso creazione/modifica)
    restoreTabs().then(async () => {
      // Carica il colore del form prima di aprire il wizard
      await loadAndSetFormColor();
      buildTabsBar();
      // Apri di default il pannello Proprietà (senza mostrare prima i Campi)
      try { await setWizardTab(); } catch (_) { }
    });
  })();

  // ==================== ANTEPRIMA FORM ====================
  document.getElementById('pe-preview-btn')?.addEventListener('click', function () {
    const currentFormName = formName || inputFormName?.value || 'Nuovo Form';
    const currentFormDesc = inputFormDesc?.value || '';
    const currentFormColor = document.getElementById('pe-form-color')?.value || '#667eea';
    // Usa SOLO la scheda attiva: se submit_label non c'è, fallback al default form o "Salva"
    const getSubmitText = () => {
      // Usa SOLO la scheda attiva: se submit_label non c'è, fallback al default form o "Salva"
      if (typeof activeTabKey !== 'undefined') {
        const t = (tabState[activeTabKey]?.submitLabel || '').trim();
        if (t) return t;
      }
      return (formConfig.button_text || 'Salva').trim() || 'Salva';
    };
    const submitText = getSubmitText();

    // Leggi i valori meta dal form-meta-block dell'editor
    const metaTitolo = (document.getElementById('pe-meta-titolo')?.value || '').trim();
    const metaDescrizione = (document.getElementById('pe-meta-descrizione')?.value || '').trim();
    const metaDeadline = (document.getElementById('pe-meta-deadline')?.value || '').trim();
    const metaPriority = (document.getElementById('pe-meta-priority')?.value || 'Media').trim();
    // Responsabile: leggi dal form-meta-block o usa il valore salvato
    const metaResponsabileEl = document.getElementById('pe-meta-responsabile-display');
    // Usa innerHTML per mantenere l'immagine se presente
    const metaResponsabile = metaResponsabileEl ? metaResponsabileEl.innerHTML : '<span style="color: #999;">—</span>';

    // Sincronizza al volo i campi della scheda attiva prima di costruire l'anteprima
    try {
      if (typeof activeTabKey !== 'undefined') {
        tabState[activeTabKey] = tabState[activeTabKey] || { fields: [] };
        if (Array.isArray(fields)) {
          tabState[activeTabKey].fields = fields.slice();
        }
      }
    } catch (_) { }

    // Costruisci markup fedele della pagina, limitandoci alla sola .pagina-foglio
    const escape = (s) => window.escapeHtml ? window.escapeHtml(String(s)) : String(s);

    // Normalizza i campi per supportare sia lo schema editor (type,label,children,...) che quello persistito (field_*)
    const beautifyLabel = (str) => {
      if (!str) return 'Campo';
      // Convert snake_case/kebab-case to Title Case
      return str
        .replace(/_/g, ' ')  // Replace underscores with spaces
        .replace(/-/g, ' ')  // Replace hyphens with spaces
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');
    };

    function normalizeField(f) {
      const type = String((f.type ?? f.field_type ?? 'text') || 'text').toLowerCase();
      // Use explicit label if present, otherwise beautify the name
      const rawLabel = f.label ?? f.field_label;
      const rawName = f.name ?? f.field_name ?? '';
      const label = rawLabel || (rawName ? beautifyLabel(rawName) : 'Campo');
      const name = rawName;
      const placeholder = f.placeholder ?? f.field_placeholder ?? '';
      const options = f.options ?? f.field_options ?? [];
      const colspan = Number(f.colspan) === 2 ? 2 : 1;
      const children = Array.isArray(f.children) ? f.children : [];
      let datasource = f.datasource;
      if (!datasource && type === 'dbselect') {
        if (typeof f.field_options === 'string') {
          try { datasource = JSON.parse(f.field_options) || {}; } catch { datasource = {}; }
        } else if (f.field_options && typeof f.field_options === 'object') {
          datasource = f.field_options;
        }
      }
      const multiple = type === 'dbselect' ? boolish(f.multiple ?? datasource?.multiple) : false;
      const allow_custom = f.allow_custom ?? f.allowCustom ?? null; // Pass raw value
      return { type, label, name, placeholder, options, colspan, children, multiple, allow_custom };
    }

    const normalized = Array.isArray(fields) ? fields.map(normalizeField) : [];

    // Filtra i meta campi (non devono apparire nella grid)
    const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
    const filteredFields = normalized.filter(f => {
      const fieldName = (f.name || '').toLowerCase();
      return !fissiKeys.includes(fieldName);
    });

    // Costruisci schede: tab "Generale" per campi non-sezione + una scheda per ogni sezione (editor: type==='section')
    const sections = filteredFields.filter(f => f.type === 'section');
    const plainFields = filteredFields.filter(f => f.type !== 'section');

    function renderFieldBlock(field) {
      const { label, placeholder, colspan, type: ftype } = field;
      let control = '';
      if (ftype === 'textarea') {
        control = `<textarea placeholder="${escape(placeholder)}"></textarea>`;
      } else if (ftype === 'select' || ftype === 'dbselect') {
        let opts = [];
        let dbConfig = null;

        if (ftype === 'dbselect') {
          // Parse config for real fetch
          try {
            if (typeof field.options === 'string') {
              dbConfig = JSON.parse(field.options);
            } else if (typeof field.options === 'object') {
              dbConfig = field.options;
            }
          } catch (e) { }

          // Initial placeholder while loading
          opts = ['Caricamento dati...'];
        } else if (Array.isArray(field.options)) {
          opts = field.options;
        } else if (typeof field.options === 'string') {
          try { const parsed = JSON.parse(field.options || '[]'); if (Array.isArray(parsed)) opts = parsed; } catch { opts = []; }
        }

        const isMultiple = boolish(field.multiple);

        // Custom values support (Altro...)
        // User requested: Visible ONLY if checked. 
        // We use boolish() so undefined/null/false/"0" becomes FALSE.
        let allowCustom = boolish(field.allow_custom);

        // Force disable if multiple (dbselect specific)
        if (ftype === 'dbselect' && isMultiple) allowCustom = false;

        let customOptHtml = '';
        if (allowCustom) {
          customOptHtml = `<option disabled style="font-style:italic; color:#666;">➕ Altro (inserimento libero)...</option>`;
        }

        const optionsHtml = opts.map(o => `<option>${escape(o)}</option>`).join('');
        const placeholderOpt = `<option${isMultiple ? ' value="" disabled' : ''}>${escape(placeholder || '-- Seleziona --')}</option>`;
        const summaryHtml = isMultiple ? `<div class="dbselect-multi-summary is-empty"><span>Nessun elemento selezionato</span></div>` : '';

        const dataAttr = dbConfig ? ` data-db-config='${JSON.stringify(dbConfig).replace(/'/g, "&#39;")}' class="preview-dbselect"` : '';

        control = `<div class="select-preview"><select${isMultiple ? ' multiple' : ''}${dataAttr}>${placeholderOpt}${optionsHtml}${customOptHtml}</select>${summaryHtml}</div>`;
      } else if (ftype === 'radio') {
        const opts = typeof field.options === 'string' ? (JSON.parse(field.options || '[]')) : (field.options || []);
        const nameAttr = `prev_${Math.random().toString(36).slice(2)}`;
        const radios = (Array.isArray(opts) && opts.length ? opts : ['Opzione 1', 'Opzione 2']).map((o, i) => `
        <label class="choice-inline"><input type="radio" name="${nameAttr}"> <span>${escape(o)}</span></label>
      `).join('');
        control = `<div class="choice-group radio-group">${radios}</div>`;
      } else if (ftype === 'checkbox') {
        const opts = typeof field.options === 'string' ? (JSON.parse(field.options || '[]')) : (field.options || []);
        if (Array.isArray(opts) && opts.length > 1) {
          const boxes = opts.map(o => `
        <label class="choice-inline"><input type="checkbox"> <span>${escape(o)}</span></label>
      `).join('');
          control = `<div class="choice-group checkbox-group">${boxes}</div>`;
        } else {
          control = `<label class="choice-inline"><input type="checkbox"> <span>${escape(opts[0] || label)}</span></label>`;
        }
      } else if (ftype === 'file') {
        control = `<input type="file" />`;
      } else if (ftype === 'date') {
        control = `<input type="date" placeholder="${escape(placeholder)}" />`;
      } else if (ftype === 'number') {
        control = `<input type="number" placeholder="${escape(placeholder)}" />`;
      } else if (ftype === 'email') {
        control = `<input type="email" placeholder="${escape(placeholder)}" />`;
      } else if (ftype === 'tel' || ftype === 'phone') {
        control = `<input type="tel" placeholder="${escape(placeholder)}" />`;
      } else {
        control = `<input type="text" placeholder="${escape(placeholder)}" />`;
      }
      return `<div class="form-group" style="grid-column: span ${colspan};"><label>${escape(label)}</label>${control}</div>`;
    }

    function renderFieldsGrid(list) {
      if (!Array.isArray(list) || !list.length) return '<p style="text-align:center; color:#999; padding:40px;">Nessun campo aggiunto ancora.</p>';
      return `<div class="view-form-grid">${list.map(renderFieldBlock).join('')}</div>`;
    }

    // Fieldset renderer per sezioni
    function renderSectionFieldset(sec, idx) {
      const children = (Array.isArray(sec.children) ? sec.children.map(normalizeField) : []);
      // Filtra anche i meta campi dalle sezioni
      const filteredChildren = children.filter(f => {
        const fieldName = (f.name || '').toLowerCase();
        return !fissiKeys.includes(fieldName);
      });
      const label = sec.label || sec.name || `Sezione ${idx + 1} `;
      return `
        <fieldset class="view-form-group form-section span-2">
          <legend class="form-section-legend">${escape(label)}</legend>
          <div class="section-grid">${filteredChildren.length ? filteredChildren.map(renderFieldBlock).join('') : `<div class=\"input-static\" style=\"grid-column:1 / -1; color:#999\">Nessun campo nella sezione.</div>`}</div>
        </fieldset>`;
    }

    // Genera form-meta-block HTML (stessa struttura di view_form.php)
    const metaBlockHtml = `
        <div class="form-meta-block">
        <!--1. Titolo-->
        <div class="meta-row">
          <span class="label">Titolo:</span>
          <span class="meta-value">
            <input type="text" class="input-title" placeholder="Inserisci il titolo" value="${escape(metaTitolo)}" disabled>
          </span>
        </div>
        
        <!--2. Descrizione-->
        <div class="meta-row">
          <span class="label">Descrizione:</span>
          <span class="meta-value">
            <textarea placeholder="Inserisci la descrizione..." disabled>${escape(metaDescrizione)}</textarea>
          </span>
        </div>
        
        <!--3. Opening Date | Deadline-->
        <div class="meta-row meta-row-split">
          <span class="label-split">Data di apertura:</span>
          <span class="value-split">
            ${new Date().toLocaleDateString('it-IT')}
          </span>
          <span class="label-split">Data di scadenza:</span>
          <span class="value-split">
            <input type="date" value="${escape(metaDeadline)}" disabled>
          </span>
        </div>

        <!--4. Created By | Assigned To-->
        <div class="meta-row meta-row-split">
          <span class="label-split">Creato da:</span>
          <span class="value-split">
             —
          </span>
          <span class="label-split">Assegnato a:</span>
          <span class="value-split">
            ${metaResponsabile}
          </span>
        </div>
        
        <!--5. Priority | Status-->
        <div class="meta-row meta-row-split">
          <span class="label-split">Priorità:</span>
          <span class="value-split">
            <select disabled>
              <option value="Bassa" ${metaPriority === 'Bassa' ? 'selected' : ''}>Bassa</option>
              <option value="Media" ${metaPriority === 'Media' ? 'selected' : ''}>Media</option>
              <option value="Alta" ${metaPriority === 'Alta' ? 'selected' : ''}>Alta</option>
            </select>
          </span>
          <span class="label-split">Stato:</span>
          <span class="value-split">
            <select disabled>
              <option selected>Aperta</option>
              <option>In corso</option>
              <option>Chiusa</option>
            </select>
          </span>
        </div>
      </div>`;

    // Genera form-meta-block esito HTML (stessa struttura, modalità esito)
    const esitoMetaBlockHtml = `
        <div class="form-meta-block" style="border-left: 4px solid #3498db; margin-bottom: 16px;">
          ${buildMetaBlockContent('esito')}
      </div>`;

    // Costruisci le schede dinamicamente in base all'editor corrente
    const computedTabs = (Array.isArray(tabs) ? tabs : []).filter(t => t && t.key && t.key !== PROPERTIES_TAB_KEY).map((t, i) => {
      let tfRaw = (tabState[t.key]?.fields || []);
      // Fallback: se la scheda è quella attiva e non ha campi in stato, usa i campi correnti
      if ((!Array.isArray(tfRaw) || !tfRaw.length) && typeof activeTabKey !== 'undefined' && t.key === activeTabKey && Array.isArray(fields)) {
        tfRaw = fields;
      }
      const tf = (tfRaw || []).map(normalizeField);
      // Filtra i meta campi anche per ogni scheda
      const tfFiltered = tf.filter(f => {
        const fieldName = (f.name || '').toLowerCase();
        return !fissiKeys.includes(fieldName);
      });
      // Renderizza rispettando l'ordine originale, raggruppando i campi plain consecutivi
      let content = '';
      let currentPlainGroup = [];
      tfFiltered.forEach((f, idx) => {
        if (f.type === 'section') {
          // Prima chiudi il gruppo plain corrente
          if (currentPlainGroup.length > 0) {
            content += renderFieldsGrid(currentPlainGroup);
            currentPlainGroup = [];
          }
          // Poi aggiungi la sezione
          content += renderSectionFieldset(f, idx);
        } else {
          currentPlainGroup.push(f);
        }
      });
      // Chiudi eventuali campi rimasti
      if (currentPlainGroup.length > 0) {
        content += renderFieldsGrid(currentPlainGroup);
      }

      // RENDERIZZA META-BLOCK ESITO + BLOCCO CHIUSURA IN ANTEPRIMA SE PRESENTE
      // RENDERIZZA META-BLOCK ESITO + BLOCCO CHIUSURA IN ANTEPRIMA SE PRESENTE
      if (tabState[t.key]?.isClosureTab) {
        // Blocco Esito (Dati Responsabile) in alto
        content = esitoMetaBlockHtml + content;

        // Blocco Chiusura (Esito Segnalazione) in basso
        content += `
        <div class="form-meta-block closure-fixed-block" style="grid-column: 1 / -1; border-left: 4px solid #e74c3c; margin-bottom: 20px;">
                <div class="form-meta-header">
                    Esito della Segnalazione
                </div>
                <div class="meta-row">
                    <span class="label">Esito <span style="color:#e74c3c">*</span>:</span>
                    <span class="meta-value">
                        <select class="input-light" style="font-weight:500;">
                            <option value="">-- Seleziona Esito --</option>
                            <option value="accettata" style="color:#27ae60; font-weight:bold;">Accettata</option>
                            <option value="in_valutazione" style="color:#f39c12; font-weight:bold;">In Valutazione</option>
                            <option value="rifiutata" style="color:#c0392b; font-weight:bold;">Rifiutata</option>
                        </select>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="label">Note Esito:</span>
                    <span class="meta-value">
                        <textarea class="input-light" style="min-height:80px;" placeholder="Inserisci eventuali note..."></textarea>
                    </span>
                </div>
                 <div class="meta-row">
                    <span class="label">Data Chiusura:</span>
                    <span class="meta-value">
                         <input type="date" class="input-light" style="font-weight:500;">
                    </span>
                </div>
            </div>
        `;
      }

      // === RENDERIZZA I METADATI STANDARD NON PIÙ QUI MA TRAMITE CONTAINER ESTERNO ===
      // Se vuoi che appaiano dentro il tab, decomenta sotto.
      // Ma per ora li abbiamo messi fuori come richiesto per il layout.
      /*
      if (t.key === DEFAULT_TAB_KEY) {
        content = metaBlockHtml + content;
      }
      */
      return { key: t.key, label: t.label || `Scheda ${i + 1} `, content };
    });
    const firstKey = computedTabs[0]?.key || 'generale';
    let tabsBarHtml = `<div class="form-tabs-bar" id="form-tabs-bar">${computedTabs.map((t, i) => `<button type="button" class="form-tab ${i === 0 ? 'active' : ''}" data-tab="${escape(t.key)}">${escape(t.label)}</button>`).join('')}</div>`;
    let tabsContentHtml = `<div class="form-tabs-content">${computedTabs.map((t, i) => `<div class="form-tab-content ${i === 0 ? 'active' : ''}" data-tab="${escape(t.key)}">${t.content}</div>`).join('')}</div>`;

    const paginaFoglioHtml = `
        <div class="pagina-foglio">
        <div class="view-form-header" style="--form-color:${escape(currentFormColor)}">
          <div class="form-header-row">
            <h1 id="form-title">${escape(currentFormName)}</h1>
            <span class="protocollo-tag" id="protocollo-viewer" data-tooltip="protocollo" style="display:none;">-</span>
          </div>
          ${currentFormDesc ? `<p style="margin: 6px 0 0; color:#444;">${escape(currentFormDesc)}</p>` : ''}
          <hr class="form-title-divider" />
        </div>
        <div class="view-form-content">
          ${tabsBarHtml}
          <!-- ID aggiunto per controllo visibilità dinamica in anteprima -->
          <div id="preview-standard-meta-container">
            ${metaBlockHtml}
          </div>
          <div class="form-tabs-container" style="margin-top: 20px;">
            <div>
              <form>
                ${tabsContentHtml}
                <button type="button" class="button" id="form-submit-btn" disabled>${escape(submitText)}</button>
              </form>
            </div>
          </div>
        </div>
      </div>`;

    const modal = document.getElementById('preview-modal');
    if (!modal) return;
    modal.innerHTML = paginaFoglioHtml;

    // Centra il modale e usa solo la dimensione naturale della pagina-foglio
    // Il contenitore del modale è la pagina-foglio stessa; nessun wrapper

    window.toggleModal('preview-modal', 'open');

    // Piccola interazione per tab nella sola anteprima (switch client-side)
    const tabBtns = modal.querySelectorAll('.form-tabs-bar .form-tab');
    if (tabBtns && tabBtns.length) {
      tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          const key = btn.getAttribute('data-tab');
          modal.querySelectorAll('.form-tabs-bar .form-tab').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          modal.querySelectorAll('.form-tabs-content .form-tab-content').forEach(p => {
            if (p.getAttribute('data-tab') === key) p.classList.add('active'); else p.classList.remove('active');
          });

          // Gestione visibilità meta standard in anteprima
          const previewMeta = modal.querySelector('#preview-standard-meta-container');
          if (previewMeta) {
            const isStruttura = (key === DEFAULT_TAB_KEY || key === 'struttura' || key === 'generale');
            previewMeta.style.display = isStruttura ? 'block' : 'none';
          }
        });
      });
      // Trigger click per init
      if (tabBtns[0]) tabBtns[0].click();
    }

    // --- POPOLAMENTO REALE DBSELECT ---
    // Cerca i select marchiati per il caricamento
    const pendingDbSelects = modal.querySelectorAll('select.preview-dbselect[data-db-config]');
    pendingDbSelects.forEach(async (select) => {
      try {
        const configStr = select.getAttribute('data-db-config');
        if (!configStr) return;
        const config = JSON.parse(configStr);

        // Determina parametri payload
        const table = config.table || config.Table || config.tabella;
        const valueCol = config.valueCol || config.valuecol || config.value_col || config.value || config.val;
        const labelCol = config.labelCol || config.labelcol || config.label_col || config.label || config.text || valueCol;

        if (table && valueCol) {
          if (typeof window.customFetch === 'function') {
            const resp = await window.customFetch('datasource', 'getOptions', {
              table,
              valueCol,
              labelCol
            });

            if (Array.isArray(resp)) {
              select.innerHTML = '';
              const placeholderTxt = '-- Seleziona (DB) --';
              const phOpt = document.createElement('option');
              phOpt.value = "";
              phOpt.disabled = true;
              phOpt.selected = true;
              phOpt.textContent = placeholderTxt;
              select.appendChild(phOpt);

              resp.forEach(item => {
                const opt = document.createElement('option');
                if (typeof item !== 'object') {
                  opt.value = item;
                  opt.textContent = item;
                } else {
                  opt.value = item.value || item[valueCol] || item.id;
                  opt.textContent = item.label || item[labelCol] || item.name || opt.value;
                }
                select.appendChild(opt);
              });
              select.style.color = 'inherit';
            }
          }
        }
      } catch (e) {
        console.error("Errore popolamento anteprima DB Select:", e);
      }
    });
  });

})();
