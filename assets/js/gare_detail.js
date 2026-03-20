(function () {
  // Usa funzioni globali da main_core.js
  // statusLabel è specifico per job gare, definito localmente
  function statusLabel(status) {
    const map = {
      queued: 'In coda',
      processing: 'In elaborazione',
      in_progress: 'In elaborazione',
      completed: 'Completato',
      done: 'Completato',
      failed: 'Fallito',
      error: 'Errore',
    };
    const key = (status || '').toLowerCase();
    if (!key) return 'Sconosciuto';
    return map[key] || (key.charAt(0).toUpperCase() + key.slice(1));
  }

  // Usa direttamente le funzioni globali da main_core.js
  // Alias locali per comodità (puntano alle funzioni globali)
  const escapeHtml = window.escapeHtml || ((v) => String(v || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c] || c)));
  const formatDate = window.formatDate || (() => '—');
  const formatDateTime = window.formatDateTime || window.formatDate || (() => '—');

  // Utility per pickFirst (usata solo qui)
  function pickFirst(values) {
    if (!Array.isArray(values)) return undefined;
    for (const value of values) {
      if (value !== undefined && value !== null && value !== '') {
        return value;
      }
    }
    return undefined;
  }

  // Utility per estrarre il valore primario da un item
  function extractPrimaryValue(item) {
    const candidates = [
      item.display_value,
      item.valore_display,
      item.value_text,
      item.valore,
      item.valore_raw,
    ];
    for (const candidate of candidates) {
      if (candidate !== null && candidate !== undefined && candidate !== '') {
        return candidate;
      }
    }

    const jsonPayload = item.value_json || item.context;
    if (jsonPayload) {
      try {
        const json = typeof jsonPayload === 'string' ? JSON.parse(jsonPayload) : jsonPayload;
        if (json && typeof json === 'object') {
          const answer = pickFirst([
            json.answer,
            json.value,
            json.result,
            json.response,
            json.output,
            json.text,
            json.content,
          ]);

          if (answer !== undefined && answer !== null && answer !== '') {
            if (typeof answer === 'string') return answer;
            if (typeof answer === 'number' || typeof answer === 'boolean') return String(answer);
            if (Array.isArray(answer)) {
              return answer
                .map((part) => (typeof part === 'string' ? part : ''))
                .filter(Boolean)
                .join(' ');
            }
            if (typeof answer === 'object') {
              const nested = pickFirst([
                answer.text,
                answer.content,
                answer.value,
                answer.summary,
                answer.description,
              ]);
              if (typeof nested === 'string') return nested;
            }
          }
        }
      } catch (_) {
        /* ignore parse errors */
      }
    }

    return '';
  }


  const root = document.getElementById('gare-detail-root');
  if (!root) return;

  let garaId = parseInt(root.dataset.garaId || '', 10) || null;
  const initialJobId = parseInt(root.dataset.jobId || '', 10) || null;

  if (garaId) {
    window.currentGaraIdForUpload = garaId;
  }

  const jobs = new Map();
  let jobsInitialLoadPromise = null;
  const resultPromises = new Map();

  function normalizeItems(items) {
    if (!Array.isArray(items)) return [];
    const normalized = [];
    const sopralluogoItems = [];
    
    items.forEach((item) => {
      if (!item) return;
      const typeCodeRaw = item.type_code || item.tipo || item.type || '';
      const typeCode = String(typeCodeRaw).toLowerCase();

      if (typeCode === 'sopralluogo_obbligatorio') {
        const parsedValueJson = parseJsonObject(item.value_json);
        const details = buildSopralluogoDetails(item, parsedValueJson);

        const boolItem = { ...item };
        boolItem.type_code = 'sopralluogo_obbligatorio_split';
        boolItem.type_display = 'Sopralluogo obbligatorio';
        boolItem.display_value = details.required ? 'Sì' : 'No';
        boolItem.value_state = 'scalar';
        boolItem.synthetic = 'sopralluogo';
        boolItem.synthetic_kind = 'required';
        boolItem.synthetic_source = item;
        boolItem.synthetic_message = details.audit;
        sopralluogoItems.push(boolItem);

        const deadlineItem = { ...item };
        deadlineItem.type_code = 'sopralluogo_deadline';
        deadlineItem.type_display = 'Data richiesta sopralluogo';
        deadlineItem.display_value = details.deadline || '—';
        deadlineItem.value_state = 'scalar';
        deadlineItem.synthetic = 'sopralluogo';
        deadlineItem.synthetic_kind = 'deadline';
        deadlineItem.synthetic_source = item;
        sopralluogoItems.push(deadlineItem);
        return;
      }

      const normalizedItem = { ...item };
      // settore_industriale_gara_appalto ora mostra direttamente l'id_opera dal backend
      // (primo id_opera da gar_gara_importi_opere ordinato per importo decrescente)
      // Non serve più formattare il CPV

      normalized.push(normalizedItem);
    });
    
    // Riordina per garantire che gli item sopralluogo siano sempre adiacenti
    // Trova l'indice del primo item sopralluogo (required) se presente
    if (sopralluogoItems.length > 0) {
      const requiredIndex = normalized.findIndex(item => 
        item.synthetic === 'sopralluogo' && item.synthetic_kind === 'required'
      );
      const deadlineIndex = normalized.findIndex(item => 
        item.synthetic === 'sopralluogo' && item.synthetic_kind === 'deadline'
      );
      
      // Se entrambi sono già presenti, rimuovili e reinseriscili insieme
      if (requiredIndex !== -1 && deadlineIndex !== -1) {
        // Rimuovi entrambi mantenendo l'ordine degli altri
        const beforeRequired = normalized.slice(0, Math.min(requiredIndex, deadlineIndex));
        const afterDeadline = normalized.slice(Math.max(requiredIndex, deadlineIndex) + 1);
        const between = normalized.slice(
          Math.min(requiredIndex, deadlineIndex) + 1,
          Math.max(requiredIndex, deadlineIndex)
        );
        
        // Ricostruisci: prima gli altri item, poi required, poi deadline
        normalized.length = 0;
        normalized.push(...beforeRequired, ...between, ...sopralluogoItems, ...afterDeadline);
      } else if (requiredIndex !== -1 || deadlineIndex !== -1) {
        // Se solo uno è presente, rimuovilo e aggiungi entrambi
        const indexToRemove = requiredIndex !== -1 ? requiredIndex : deadlineIndex;
        normalized.splice(indexToRemove, 1);
        normalized.splice(indexToRemove, 0, ...sopralluogoItems);
      } else {
        // Se nessuno è presente, aggiungi alla fine
        normalized.push(...sopralluogoItems);
      }
    }
    
    return normalized;
  }

  document.addEventListener('gare:upload:completed', () => {
    if (garaId) {
      scheduleLoadJobs();
    }
  });

  scheduleLoadJobs();

  // Load API health status on page init
  const apiStatusContainer = document.getElementById('api-status-container');
  if (apiStatusContainer) {
    loadApiStatus(apiStatusContainer);
  }

  function scheduleLoadJobs() {
    jobsInitialLoadPromise = loadJobs();
    return jobsInitialLoadPromise;
  }

  async function loadJobs() {
    try {
      let data = [];
      if (garaId) {
        const res = await customFetch('gare', 'listJobsByGara', { gara_id: garaId });
        data = res?.data || [];
      } else if (initialJobId) {
        const res = await customFetch('gare', 'jobShow', { job_id: initialJobId });
        if (res?.ok && res.data) {
          data = [normalizeJob(res.data)];
        }
      }

      jobs.clear();
      data.forEach((job) => {
        if (!job) return;
        if (!job.job_id) job.job_id = job.id;
        jobs.set(job.job_id, job);
      });

      const jobWithGara = data.find((job) => job.gara_id);
      if (jobWithGara && (!garaId || garaId !== jobWithGara.gara_id)) {
        setGaraId(jobWithGara.gara_id);
      }

      if (jobs.size === 0) {
        return;
      }

      renderJobs();
      for (const job of jobs.values()) {
        await loadJobResults(job.job_id);
        if (job.status !== 'completed') {
          pollJob(job.job_id);
        }
      }

      return Array.from(jobs.values());
    } catch (e) {
      console.error('Errore caricamento job gara:', e);
      throw e;
    }
  }

  async function loadJobResults(jobId) {
    if (!jobId) return;
    const promise = (async () => {
      try {
        const res = await customFetch('gare', 'jobResults', { job_id: jobId });
        if (jobs.has(jobId)) {
          const job = jobs.get(jobId);
          job.results = res;
          renderJobs();
        }
      } catch (e) {
        console.error('Errore caricamento risultati job:', e);
      }
    })();

    resultPromises.set(jobId, promise);
    try {
      await promise;
    } finally {
      resultPromises.delete(jobId);
    }
  }

  async function pollJob(jobId) {
    try {
      const res = await customFetch('gare', 'jobPull', { job_id: jobId }, { showLoader: false });
      if (!jobs.has(jobId)) return;

      const job = jobs.get(jobId);
      if (res) {
        if (res.status) {
          job.status = res.status;
          job.status_label = statusLabel(res.status);
        }
        if (res.progress) {
          job.progress_done = res.progress.done;
          job.progress_total = res.progress.total;
        }
      }

      renderJobs();
      if (job.status !== 'completed' && job.status !== 'failed' && job.status !== 'error') {
        setTimeout(() => pollJob(jobId), 3000);
      } else {
        await loadJobResults(jobId);
      }
    } catch (e) {
      console.error('Errore polling job:', e);
    }
  }

  function setGaraId(id) {
    if (!id) return;
    garaId = id;
    root.dataset.garaId = String(id);
    window.currentGaraIdForUpload = id;

    if (typeof URL !== 'undefined' && window.history && typeof window.history.replaceState === 'function') {
      const url = new URL(window.location.href);
      url.searchParams.set('gara_id', String(id));
      url.searchParams.delete('job_id');
      window.history.replaceState({}, document.title, url.toString());
    }
  }

  function renderJobs() {
    if (!jobs.size) {
      hideSection('gd-loading');
      const errEl = document.getElementById('gd-error');
      if (errEl) {
        errEl.textContent = 'Nessuna estrazione disponibile.';
        errEl.style.display = '';
      }
      return;
    }

    const primaryJob = jobs.values().next().value;
    if (!primaryJob) return;

    const isComplete = primaryJob.status === 'completed' || primaryJob.status === 'done';
    const isFailed = primaryJob.status === 'failed' || primaryJob.status === 'error';

    if (primaryJob.results) {
      renderGaraDetail(primaryJob);
    } else if (!isComplete && !isFailed) {
      // Job in progress — show header with live progress
      renderProgressHeader(primaryJob);
    } else if (isFailed) {
      hideSection('gd-loading');
      const errEl = document.getElementById('gd-error');
      if (errEl) {
        errEl.textContent = 'Estrazione fallita: ' + (primaryJob.error_message || 'errore sconosciuto');
        errEl.style.display = '';
      }
    }
  }

  /**
   * Render a minimal header with live progress bar while extraction is running.
   */
  function renderProgressHeader(job) {
    const el = document.getElementById('gd-header');
    if (!el) return;

    const total = Math.max(1, job.progress_total || 100);
    const done = Math.max(0, Math.min(total, job.progress_done || 0));
    const pct = Math.round((done / total) * 100);
    const fileName = job.file_name || 'Documento';
    const badge = getStatusBadge(job);

    el.innerHTML = `
      <div class="intest">
        <div class="int-top">
          <div class="int-left">
            <div class="pdf-chip">PDF</div>
            <div class="int-meta2">
              <span class="int-file">${escapeHtml(fileName)}</span>
              <span class="int-title">Estrazione in corso...</span>
            </div>
          </div>
          <div class="int-right">
            <span class="gd-badge ${badge.cls}"><span class="gd-dot"></span> ${escapeHtml(badge.label)}</span>
          </div>
        </div>
        <div class="prog-strip">
          <div class="prog-bar"><div class="prog-fill" style="width:${pct}%"></div></div>
          <span class="prog-pct">${pct}%</span>
          <span class="prog-label">${done} / ${total} elementi elaborati</span>
        </div>
      </div>
    `;
    showSection('gd-header');
    hideSection('gd-loading');
  }

  function initializeExtractionTables(scope) {
    if (!scope) return;
    if (typeof window.initTableFilters === 'function') {
      const tables = scope.querySelectorAll('table[data-extraction-table="true"]');
      tables.forEach((table) => {
        if (table.id) return;
        table.id = `extraction-table-${Math.random().toString(36).slice(2, 8)}`;
        window.initTableFilters(table.id);
      });
    }
  }

  function resolveOggettoAppalto(job) {
    if (!job) return '';
    const data = job.results && Array.isArray(job.results.data) ? job.results.data : [];
    for (const item of data) {
      const code = (item.type_code || item.tipo || item.type || '').toLowerCase();
      if (code === 'oggetto_appalto' || code === 'oggetto_dell_appalto' || code === 'oggetto_della_gara') {
        const primary = extractPrimaryValue(item);
        const text = stringifyValue(primary);
        if (text && text.trim()) return text.trim();
        if (item.display_value) {
          const displayText = stringifyValue(item.display_value);
          if (displayText && displayText.trim()) return displayText.trim();
        }
      }
    }
    if (job.oggetto_appalto && String(job.oggetto_appalto).trim()) return String(job.oggetto_appalto).trim();
    if (job.titolo && String(job.titolo).trim()) return String(job.titolo).trim();
    return '';
  }

  function renderJobsPrint() {
    if (!jobs.size) {
      return '<p class="print-jobs-empty">Nessuna estrazione disponibile.</p>';
    }

    const body = Array.from(jobs.values())
      .map((job) => renderJobPrint(job))
      .join('');

    return `
      <div class="print-wrapper">
        ${renderPrintHeader()}
        <div class="print-body">${body}</div>
      </div>
    `;
  }

  function renderPrintHeader() {
    const logoPath = '/assets/logo/logo_incide_engineering.png';
    return `
      <div class="print-bg-logo">
        <img src="${logoPath}" alt="Incide Engineering">
      </div>
    `;
  }

  function formatPrintInlineValue(valueHtml) {
    if (!valueHtml) return '';
    const temp = document.createElement('div');
    temp.innerHTML = valueHtml;
    const text = temp.textContent || temp.innerText || '';
    return escapeHtml(text.trim());
  }

  function renderJobPrint(job) {
    const appaltoTitle = resolveOggettoAppalto(job);
    return `
      <article class="print-job" id="print-job-${job.job_id}">
        ${appaltoTitle ? `<h2 class="print-job-title">${escapeHtml(appaltoTitle)}</h2>` : ''}
        ${renderJobPrintResults(job)}
      </article>
    `;
  }

  function renderJobPrintResults(job) {
    const data = job.results;
    if (!data || !data.ok || !Array.isArray(data.data) || !data.data.length) {
      return '<p class="print-job-empty">Nessun risultato disponibile.</p>';
    }

    job.normalized_items = normalizeItems(data.data);
    // IMPORTANTE: Ordina gli items usando DETTAGLIO_GARA_ORDER anche per la stampa
    job.normalized_items = sortExtractionItems(job.normalized_items);
    
    // Se c'è un titolo "Oggetto dell'appalto", nascondi la voce duplicata nella lista
    const hasOggettoAppaltoTitle = resolveOggettoAppalto(job);
    if (hasOggettoAppaltoTitle) {
      job.normalized_items = job.normalized_items.filter((item) => {
        const typeCode = (item.type_code || item.tipo || item.type || '').toLowerCase();
        return typeCode !== 'oggetto_appalto' && 
               typeCode !== 'oggetto_dell_appalto' && 
               typeCode !== 'oggetto_della_gara';
      });
    }
    
    const sections = job.normalized_items
      .map((item, index) => renderPrintExtraction(item, index))
      .filter(Boolean)
      .join('');

    return `<div class="print-extractions">${sections}</div>`;
  }

  function renderPrintExtraction(item, index) {
    const typeCode = (item.type_code || item.tipo || item.type || '').toLowerCase();
    const typeInfo = resolveExtractionType(item);
    const value = renderValueCell(item);
    
    // Controlla se c'è una tabella: estrai solo il tab "response" (senza citazioni e JSON)
    const source = item && item.synthetic_source ? item.synthetic_source : item;
    const tabs = buildExtractionTabs(source);
    const responseTab = tabs && Array.isArray(tabs) ? tabs.find((tab) => tab.id === 'response') : null;
    const responseContent = responseTab ? responseTab.content : '';
    const hasTable = responseContent && /<table[\s>]/i.test(responseContent);
    
    // Se c'è una tabella, usa solo il contenuto del tab "response" (solo tabelle, niente citazioni/JSON)
    if (hasTable) {
      const inlineValue = value;
      const inlineText = inlineValue ? formatPrintInlineValue(inlineValue) : '';
      
      return `
        <section class="print-extraction">
          <div class="print-extraction-label">
            <span class="print-extraction-index">${index + 1}.</span>
            <span class="print-extraction-type">${escapeHtml(typeInfo.label)}</span>
          </div>
          <div class="print-extraction-value">
            ${inlineText || '<span class="print-placeholder">—</span>'}
          </div>
          <div class="print-extraction-details">${responseContent}</div>
        </section>
      `;
    }
    
    // Per le righe senza tabelle, usa la versione semplice originale
    const detail = renderPrintResponseContent(item);
    const valueIsTable = detail && /<table[\s>]/i.test(detail);
    const inlineValue = valueIsTable ? '' : value;
    const inlineText = inlineValue ? formatPrintInlineValue(inlineValue) : '';
    const detailHtml = valueIsTable ? detail : '';

    return `
      <section class="print-extraction">
        <div class="print-extraction-label">
          <span class="print-extraction-index">${index + 1}.</span>
          <span class="print-extraction-type">${escapeHtml(typeInfo.label)}</span>
        </div>
        <div class="print-extraction-value">
          ${inlineText || (detailHtml ? '<span class="print-placeholder">—</span>' : '&mdash;')}
        </div>
        ${detailHtml ? `<div class="print-extraction-details">${detailHtml}</div>` : ''}
      </section>
    `;
  }

  function resolveExtractionType(item) {
    const labelCandidate =
      item.type_display ||
      item.tipo_display ||
      item.type ||
      item.type_code ||
      '';
    const label = labelCandidate ? String(labelCandidate) : '—';
    return { label };
  }

  /**
   * Mappa di ordinamento fisso per i blocchi di estrazione nella pagina Dettaglio Gara
   * Deve corrispondere esattamente a DETTAGLIO_GARA_ORDER in ExtractionFormatter.php
   */
  const DETTAGLIO_GARA_ORDER = {
    'oggetto_appalto': 1,
    'luogo_provincia_appalto': 2,
    'data_scadenza_gara_appalto': 3,
    'data_uscita_gara_appalto': 4,
    'settore_industriale_gara_appalto': 5,
    'sopralluogo_obbligatorio': 6,
    'sopralluogo_obbligatorio_split': 6, // Stesso ordine di sopralluogo_obbligatorio
    'sopralluogo_deadline': 7,
    'stazione_appaltante': 8,
    'tipologia_di_appalto': 9,
    'tipologia_di_gara': 10,
    'link_portale_stazione_appaltante': 11,
    'importi_opere_per_categoria_id_opere': 12,
    'importi_corrispettivi_categoria_id_opere': 13,
    'importi_requisiti_tecnici_categoria_id_opere': 14,
    'documentazione_richiesta_tecnica': 15,
    'requisiti_tecnico_professionali': 16,
    'fatturato_globale_n_minimo_anni': 17,
    'requisiti_di_capacita_economica_finanziaria': 18,
    'requisiti_idoneita_professionale_gruppo_lavoro': 19,
  };

  // ══════════════════════════════════════════════════════════════════
  // SECTION CLASSIFICATION — maps extraction type_code to UI section
  // ══════════════════════════════════════════════════════════════════
  const SECTION_MAP = {
    oggetto_appalto: 'header',
    stazione_appaltante: 'header',
    data_scadenza_gara_appalto: 'header',
    data_uscita_gara_appalto: 'header',
    luogo_provincia_appalto: 'header',
    tipologia_di_gara: 'header',
    link_portale_stazione_appaltante: 'header',
    sopralluogo_obbligatorio: 'overview',
    sopralluogo_obbligatorio_split: 'overview',
    sopralluogo_deadline: 'overview',
    tipologia_di_appalto: 'overview',
    settore_industriale_gara_appalto: 'overview',
    settore_gara: 'overview',
    importi_opere_per_categoria_id_opere: 'importi',
    importi_corrispettivi_categoria_id_opere: 'importi',
    importi_requisiti_tecnici_categoria_id_opere: 'importi',
    requisiti_tecnico_professionali: 'requisiti',
    fatturato_globale_n_minimo_anni: 'economici',
    requisiti_di_capacita_economica_finanziaria: 'economici',
    documentazione_richiesta_tecnica: 'docs_ruoli',
    requisiti_idoneita_professionale_gruppo_lavoro: 'docs_ruoli',
    documenti_di_gara: 'docs_ruoli',
    criteri_valutazione_offerta_tecnica: 'docs_ruoli',
  };

  /**
   * Classify extraction items into UI sections.
   * Each item goes to exactly ONE section (no duplicates).
   */
  function classifyExtractions(items) {
    const sections = {
      header: [], overview: [], importi: [],
      requisiti: [], economici: [], docs_ruoli: [], fallback: []
    };
    for (const item of items) {
      const type = (item.type_code || item.tipo || '').toLowerCase();
      const section = SECTION_MAP[type] || 'fallback';
      sections[section].push(item);
    }
    return sections;
  }

  // ══════════════════════════════════════════════════════════════════
  // HELPER FUNCTIONS for section renderers
  // ══════════════════════════════════════════════════════════════════

  /**
   * Extract the human-readable display value from an extraction item.
   */
  function getDisplayValue(item) {
    if (!item) return '';
    const primary = extractPrimaryValue(item);
    const text = stringifyValue(primary);
    if (text && text.trim()) return text.trim();
    if (item.display_value) {
      const dv = stringifyValue(item.display_value);
      if (dv && dv.trim()) return dv.trim();
    }
    if (item.empty_reason) return item.empty_reason;
    return '';
  }

  /**
   * Parse value_json once. Returns the parsed object or null.
   */
  function getJson(item) {
    if (!item) return null;
    return parseJsonObject(item.value_json);
  }

  /**
   * Format an API date object {year, month, day, hour?, minute?} to Italian string.
   * e.g. "07/11/2025 ore 12:00" or "07/11/2025"
   */
  function formatApiDate(dateObj) {
    if (!dateObj || !dateObj.year) return '';
    const dd = String(dateObj.day || 1).padStart(2, '0');
    const mm = String(dateObj.month || 1).padStart(2, '0');
    const yyyy = dateObj.year;
    let result = `${dd}/${mm}/${yyyy}`;
    if (dateObj.hour !== null && dateObj.hour !== undefined) {
      const hh = String(dateObj.hour).padStart(2, '0');
      const min = String(dateObj.minute || 0).padStart(2, '0');
      result += ` ore ${hh}:${min}`;
    }
    return result;
  }

  /**
   * Format an API date object to long Italian: "7 novembre 2025 ore 12:00"
   */
  function formatApiDateLong(dateObj) {
    if (!dateObj || !dateObj.year) return '';
    const months = [
      'gennaio','febbraio','marzo','aprile','maggio','giugno',
      'luglio','agosto','settembre','ottobre','novembre','dicembre'
    ];
    let result = `${dateObj.day} ${months[(dateObj.month || 1) - 1]} ${dateObj.year}`;
    if (dateObj.hour !== null && dateObj.hour !== undefined) {
      const hh = String(dateObj.hour).padStart(2, '0');
      const min = String(dateObj.minute || 0).padStart(2, '0');
      result += ` ore ${hh}:${min}`;
    }
    return result;
  }

  /**
   * Read a simple string answer from API JSON.
   * Checks: json.answer, json.url, then falls back to getDisplayValue.
   */
  function getSimpleAnswer(item) {
    const json = getJson(item);
    if (json) {
      if (typeof json.answer === 'string' && json.answer.trim()) return json.answer.trim();
      if (typeof json.url === 'string' && json.url.trim()) return json.url.trim();
    }
    return getDisplayValue(item);
  }

  /**
   * Map type_code to Italian label, leveraging resolveExtractionType.
   */
  function getTypeLabel(typeCode) {
    const TYPE_LABELS = {
      oggetto_appalto: 'Oggetto dell\'appalto',
      stazione_appaltante: 'Stazione appaltante',
      data_scadenza_gara_appalto: 'Scadenza',
      data_uscita_gara_appalto: 'Pubblicazione',
      luogo_provincia_appalto: 'Luogo',
      tipologia_di_gara: 'Tipologia di gara',
      tipologia_di_appalto: 'Tipologia di appalto',
      link_portale_stazione_appaltante: 'Portale',
      sopralluogo_obbligatorio: 'Sopralluogo obbligatorio',
      sopralluogo_obbligatorio_split: 'Sopralluogo obbligatorio',
      sopralluogo_deadline: 'Data richiesta sopralluogo',
      settore_industriale_gara_appalto: 'Settore industriale',
      settore_gara: 'Settore della gara',
      importi_opere_per_categoria_id_opere: 'Importi opere per categoria',
      importi_corrispettivi_categoria_id_opere: 'Corrispettivi per categoria',
      importi_requisiti_tecnici_categoria_id_opere: 'Requisiti tecnici per categoria',
      requisiti_tecnico_professionali: 'Requisiti tecnico-professionali',
      fatturato_globale_n_minimo_anni: 'Fatturato globale minimo',
      requisiti_di_capacita_economica_finanziaria: 'Capacita economico-finanziaria',
      documentazione_richiesta_tecnica: 'Documentazione richiesta tecnica',
      requisiti_idoneita_professionale_gruppo_lavoro: 'Idoneita professionale gruppo di lavoro',
      documenti_di_gara: 'Documenti di gara',
      criteri_valutazione_offerta_tecnica: 'Criteri valutazione offerta tecnica',
    };
    if (!typeCode) return '';
    const key = typeCode.toLowerCase();
    return TYPE_LABELS[key] || typeCode.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  /**
   * Format a number as Italian Euro: "1.234.567,89 EUR"
   */
  function formatEuro(amount) {
    if (amount === null || amount === undefined || isNaN(amount)) return 'N/D';
    return parseFloat(amount).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
  }

  /**
   * Format a date string as Italian long format: "21 gennaio 2026"
   */
  function formatDateItalianLong(dateStr) {
    if (!dateStr) return '';
    const months = [
      'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
      'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'
    ];
    // Try to parse various formats
    let d = null;
    if (typeof dateStr === 'string') {
      const trimmed = dateStr.trim();
      // dd/mm/yyyy
      const itMatch = trimmed.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
      if (itMatch) {
        d = new Date(parseInt(itMatch[3]), parseInt(itMatch[2]) - 1, parseInt(itMatch[1]));
      }
      // yyyy-mm-dd
      if (!d || isNaN(d.getTime())) {
        const isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (isoMatch) {
          d = new Date(parseInt(isoMatch[1]), parseInt(isoMatch[2]) - 1, parseInt(isoMatch[3]));
        }
      }
      if (!d || isNaN(d.getTime())) {
        d = new Date(trimmed);
      }
    } else {
      d = new Date(dateStr);
    }
    if (!d || isNaN(d.getTime())) return String(dateStr);
    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
  }

  /**
   * Calculate days until a given date string. Returns negative if past.
   */
  function daysUntil(dateStr) {
    if (!dateStr) return null;
    let d = null;
    if (typeof dateStr === 'string') {
      const trimmed = dateStr.trim();
      const itMatch = trimmed.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
      if (itMatch) {
        d = new Date(parseInt(itMatch[3]), parseInt(itMatch[2]) - 1, parseInt(itMatch[1]));
      }
      if (!d || isNaN(d.getTime())) {
        const isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (isoMatch) {
          d = new Date(parseInt(isoMatch[1]), parseInt(isoMatch[2]) - 1, parseInt(isoMatch[3]));
        }
      }
      if (!d || isNaN(d.getTime())) {
        d = new Date(trimmed);
      }
    }
    if (!d || isNaN(d.getTime())) return null;
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    d.setHours(0, 0, 0, 0);
    return Math.ceil((d - now) / (1000 * 60 * 60 * 24));
  }

  /**
   * Show/hide a container by id.
   */
  function showSection(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = '';
  }
  function hideSection(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  }

  /**
   * Find an extraction item by type_code from an items array.
   */
  function findByType(items, typeCode) {
    if (!Array.isArray(items)) return null;
    const target = typeCode.toLowerCase();
    return items.find(item => {
      const code = (item.type_code || item.tipo || '').toLowerCase();
      return code === target;
    }) || null;
  }

  /**
   * Sum amount_eur from entries in value_json for importi-type items.
   */
  /**
   * Parse an Italian-formatted number string like "10.000.000,50" to float.
   */
  function parseItalianNumber(str) {
    if (str === null || str === undefined) return NaN;
    if (typeof str === 'number') return str;
    // Remove currency symbols, spaces, non-numeric chars except . and ,
    const cleaned = String(str).replace(/[€$\s]/g, '').trim();
    if (!cleaned) return NaN;
    // If has both . and , → Italian: 1.234,56
    if (cleaned.includes('.') && cleaned.includes(',')) {
      return parseFloat(cleaned.replace(/\./g, '').replace(',', '.'));
    }
    // If only , and it looks like decimal separator (max 2 digits after)
    if (cleaned.includes(',') && /,\d{1,2}$/.test(cleaned)) {
      return parseFloat(cleaned.replace(',', '.'));
    }
    // Otherwise parse normally
    return parseFloat(cleaned);
  }

  function sumAmountEur(item) {
    if (!item) return 0;
    const parsed = parseJsonObject(item.value_json);
    if (parsed && Array.isArray(parsed.entries)) {
      return parsed.entries.reduce((sum, e) => {
        const raw = e.amount_eur ?? e.importo_corrispettivo_eur ?? e.amount_raw ?? 0;
        const val = (typeof raw === 'number') ? raw : parseItalianNumber(raw);
        return sum + (isNaN(val) ? 0 : val);
      }, 0);
    }
    if (item.table && Array.isArray(item.table.rows)) {
      return item.table.rows.reduce((sum, row) => {
        if (Array.isArray(row)) {
          for (const cell of row) {
            const val = parseItalianNumber(cell);
            if (!isNaN(val) && val > 100) return sum + val;
          }
        }
        return sum;
      }, 0);
    }
    return 0;
  }

  /**
   * Get the status badge class and label for a job.
   */
  function getStatusBadge(job) {
    const status = (job.status || '').toLowerCase();
    if (status === 'completed' || status === 'done') {
      return { cls: 'gd-bg', label: 'Completato' };
    }
    if (status === 'processing' || status === 'in_progress') {
      return { cls: 'gd-ba', label: 'In elaborazione' };
    }
    if (status === 'failed' || status === 'error') {
      return { cls: 'gd-br', label: 'Errore' };
    }
    return { cls: 'gd-bb', label: statusLabel(status) };
  }

  // ══════════════════════════════════════════════════════════════════
  // MAIN DISPATCHER — renderGaraDetail(job)
  // ══════════════════════════════════════════════════════════════════

  /**
   * Main entry point for section-based rendering.
   * Takes a loaded job with results and populates all section containers.
   */
  function renderGaraDetail(job) {
    if (!job || !job.results || !job.results.ok || !Array.isArray(job.results.data)) {
      hideSection('gd-loading');
      const errEl = document.getElementById('gd-error');
      if (errEl) {
        errEl.textContent = 'Nessun dato disponibile per questa gara.';
        errEl.style.display = '';
      }
      return;
    }

    // Normalize and sort items
    if (!job.normalized_items) {
      job.normalized_items = normalizeItems(job.results.data);
      job.normalized_items = sortExtractionItems(job.normalized_items);
    }

    const allItems = job.normalized_items;
    const sections = classifyExtractions(allItems);

    // Helper to find by type across all items
    const byType = (typeCode) => findByType(allItems, typeCode);

    // Render each section
    renderHeader(job, sections.header, byType);
    renderOverview(sections.overview, byType, allItems);
    renderServizi(byType);
    renderImporti(sections.importi, byType);
    renderRequisiti(sections.requisiti);
    renderEconomici(sections.economici, byType);
    renderDocsRuoli(sections.docs_ruoli, job);
    renderAllFields(sections.fallback, job);
    renderActionBar(job);

    // Hide loading, show populated sections
    hideSection('gd-loading');

    // Update print layout
    const printRoot = document.getElementById('gare-print-root');
    if (printRoot) {
      printRoot.innerHTML = renderJobsPrint();
      printRoot.setAttribute('data-print-root', 'true');
    }

    // Load batch usage if completed
    if (job.status === 'completed' || job.status === 'done') {
      const batchId = job.ext_batch_id;
      const usageContainer = document.getElementById('batch-usage-container');
      if (batchId && usageContainer) {
        loadBatchUsage(batchId, usageContainer);
      }
    }
  }

  // ══════════════════════════════════════════════════════════════════
  // SECTION RENDERERS
  // ══════════════════════════════════════════════════════════════════

  /**
   * Render the header/intestazione card.
   */
  function renderHeader(job, headerItems, byType) {
    const el = document.getElementById('gd-header');
    if (!el) return;

    // Title: from API JSON project_name or fallback
    const oggettoItem = byType('oggetto_appalto');
    const oggettoJson = getJson(oggettoItem);
    const title = oggettoJson?.project_name || (oggettoItem ? getDisplayValue(oggettoItem) : '') || resolveOggettoAppalto(job) || job.file_name || 'Gara';

    // Status badge
    const badge = getStatusBadge(job);
    const allItems = job.normalized_items || [];
    const itemCount = allItems.length;

    // Meta fields — read API JSON directly
    const ente = getSimpleAnswer(byType('stazione_appaltante'));

    const scadenzaItem = byType('data_scadenza_gara_appalto');
    const scadenzaJson = getJson(scadenzaItem);
    const scadenza = scadenzaJson?.date ? formatApiDate(scadenzaJson.date) : (getDisplayValue(scadenzaItem) ? (formatDateItalian(getDisplayValue(scadenzaItem)) || getDisplayValue(scadenzaItem)) : '');

    const tipologia = getSimpleAnswer(byType('tipologia_di_gara'));

    const luogoItem = byType('luogo_provincia_appalto');
    const luogoJson = getJson(luogoItem);
    let luogo = '';
    if (luogoJson?.location) {
      const loc = luogoJson.location;
      const nameParts = [loc.entity_type, loc.entity_name].filter(Boolean);
      const entityStr = nameParts.join(' ');
      const parts = [entityStr, loc.city].filter(Boolean);
      luogo = parts.join(', ');
      if (loc.district) luogo += ` (${loc.district})`;
      if (loc.country && loc.country !== 'Italia') luogo += ` — ${loc.country}`;
      if (loc.nuts_code) luogo += ` [${loc.nuts_code}]`;
    } else {
      luogo = getDisplayValue(luogoItem);
    }

    const updated = job.updated_at || job.completed_at || job.created_at;
    const updatedLabel = formatDate(updated) || '';

    const portaleItem = byType('link_portale_stazione_appaltante');
    const portaleJson = getJson(portaleItem);
    const portaleValue = portaleJson?.url || getDisplayValue(portaleItem);
    const portaleIsUrl = portaleValue && /^https?:\/\//i.test(portaleValue);
    let portaleDomain = '';
    if (portaleIsUrl) {
      try { portaleDomain = new URL(portaleValue).hostname; } catch (_) { portaleDomain = portaleValue; }
    }

    // Confidence: average from all items that have confidence
    let totalConf = 0;
    let confCount = 0;
    allItems.forEach(item => {
      const c = parseFloat(item.confidence || item.confidence_score || 0);
      if (c > 0) { totalConf += c; confCount++; }
    });
    const avgConf = confCount > 0 ? Math.round((totalConf / confCount) * 100) : 0;

    const fileName = job.file_name || '';

    // Live progress (visible when job is not yet completed)
    const jobStatus = (job.status || '').toLowerCase();
    const jobInProgress = jobStatus !== 'completed' && jobStatus !== 'done' && jobStatus !== 'failed' && jobStatus !== 'error';
    const total = Math.max(1, job.progress_total || 100);
    const done = Math.max(0, Math.min(total, job.progress_done || 0));
    const pct = Math.round((done / total) * 100);
    const chevronSvg = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

    el.innerHTML = `
      <div class="page-title-container" style="border-bottom:3px solid var(--gd-blue);padding-bottom:10px;padding-left:5px;margin-bottom:15px;">
        <h1 style="margin:0;margin-bottom:-5px;font-weight:bold;color:#333;">${escapeHtml(truncate(title, 100))}</h1>
      </div>
      <div class="intest">
        <div class="int-top">
          <div class="int-left">
            <div class="pdf-chip">PDF</div>
            <div class="int-meta2">
              ${fileName ? `<span class="int-file">${escapeHtml(fileName)}</span>` : ''}
              <span class="int-title">${escapeHtml(truncate(title, 120))}</span>
            </div>
          </div>
          <div class="int-right">
            <div id="asi-slot"></div>
            ${jobInProgress ? `
            <div class="int-prog">
              <div class="prog-bar" style="width:80px;"><div class="prog-fill" style="width:${pct}%"></div></div>
              <span class="prog-pct">${pct}%</span>
            </div>` : ''}
            <span class="gd-badge ${badge.cls}"><span class="gd-dot"></span> ${escapeHtml(badge.label)}</span>
            <span class="gd-badge gd-bb">${itemCount} elementi</span>
          </div>
        </div>
        <div class="meta-row">
          <div class="mc"><span class="mc-l">Stazione appaltante</span><span class="mc-v sm">${ente ? escapeHtml(truncate(ente, 80)) : '<span class="mc-v sm" style="color:var(--gd-t2);font-style:italic">N/D</span>'}</span></div>
          <div class="mc${scadenza ? ' hi' : ''}"><span class="mc-l">Scadenza</span><span class="mc-v">${scadenza ? escapeHtml(scadenza) : '<span style="color:var(--gd-t2);font-style:italic">N/D</span>'}</span></div>
          <div class="mc"><span class="mc-l">Tipologia</span><span class="mc-v sm">${tipologia ? escapeHtml(truncate(tipologia, 50)) : '<span style="color:var(--gd-t2);font-style:italic">N/D</span>'}</span></div>
          <div class="mc"><span class="mc-l">Luogo</span><span class="mc-v">${luogo ? escapeHtml(luogo) : '<span style="color:var(--gd-t2);font-style:italic">N/D</span>'}</span></div>
          <div class="mc"><span class="mc-l">Aggiornato</span><span class="mc-v">${updatedLabel ? escapeHtml(updatedLabel) : '—'}</span></div>
          <div class="mc"><span class="mc-l">Portale</span><span class="mc-v">${portaleIsUrl ? `<a href="${escapeAttribute(portaleValue)}" target="_blank" rel="noopener">${escapeHtml(portaleDomain)} &#8599;</a>` : (portaleValue ? escapeHtml(truncate(portaleValue, 40)) : '<span style="color:var(--gd-t2);font-style:italic">N/D</span>')}</span></div>
        </div>
        ${confCount > 0 ? `
        <div class="conf-strip">
          <span class="conf-l">Confidenza estrazione</span>
          <div class="conf-bar"><div class="conf-fill" style="width:${avgConf}%"></div></div>
          <span class="conf-pct">${avgConf}%</span>
          <span class="conf-info">&nbsp;&middot;&nbsp; ${itemCount} campi elaborati</span>
        </div>` : ''}
      </div>
    `;
    showSection('gd-header');

    // Move API status widget into the header int-right area
    const asiSource = document.getElementById('api-status-container');
    const asiSlot = document.getElementById('asi-slot');
    if (asiSource && asiSlot && asiSource.firstElementChild) {
      asiSlot.appendChild(asiSource.firstElementChild);
    }
  }

  /**
   * Search an extraction item's citations for a keyword match.
   * Returns the matched citation text fragment or '' if not found.
   */
  function findInCitations(item, keyword) {
    const json = getJson(item?.synthetic_source || item);
    if (!json) return '';
    const citations = json.citations || [];
    for (const cit of citations) {
      const texts = Array.isArray(cit.text) ? cit.text : [cit.text || ''];
      for (const t of texts) {
        if (t.toLowerCase().includes(keyword.toLowerCase())) return t;
      }
    }
    return '';
  }

  /**
   * Parse Qcl codes and complexity grades from importi_corrispettivi citation text.
   * Returns a Map of category_id → { qclCodes: string, complexityGrade: string }
   */
  function parseQclFromCitations(corrispettiviItem) {
    const result = new Map();
    const json = getJson(corrispettiviItem?.synthetic_source || corrispettiviItem);
    if (!json?.citations) return result;
    const regex = /^(.+?)\s+((?:[A-Z]+\.)\d+)\s+(Qcl\.\d+(?:\s+[–\-]\s+Qcl\.\d+)*)\s+([\d.,]+)\s+€/;
    for (const cit of json.citations) {
      const texts = Array.isArray(cit.text) ? cit.text : [cit.text || ''];
      for (const line of texts) {
        const m = line.match(regex);
        if (m) {
          result.set(m[2], { qclCodes: m[3], complexityGrade: m[4] });
        }
      }
    }
    return result;
  }

  /**
   * Render the Panoramica (overview) section.
   */
  function renderOverview(overviewItems, byType, allItems) {
    const el = document.getElementById('gd-overview');
    if (!el) return;

    // Timeline dates — read API date objects directly
    const dataUscitaItem = byType('data_uscita_gara_appalto');
    const dataUscitaJson = getJson(dataUscitaItem);
    const dataScadenzaItem = byType('data_scadenza_gara_appalto');
    const dataScadenzaJson = getJson(dataScadenzaItem);

    const dataUscitaVal = dataUscitaJson?.date ? formatApiDate(dataUscitaJson.date) : getDisplayValue(dataUscitaItem);
    const dataScadenzaVal = dataScadenzaJson?.date ? formatApiDate(dataScadenzaJson.date) : getDisplayValue(dataScadenzaItem);
    const uscitaLong = dataUscitaJson?.date ? formatApiDateLong(dataUscitaJson.date) : formatDateItalianLong(dataUscitaVal);
    const scadenzaLong = dataScadenzaJson?.date ? formatApiDateLong(dataScadenzaJson.date) : formatDateItalianLong(dataScadenzaVal);

    // Sopralluogo — read API JSON directly (bool_answer, deadlines[], booking)
    const sopItem = byType('sopralluogo_obbligatorio') || byType('sopralluogo_obbligatorio_split');
    const sopJson = getJson(sopItem?.synthetic_source || sopItem);
    let sopRequired = false;
    let sopDeadlineVal = '';
    let sopBookingUrl = '';
    let sopBookingInstructions = '';
    let sopDeadlineNotes = '';
    let sopBookingContacts = [];

    if (sopJson && typeof sopJson.bool_answer === 'boolean') {
      sopRequired = sopJson.bool_answer;
      if (Array.isArray(sopJson.deadlines) && sopJson.deadlines.length > 0) {
        const dl = sopJson.deadlines[0];
        const dtObj = dl.calculated_effective_datetime || dl.absolute_datetime;
        sopDeadlineVal = dtObj ? formatApiDate(dtObj) : (dl.source_text || '');
      }
      if (sopJson.booking_platform?.url) sopBookingUrl = sopJson.booking_platform.url;
      sopBookingInstructions = sopJson.booking_instructions || '';
      sopDeadlineNotes = (Array.isArray(sopJson.deadlines) && sopJson.deadlines.length > 0)
        ? (sopJson.deadlines[0].notes || '') : '';
      sopBookingContacts = Array.isArray(sopJson.booking_contacts) ? sopJson.booking_contacts : [];
    } else {
      // Fallback to synthetic items
      const sopSplitItem = byType('sopralluogo_obbligatorio_split');
      if (sopSplitItem) {
        const norm = (getDisplayValue(sopSplitItem) || '').toLowerCase().trim();
        sopRequired = (norm === 'si' || norm === 'sì' || norm === 'yes' || norm === 'true' || norm === '1');
      }
      const sopDeadlineItem = byType('sopralluogo_deadline');
      if (sopDeadlineItem) sopDeadlineVal = getDisplayValue(sopDeadlineItem);
    }

    // Countdown — use structured date if available
    let scadenzaDays = null;
    if (dataScadenzaJson?.date) {
      const d = new Date(dataScadenzaJson.date.year, dataScadenzaJson.date.month - 1, dataScadenzaJson.date.day);
      const now = new Date(); now.setHours(0,0,0,0); d.setHours(0,0,0,0);
      scadenzaDays = Math.ceil((d - now) / (1000 * 60 * 60 * 24));
    } else {
      scadenzaDays = daysUntil(dataScadenzaVal);
    }

    // Stat cards
    const totalItems = allItems.length;
    const missingItems = allItems.filter(item => {
      const dv = getDisplayValue(item);
      return !dv || dv === '—' || dv === 'N/D' || (item.empty_reason && !dv);
    }).length;

    // Tipologia appalto
    const tipologiaAppaltoVal = getSimpleAnswer(byType('tipologia_di_appalto'));

    // Settore — read API JSON object fields
    const settoreItem = byType('settore_industriale_gara_appalto') || byType('settore_gara');
    const settoreJson = getJson(settoreItem);
    let settoreVal = '';
    if (settoreJson) {
      const code = settoreJson.prevalent_id_opere?.code || (typeof settoreJson.prevalent_id_opere === 'string' ? settoreJson.prevalent_id_opere : '');
      const cat = settoreJson.prevalent_categoria?.categoria || (typeof settoreJson.prevalent_categoria === 'string' ? settoreJson.prevalent_categoria : '');
      settoreVal = [code, cat].filter(Boolean).join(' ');
    }
    if (!settoreVal) settoreVal = getDisplayValue(settoreItem);

    // Timeline HTML
    const timelineHtml = `
      <div class="timeline">
        <div class="tl-title">Cronologia della gara</div>
        <div class="tl-track">
          <div class="tl-item">
            <div class="tl-dot ${dataUscitaVal ? 'done' : 'empty'}"></div>
            <div class="tl-body">
              <div class="tl-label">Pubblicazione</div>
              ${dataUscitaVal
                ? `<div class="tl-date">${escapeHtml(uscitaLong || dataUscitaVal)}</div>`
                : '<div class="tl-date" style="color:var(--gd-t2);font-weight:400;font-size:12px;font-style:italic">Data non disponibile</div>'}
            </div>
          </div>
          <div class="tl-item">
            <div class="tl-dot ${sopRequired ? 'warn' : 'empty'}"></div>
            <div class="tl-body">
              <div class="tl-label">Sopralluogo ${sopRequired ? 'obbligatorio' : 'facoltativo'}</div>
              ${sopDeadlineVal && sopDeadlineVal !== '—'
                ? `<div class="tl-date">${escapeHtml(sopDeadlineVal)}</div>`
                : '<div class="tl-date" style="color:var(--gd-t2);font-weight:400;font-size:12px;font-style:italic">Nessuna data prevista</div>'}
            </div>
          </div>
          <div class="tl-item">
            <div class="tl-dot ${dataScadenzaVal ? 'warn' : 'empty'}"></div>
            <div class="tl-body">
              <div class="tl-label">Scadenza presentazione offerte</div>
              ${dataScadenzaVal
                ? `<div class="tl-date">${escapeHtml(scadenzaLong || dataScadenzaVal)}</div>`
                : '<div class="tl-date" style="color:var(--gd-t2);font-weight:400;font-size:12px;font-style:italic">Data non disponibile</div>'}
              ${scadenzaDays !== null && scadenzaDays >= 0
                ? `<div class="countdown">&#9201; ${scadenzaDays} giorni al termine</div>`
                : (scadenzaDays !== null && scadenzaDays < 0
                    ? `<div class="countdown" style="background:var(--gd-red-l);color:var(--gd-red-t)">Scaduta da ${Math.abs(scadenzaDays)} giorni</div>`
                    : '')}
            </div>
          </div>
        </div>
      </div>
    `;

    // Sopralluogo card
    const sopCardBg = sopRequired ? 'var(--gd-amber-l)' : 'var(--gd-green-l)';
    const sopCardBorder = sopRequired ? '#e0c97a' : '#b5dcc5';
    const sopCardColor = sopRequired ? 'var(--gd-amber-t)' : 'var(--gd-green-t)';
    const sopIconBg = sopRequired ? 'var(--gd-amber)' : 'var(--gd-green)';
    const sopIcon = sopRequired
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    // Info procedurali
    const tipologiaGaraItem = byType('tipologia_di_gara');
    const criteriItem = byType('criteri_valutazione_offerta_tecnica');
    const inversioneText = findInCitations(tipologiaGaraItem, 'inversione procedimentale');
    const hasInversione = !!inversioneText;
    const metodoText = findInCitations(criteriItem, 'aggregativo') || findInCitations(criteriItem, 'metodo');
    const criterioText = findInCitations(tipologiaGaraItem, 'offerta economicamente') || findInCitations(tipologiaGaraItem, 'criterio');
    const procItems = [];
    procItems.push(`<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0"><span class="ic-l" style="margin:0">Inversione procedimentale</span><span class="gd-badge ${hasInversione ? 'gd-ba' : 'gd-bb'}" style="font-size:11px">${hasInversione ? 'Sì' : 'No'}</span></div>`);
    if (metodoText) procItems.push(`<div style="padding:4px 0"><span class="ic-l" style="margin:0">Metodo di aggiudicazione</span><div class="ic-v" style="font-size:13px;margin-top:2px">${escapeHtml(truncate(metodoText, 120))}</div></div>`);
    if (criterioText) procItems.push(`<div style="padding:4px 0"><span class="ic-l" style="margin:0">Criterio di aggiudicazione</span><div class="ic-v" style="font-size:13px;margin-top:2px">${escapeHtml(truncate(criterioText, 120))}</div></div>`);
    const procCardHtml = procItems.length > 0 ? `
        <div class="info-card">
          <div class="ic-l" style="font-weight:600;margin-bottom:6px">Informazioni procedurali</div>
          ${procItems.join('<div style="border-top:1px solid #eee"></div>')}
        </div>` : '';

    const rightColHtml = `
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div class="sop-card" style="background:${sopCardBg};border-color:${sopCardBorder};">
          <div class="sop-icon" style="background:${sopIconBg};">${sopIcon}</div>
          <div class="sop-body">
            <div class="sop-label" style="color:${sopCardColor};">Sopralluogo obbligatorio</div>
            <div class="sop-val" style="color:${sopCardColor};">${sopRequired ? 'Richiesto' : 'Non richiesto'}</div>
            ${sopDeadlineVal && sopDeadlineVal !== '—'
              ? `<div class="sop-note" style="color:${sopRequired ? 'var(--gd-amber)' : 'var(--gd-green)'};">${escapeHtml(sopDeadlineVal)}</div>`
              : ''}
            ${sopBookingUrl ? `<a class="sop-link" href="${escapeAttribute(sopBookingUrl)}" target="_blank" rel="noopener">Piattaforma prenotazione &#8599;</a>` : ''}
            ${sopDeadlineNotes ? `<div class="sop-note" style="margin-top:4px"><span class="gd-badge gd-ba" style="font-size:11px">${escapeHtml(sopDeadlineNotes)}</span></div>` : ''}
            ${sopBookingInstructions ? `<div class="sop-instr" style="margin-top:6px;font-size:12px;color:var(--gd-t2);line-height:1.4">${escapeHtml(truncate(sopBookingInstructions, 200))}</div>` : ''}
            ${sopBookingContacts.length > 0 ? `<div class="sop-contacts" style="margin-top:4px;font-size:12px">${sopBookingContacts.map(c => escapeHtml(typeof c === 'string' ? c : (c.name || c.email || JSON.stringify(c)))).join(', ')}</div>` : ''}
          </div>
        </div>
        <div class="g2" style="gap:10px;">
          <div class="gd-stat">
            <div class="gd-stat-l">Elementi estratti</div>
            <div class="gd-stat-v">${totalItems}</div>
            <div class="gd-stat-sub">campi elaborati</div>
          </div>
          <div class="gd-stat">
            <div class="gd-stat-l">Campi mancanti</div>
            <div class="gd-stat-v" style="${missingItems > 0 ? 'color:var(--gd-amber)' : ''}">${missingItems}</div>
            <div class="gd-stat-sub">${missingItems > 0 ? 'verifica manuale' : 'tutto estratto'}</div>
          </div>
        </div>
        ${tipologiaAppaltoVal ? `
        <div class="info-card">
          <div class="ic-l">Tipologia appalto</div>
          <div class="ic-v" style="font-size:14px;margin-bottom:6px">${escapeHtml(truncate(tipologiaAppaltoVal, 200))}</div>
          ${settoreVal ? `<div style="display:flex;gap:6px;flex-wrap:wrap;"><span class="gd-badge gd-bb">${escapeHtml(truncate(settoreVal, 60))}</span></div>` : ''}
        </div>` : (settoreVal ? `
        <div class="info-card">
          <div class="ic-l">Settore</div>
          <div class="ic-v">${escapeHtml(truncate(settoreVal, 200))}</div>
        </div>` : '')}
        ${procCardHtml}
      </div>
    `;

    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Panoramica</div>
        <div class="g2">
          ${timelineHtml}
          ${rightColHtml}
        </div>
      </div>
    `;
    showSection('gd-overview');
  }

  /**
   * Render the Servizi e Prestazioni richieste section.
   * Cross-extraction renderer: reads oggetto_appalto + importi_corrispettivi.
   */
  function renderServizi(byType) {
    const el = document.getElementById('gd-servizi');
    if (!el) return;

    const oggettoJson = getJson(byType('oggetto_appalto'));
    const corrispettiviItem = byType('importi_corrispettivi_categoria_id_opere');
    const corrispettiviJson = getJson(corrispettiviItem?.synthetic_source || corrispettiviItem);

    const servizi = oggettoJson?.servizi_previsti || [];
    const entries = corrispettiviJson?.entries || [];
    const qclMap = parseQclFromCitations(corrispettiviItem);

    if (servizi.length === 0 && entries.length === 0) return;

    // Service cards
    let serviziCardsHtml = '';
    if (servizi.length > 0) {
      serviziCardsHtml = `<div class="${servizi.length > 1 ? 'g2' : ''}" style="margin-bottom:16px">` +
        servizi.map(s => {
          const amount = (typeof s.amount_eur === 'number' && s.amount_eur > 0)
            ? formatEuro(s.amount_eur)
            : (s.amount_raw || '');
          const isObb = s.is_optional === false;
          return `
            <div class="info-card">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <div class="ic-l" style="margin:0;font-weight:600">${escapeHtml(s.label || s.service_type || 'Servizio')}</div>
                <span class="gd-badge ${isObb ? 'gd-br' : 'gd-bb'}">${isObb ? 'Obbligatorio' : 'Opzionale'}</span>
              </div>
              ${amount ? `<div class="ic-v" style="font-size:16px;font-weight:700;margin:4px 0">${escapeHtml(amount)}</div>` : ''}
              ${s.legal_reference ? `<div style="font-size:12px;color:var(--gd-t2);margin-top:4px">${escapeHtml(truncate(s.legal_reference, 150))}</div>` : ''}
              ${s.notes ? `<div style="font-size:12px;color:var(--gd-t2);margin-top:4px;font-style:italic">${escapeHtml(truncate(s.notes, 200))}</div>` : ''}
            </div>`;
        }).join('') + '</div>';
    }

    // Prestazioni table
    let tableHtml = '';
    if (entries.length > 0) {
      const hasQcl = qclMap.size > 0;
      const rows = entries.map(e => {
        const catId = e.category_id || e.id_opera || '';
        const parsed = qclMap.get(catId) || {};
        const amount = (typeof e.amount_eur === 'number') ? formatEuro(e.amount_eur) : (e.amount_raw || '—');
        return `<tr>
          <td>${escapeHtml(e.category_name || '—')}</td>
          <td><strong>${escapeHtml(catId)}</strong></td>
          ${hasQcl ? `<td>${escapeHtml(parsed.qclCodes || '—')}</td>` : ''}
          ${hasQcl ? `<td class="tv">${escapeHtml(parsed.complexityGrade || '—')}</td>` : ''}
          <td class="tv">${escapeHtml(amount)}</td>
        </tr>`;
      }).join('');

      const totalAmount = entries.reduce((sum, e) => {
        const v = (typeof e.amount_eur === 'number') ? e.amount_eur : 0;
        return sum + v;
      }, 0);

      tableHtml = `
        <div class="tcard">
          <div class="tcard-hd">Prestazioni per categoria</div>
          <table class="table--modern">
            <thead><tr>
              <th>Categoria</th><th>ID Opera</th>
              ${hasQcl ? '<th>Codici Prestazione</th><th>Grado Complessità</th>' : ''}
              <th>Importo</th>
            </tr></thead>
            <tbody>${rows}</tbody>
            ${totalAmount > 0 ? `<tfoot><tr>
              <td colspan="${hasQcl ? 4 : 2}" style="text-align:right;font-weight:600">Totale</td>
              <td class="tv" style="font-weight:700">${escapeHtml(formatEuro(totalAmount))}</td>
            </tr></tfoot>` : ''}
          </table>
        </div>`;
    }

    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Servizi e prestazioni richieste</div>
        ${serviziCardsHtml}
        ${tableHtml}
      </div>
    `;
    showSection('gd-servizi');
  }

  /**
   * Render the Importi e valori economici section.
   */
  function renderImporti(importiItems, byType) {
    const el = document.getElementById('gd-importi');
    if (!el) return;
    if (!importiItems || importiItems.length === 0) return;

    const opereItem = byType('importi_opere_per_categoria_id_opere');
    const corrispettiviItem = byType('importi_corrispettivi_categoria_id_opere');
    const requisitiTecItem = byType('importi_requisiti_tecnici_categoria_id_opere');
    const fatturatoItem = byType('fatturato_globale_n_minimo_anni');

    // Sum amounts — read entries[].amount_eur directly (API gives numbers)
    const opereSum = sumAmountEur(opereItem);
    const corrispettiviSum = sumAmountEur(corrispettiviItem);

    // Importo a base d'asta: opere sum, or oggetto_appalto.servizi_previsti sum
    let importoBaseAsta = opereSum;
    let importoBaseAstaLabel = 'Somma importi per categoria';
    if (importoBaseAsta === 0) {
      const oggettoJson = getJson(byType('oggetto_appalto'));
      if (oggettoJson?.servizi_previsti && Array.isArray(oggettoJson.servizi_previsti)) {
        importoBaseAsta = oggettoJson.servizi_previsti.reduce((sum, s) => {
          const v = (typeof s.amount_eur === 'number') ? s.amount_eur : parseItalianNumber(s.amount_eur || s.amount_raw);
          return sum + (isNaN(v) ? 0 : v);
        }, 0);
        if (importoBaseAsta > 0) importoBaseAstaLabel = 'Da oggetto dell\'appalto';
      }
    }

    // Fatturato — read API turnover_requirement directly (number, no re-parsing)
    let fatturatoVal = 0;
    if (fatturatoItem) {
      const fJson = getJson(fatturatoItem);
      if (fJson?.turnover_requirement?.single_requirement) {
        const sr = fJson.turnover_requirement.single_requirement;
        fatturatoVal = (typeof sr.minimum_amount_value === 'number') ? sr.minimum_amount_value : 0;
      }
      if (fatturatoVal === 0 && fJson) {
        const raw = fJson.importo_minimo ?? fJson.importo_minimo_eur ?? null;
        if (raw !== null) { const v = (typeof raw === 'number') ? raw : parseItalianNumber(raw); if (!isNaN(v)) fatturatoVal = v; }
      }
      if (fatturatoVal === 0) {
        const fv = getDisplayValue(fatturatoItem);
        const match = String(fv).match(/[\d.,]+/);
        if (match) { const v = parseItalianNumber(match[0]); if (!isNaN(v) && v > 0) fatturatoVal = v; }
      }
    }

    // First ID opera — read category_id directly from API entries
    // ID opera looks like "E.20", "IA.01", "S.03" — skip pure numbers
    let firstIdOpera = '';
    function isValidIdOpera(v) { return v && typeof v === 'string' && /[A-Za-z]/.test(v); }
    for (const src of [opereItem, corrispettiviItem, requisitiTecItem]) {
      if (firstIdOpera || !src) continue;
      const json = getJson(src);
      if (json?.entries?.length > 0) {
        for (const e of json.entries) {
          const candidate = e.category_id || e.id_opera || e.id_opera_normalized || '';
          if (isValidIdOpera(candidate)) { firstIdOpera = candidate; break; }
        }
      }
      if (!firstIdOpera && json?.requirements?.length > 0) {
        for (const r of json.requirements) {
          if (isValidIdOpera(r.id_opera)) { firstIdOpera = r.id_opera; break; }
        }
      }
    }

    // Stat cards
    const statCardsHtml = `
      <div class="g4">
        <div class="imp-card">
          <div class="imp-label">Importo a base d'asta</div>
          ${importoBaseAsta > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(importoBaseAsta))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${importoBaseAsta > 0 ? escapeHtml(importoBaseAstaLabel) : 'Non presente nel disciplinare'}</div>
        </div>
        <div class="imp-card">
          <div class="imp-label">Corrispettivo</div>
          ${corrispettiviSum > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(corrispettiviSum))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${corrispettiviSum > 0 ? 'Somma corrispettivi' : 'Non strutturato'}</div>
        </div>
        <div class="imp-card">
          <div class="imp-label">Fatturato minimo</div>
          ${fatturatoVal > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(fatturatoVal))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${fatturatoVal > 0 ? 'Requisito economico' : 'Non specificato'}</div>
        </div>
        <div class="imp-card">
          <div class="imp-label">Categoria ID Opera</div>
          ${firstIdOpera ? `<div class="imp-val" style="font-size:20px;">${escapeHtml(firstIdOpera)}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${firstIdOpera ? 'Prima categoria' : 'Non applicabile'}</div>
        </div>
      </div>
    `;

    // Detail tables for each importi type
    let tablesHtml = '';
    const importiTypesToRender = [
      { item: opereItem, label: 'Importi opere per categoria' },
      { item: corrispettiviItem, label: 'Corrispettivi per categoria' },
      { item: requisitiTecItem, label: 'Requisiti tecnici per categoria' },
    ];

    importiTypesToRender.forEach(({ item, label }) => {
      if (!item) return;
      const source = item.synthetic_source || item;
      const tabs = buildExtractionTabs(source);
      const responseTab = tabs.find(t => t.id === 'response');
      if (responseTab && responseTab.content && /<table[\s>]/i.test(responseTab.content)) {
        const collapseId = `imp-tbl-${Math.random().toString(36).slice(2, 8)}`;
        tablesHtml += `
          <div class="tcard collapsible open" style="margin-top:12px;">
            <div class="tcard-hd tcard-toggle" onclick="this.parentElement.classList.toggle('open')">${escapeHtml(label)} <svg class="chv" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
            <div class="tcard-body">${responseTab.content}</div>
          </div>
        `;
      }
    });

    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Importi e valori economici</div>
        ${statCardsHtml}
        ${tablesHtml}
      </div>
    `;
    showSection('gd-importi');

    // Initialize filterable tables
    initializeExtractionTables(el);
  }

  /**
   * Render the Requisiti tecnico-professionali section.
   */
  // Translate API requirement_type to Italian label
  const REQ_TYPE_IT = {
    registration: 'Iscrizione',
    experience: 'Esperienza',
    young_professional: 'Giovane professionista',
    team_composition: 'Composizione team',
    certification: 'Certificazione',
    soa_qualification: 'Qualificazione SOA',
    technical_director: 'Direttore tecnico',
    other: 'Altro',
  };

  function renderRequisiti(requisitiItems) {
    const el = document.getElementById('gd-requisiti');
    if (!el) return;
    if (!requisitiItems || requisitiItems.length === 0) return;

    let cardsHtml = '';

    requisitiItems.forEach(item => {
      const json = getJson(item?.synthetic_source || item);

      // API structure: requirements[] — use citations (Italian) as primary text
      if (json?.requirements && Array.isArray(json.requirements) && json.requirements.length > 0) {
        json.requirements.forEach(req => {
          const isObb = req.is_mandatory === true;
          const reqType = req.requirement_type || '';
          const reqTypeLabel = REQ_TYPE_IT[reqType] || reqType;
          const legalRef = req.legal_reference || '';
          const page = req.source_location?.page || req.citations?.[0]?.page || '';

          // Use citation text (Italian, from PDF) as primary description
          const citText = (req.citations || []).map(c => c.text || '').filter(Boolean).join(' ');
          // Fallback to section name from source_location (also Italian)
          const sectionName = req.source_location?.section || '';
          const desc = citText || sectionName || req.description || '';

          // Experience details with per-category amounts
          let experienceHtml = '';
          if (req.experience_details?.categories?.length > 0) {
            experienceHtml = `<div class="req-exp">${req.experience_details.categories.map(c =>
              `<span class="req-exp-chip">${escapeHtml(c.category_code || '')} ${c.minimum_amount_eur ? formatEuro(c.minimum_amount_eur) : ''}</span>`
            ).join('')}</div>`;
          }

          cardsHtml += `
            <div class="req-card">
              <div class="req-card-head">
                <div class="req-tags">
                  ${reqTypeLabel ? `<span class="gd-badge gd-bb">${escapeHtml(reqTypeLabel)}</span>` : ''}
                  <span class="req-obbl ${isObb ? 'si' : 'no'}">${isObb ? 'Obbligatorio' : 'Facoltativo'}</span>
                  ${page ? `<span class="req-page">pag. ${escapeHtml(String(page))}</span>` : ''}
                </div>
              </div>
              ${desc ? `<div class="req-desc expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(desc)}">${escapeHtml(truncate(desc, 150))}</div>` : ''}
              <div class="req-foot">
                ${legalRef ? `<span class="req-legal">${escapeHtml(legalRef)}</span>` : ''}
                ${experienceHtml}
              </div>
            </div>
          `;
        });
      } else if (json?.entries && Array.isArray(json.entries) && json.entries.length > 0) {
        json.entries.forEach(entry => {
          const name = entry.requisito || entry.titolo || entry.title || 'Requisito';
          const desc = entry.descrizione || entry.description || '';
          const obbStr = String(entry.obbligatorio || entry.is_mandatory || '').toLowerCase();
          const isObb = (obbStr === 'si' || obbStr === 'yes' || obbStr === 'true' || obbStr === '1');
          cardsHtml += `
            <div class="req-card">
              <div class="req-card-head">
                <div class="req-tags">
                  <span class="req-obbl ${isObb ? 'si' : 'no'}">${isObb ? 'Obbligatorio' : 'Facoltativo'}</span>
                </div>
              </div>
              <div class="req-desc expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(name + (desc ? ' — ' + desc : ''))}">${escapeHtml(truncate(name + (desc ? ' — ' + desc : ''), 150))}</div>
            </div>
          `;
        });
      } else {
        const dv = getDisplayValue(item);
        if (dv) {
          cardsHtml += `
            <div class="req-card">
              <div class="req-desc">${escapeHtml(truncate(dv, 250))}</div>
            </div>
          `;
        }
      }
    });

    if (!cardsHtml) return;
    el.innerHTML = `<div class="gd-sec"><div class="gd-sec-hd">Requisiti tecnico-professionali</div><div class="req-grid">${cardsHtml}</div></div>`;
    showSection('gd-requisiti');

    // Expand/collapse truncated text on click
    el.querySelectorAll('.req-desc.expandable').forEach(div => {
      const full = div.getAttribute('data-full');
      const short = div.textContent;
      if (full && full.length > short.length) {
        div.addEventListener('click', () => {
          const isOpen = div.classList.contains('open');
          div.textContent = isOpen ? short : full;
        });
      }
    });
  }

  /**
   * Render the Requisiti economico-finanziari section.
   */
  function renderEconomici(econItems, byType) {
    const el = document.getElementById('gd-economici');
    if (!el) return;
    if (!econItems || econItems.length === 0) return;

    const cards = [];

    // ── FATTURATO card ──
    const fatturatoItem = byType('fatturato_globale_n_minimo_anni');
    const fJson = fatturatoItem ? getJson(fatturatoItem) : null;
    const sr = fJson?.turnover_requirement?.single_requirement;

    if (sr) {
      let amountNum = (typeof sr.minimum_amount_value === 'number') ? sr.minimum_amount_value : parseItalianNumber(sr.minimum_amount_raw);
      const amount = (!isNaN(amountNum) && amountNum > 0) ? formatEuro(amountNum) : (sr.minimum_amount_raw || 'N/D');
      const citText = sr.source_text || sr.calculation_rule || '';
      const tc = sr.temporal_calculation;
      const temporalLabel = tc ? `Migliori ${tc.periods_to_select || '?'} su ${tc.lookback_window_years || '?'} anni` : '';
      const derivation = sr.derivation_formula || '';
      const scope = sr.service_scope_description || '';

      let chipsHtml = [temporalLabel, derivation, scope].filter(Boolean)
        .map(t => `<span class="gd-chip">${escapeHtml(truncate(t, 50))}</span>`).join('');

      cards.push(`
        <div class="info-card">
          <div class="ic-l">Fatturato globale minimo</div>
          <div class="ic-v" style="font-size:16px;font-weight:700;margin:4px 0">${escapeHtml(amount)}</div>
          ${citText ? `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(citText)}">${escapeHtml(truncate(citText, 200))}</div>` : ''}
          ${chipsHtml ? `<div class="gd-chips" style="margin-top:8px">${chipsHtml}</div>` : ''}
        </div>
      `);
    } else if (fatturatoItem) {
      const dv = getDisplayValue(fatturatoItem);
      if (dv) cards.push(`<div class="info-card"><div class="ic-l">Fatturato globale minimo</div><div class="ic-v">${escapeHtml(truncate(dv, 300))}</div></div>`);
    }

    // ── CAPACITA ECONOMICA card(s) ──
    const capacitaItem = byType('requisiti_di_capacita_economica_finanziaria');
    const cJson = capacitaItem ? getJson(capacitaItem) : null;

    if (cJson?.requirements?.length > 0) {
      cJson.requirements.forEach(req => {
        const citText = (cJson.citations || []).map(c => (c.text || []).join(' ')).find(t => t.length > 20) || '';
        const reqText = req.source_text || req.requirement_text || citText || '';
        const amounts = (req.minimum_amount || []).filter(a => a.value);
        const tf = req.timeframe;
        const rtiRules = req.rti_allocation?.distribution_rules || '';

        let chipsHtml = '';
        if (amounts.length) chipsHtml += amounts.map(a => `<span class="gd-chip">${escapeHtml(formatEuro(a.value))}</span>`).join('');
        if (tf) chipsHtml += `<span class="gd-chip">${escapeHtml(`${tf.selection_method === 'best_of' ? 'Migliori' : ''} ${tf.selected_count || ''}/${tf.total_window || ''} ${tf.unit || 'anni'}`.trim())}</span>`;

        cards.push(`
          <div class="info-card">
            <div class="ic-l">Capacita economico-finanziaria</div>
            ${reqText ? `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(reqText)}">${escapeHtml(truncate(reqText, 200))}</div>` : ''}
            ${chipsHtml ? `<div class="gd-chips" style="margin-top:8px">${chipsHtml}</div>` : ''}
            ${rtiRules ? `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute('RTI: ' + rtiRules)}" style="margin-top:8px;font-style:italic"><strong>RTI:</strong> ${escapeHtml(truncate(rtiRules, 150))}</div>` : ''}
          </div>
        `);
      });
    } else if (capacitaItem) {
      const dv = getDisplayValue(capacitaItem);
      if (dv) cards.push(`<div class="info-card"><div class="ic-l">Capacita economico-finanziaria</div><div class="ic-v">${escapeHtml(truncate(dv, 300))}</div></div>`);
    }

    if (!cards.length) return;

    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Requisiti economico-finanziari</div>
        <div class="${cards.length > 1 ? 'g2' : ''}">${cards.join('')}</div>
      </div>
    `;
    showSection('gd-economici');
    initializeExtractionTables(el);

    // Expand/collapse truncated text on click
    el.querySelectorAll('.expandable').forEach(div => {
      const full = div.getAttribute('data-full');
      const short = div.textContent;
      if (full && full.length > short.length) {
        div.addEventListener('click', () => {
          const isOpen = div.classList.contains('open');
          div.textContent = isOpen ? short : full;
        });
      }
    });
  }

  /**
   * Render the Documentazione e ruoli section.
   */
  function renderDocsRuoli(docsItems, job) {
    const el = document.getElementById('gd-docs-ruoli');
    if (!el) return;
    if (!docsItems || docsItems.length === 0) return;

    let leftHtml = '';
    let rightHtml = '';

    docsItems.forEach(item => {
      const typeCode = (item.type_code || item.tipo || '').toLowerCase();
      const source = item.synthetic_source || item;
      const json = getJson(source);
      const label = getTypeLabel(typeCode);

      if (typeCode === 'documentazione_richiesta_tecnica') {
        // API: documents[] → compact table
        if (json?.documents?.length > 0) {
          const rows = json.documents.map(doc => {
            const status = doc.requirement_status || '';
            const statusCls = status === 'obbligatorio' ? 'gd-br' : (status === 'condizionale' ? 'gd-ba' : 'gd-bb');
            const fmt = doc.formatting_requirements || {};
            const maxPages = fmt.max_pages || '—';
            const pageSize = fmt.page_size || '—';
            return `<tr>
              <td class="tt">${escapeHtml(truncate(doc.title || 'Documento', 80))}</td>
              <td><span class="gd-badge ${statusCls}">${escapeHtml(status || '—')}</span></td>
              <td class="tv">${escapeHtml(String(maxPages))}</td>
              <td class="tv">${escapeHtml(pageSize)}</td>
            </tr>`;
          }).join('');
          leftHtml += `
            <div class="tcard" style="margin-bottom:12px;">
              <div class="tcard-hd">${escapeHtml(label)} (${json.documents.length})</div>
              <table>
                <thead><tr><th>Documento</th><th>Stato</th><th>Pagine</th><th>Formato</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>`;
        } else {
          const tabs = buildExtractionTabs(source);
          const responseTab = tabs.find(t => t.id === 'response');
          if (responseTab?.content) leftHtml += `<div class="tcard" style="margin-bottom:12px;"><div class="tcard-hd">${escapeHtml(label)}</div><div style="overflow-x:auto;">${responseTab.content}</div></div>`;
        }
      } else if (typeCode === 'criteri_valutazione_offerta_tecnica') {
        // API: criteria[] with subcriteria[], max_points, total_max_points
        if (json?.criteria?.length > 0) {
          const totalPts = json.total_max_points || '';
          const criteriaHtml = json.criteria.map(crit => {
            const subsHtml = (crit.subcriteria || []).map(sub =>
              `<div class="crit-sub"><span class="crit-sub-label">${escapeHtml(sub.label || '')}</span><span class="crit-sub-title">${escapeHtml(truncate(sub.title || '', 120))}</span><span class="crit-sub-pts">${sub.max_points || 0}</span></div>`
            ).join('');
            return `<div class="crit-group"><div class="crit-head"><span class="crit-label">${escapeHtml(crit.label || '')}</span><span class="crit-title">${escapeHtml(truncate(crit.title || '', 100))}</span><span class="crit-pts">${crit.max_points || 0} pt</span></div>${subsHtml}</div>`;
          }).join('');
          leftHtml += `<div class="tcard" style="margin-bottom:12px;"><div class="tcard-hd">${escapeHtml(label)}${totalPts ? ` — ${totalPts} punti totali` : ''}</div><div style="padding:10px;">${criteriaHtml}</div></div>`;
        } else {
          const tabs = buildExtractionTabs(source);
          const responseTab = tabs.find(t => t.id === 'response');
          if (responseTab?.content) leftHtml += `<div class="tcard" style="margin-bottom:12px;"><div class="tcard-hd">${escapeHtml(label)}</div><div style="overflow-x:auto;">${responseTab.content}</div></div>`;
        }
      } else if (typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
        // Build a role-map: id → {name, qualifications}
        const roles = json?.roles || [];
        const reqs = json?.requirements || [];

        // Map requirements to role IDs for qualification lookup
        const qualsByRoleId = {};
        reqs.forEach(req => {
          const quals = (req.qualifications || []).map(q => q.description || q.type || '').filter(Boolean);
          const roleIds = req.applies_to_all_roles ? roles.map(r => r.id) : (req.applies_to_role_ids || []);
          roleIds.forEach(rid => {
            if (!qualsByRoleId[rid]) qualsByRoleId[rid] = [];
            qualsByRoleId[rid].push(...quals);
          });
        });

        if (roles.length > 0) {
          // Group roles by phase
          const byPhase = {};
          roles.forEach(role => {
            const phases = role.applies_to_phases?.length > 0 ? role.applies_to_phases : ['Altro'];
            phases.forEach(phase => {
              if (!byPhase[phase]) byPhase[phase] = [];
              byPhase[phase].push(role);
            });
          });

          let tableRows = '';
          let phaseIdx = 0;
          Object.entries(byPhase).forEach(([phase, phaseRoles]) => {
            const pid = 'ph-' + (phaseIdx++);
            tableRows += `<tr class="prof-phase" data-phase="${pid}" onclick="this.classList.toggle('closed');this.closest('table').querySelectorAll('tr[data-group=\\'${pid}\\']').forEach(r=>r.classList.toggle('hidden'))"><td colspan="2"><svg class="chv" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg> ${escapeHtml(phase)} (${phaseRoles.length})</td></tr>`;
            phaseRoles.forEach(role => {
              const quals = qualsByRoleId[role.id] || [];
              tableRows += `<tr data-group="${pid}">
                <td class="prof-name">${escapeHtml(role.name || 'Ruolo')}</td>
                <td class="prof-quals">${quals.length > 0
                  ? quals.map(q => `<span class="gd-chip">${escapeHtml(truncate(q, 50))}</span>`).join('')
                  : '<span style="color:var(--gd-t2);font-size:11px">—</span>'}</td>
              </tr>`;
            });
          });

          rightHtml += `
            <div class="tcard" style="margin-bottom:12px;">
              <div class="tcard-hd">Gruppo di lavoro (${roles.length} ruoli)</div>
              <table class="prof-table">
                <thead><tr><th>Ruolo</th><th>Qualifiche richieste</th></tr></thead>
                <tbody>${tableRows}</tbody>
              </table>
            </div>`;
        } else if (reqs.length > 0) {
          // No roles, just requirements
          const reqRows = reqs.map(req => {
            const reqText = req.original_text || req.description || '';
            const quals = (req.qualifications || []).map(q => q.description || q.type || '').filter(Boolean);
            return `<tr><td class="tt">${escapeHtml(truncate(reqText, 120))}</td><td class="tv">${quals.length > 0 ? quals.map(q => `<span class="gd-chip">${escapeHtml(truncate(q, 50))}</span>`).join(' ') : '—'}</td></tr>`;
          }).join('');
          rightHtml += `
            <div class="tcard" style="margin-bottom:12px;">
              <div class="tcard-hd">Requisiti professionali (${reqs.length})</div>
              <table><thead><tr><th>Requisito</th><th>Qualifiche</th></tr></thead><tbody>${reqRows}</tbody></table>
            </div>`;
        } else {
          // Fallback to entries
          const parsed = json || {};
          if (parsed.entries?.length > 0) {
            parsed.entries.forEach(entry => {
              rightHtml += `<div class="req-card" style="margin-bottom:10px;"><div class="req-card-head"><div class="req-name">${escapeHtml(entry.requisito || entry.ruolo || 'Requisito')}</div></div>${entry.descrizione ? `<div class="req-desc expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(entry.descrizione)}">${escapeHtml(truncate(entry.descrizione, 150))}</div>` : ''}</div>`;
            });
          }
        }
      } else if (typeCode === 'documenti_di_gara') {
        const parsed = json || {};
        let chipItems = [];
        if (parsed.entries) chipItems = parsed.entries.map(e => e.documento || e.titolo || e.nome || stringifyValue(e)).filter(Boolean);
        // Fallback: the uploaded PDF is itself a document
        if (chipItems.length === 0 && job?.file_name) {
          chipItems.push(job.file_name);
        }
        if (chipItems.length > 0) {
          // Make chips clickable to open in media viewer (for the uploaded file)
          const pdfUrl = job?.job_id ? `ajax.php?section=gare&action=downloadOriginalPdf&job_id=${job.job_id}` : '';
          const chipsHtml = chipItems.map((c, i) => {
            if (pdfUrl && i === 0) {
              // First chip = uploaded file, make it clickable
              return `<span class="gd-chip cta" data-pdf-url="${escapeAttribute(pdfUrl)}" data-pdf-name="${escapeAttribute(c)}">${escapeHtml(truncate(c, 80))}</span>`;
            }
            return `<span class="gd-chip">${escapeHtml(truncate(c, 80))}</span>`;
          }).join('');
          rightHtml += `<div class="info-card" style="margin-bottom:12px;"><div class="ic-l">Documenti di gara</div><div class="gd-chips" style="margin-top:8px;">${chipsHtml}</div></div>`;
        }
      }
    });

    if (!leftHtml && !rightHtml) return;
    const hasTwo = leftHtml && rightHtml;
    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Documentazione e ruoli</div>
        ${hasTwo ? `<div class="g2"><div>${leftHtml}</div><div>${rightHtml}</div></div>` : `<div>${leftHtml || rightHtml}</div>`}
      </div>
    `;
    showSection('gd-docs-ruoli');
    initializeExtractionTables(el);

    // Wire clickable document chips to media viewer
    el.querySelectorAll('.gd-chip[data-pdf-url]').forEach(chip => {
      chip.addEventListener('click', () => {
        const url = chip.getAttribute('data-pdf-url');
        const name = chip.getAttribute('data-pdf-name') || 'Documento';
        if (typeof window.showMediaViewer === 'function') {
          window.showMediaViewer(url, {
            nome_file: name,
            titolo: name,
            mime_type: 'application/pdf'
          });
        } else {
          window.open(url, '_blank');
        }
      });
    });
  }

  /**
   * Render the fallback "Tutti i campi estratti" table for items not shown in any section.
   */
  function renderAllFields(fallbackItems, job) {
    const el = document.getElementById('gd-all-fields');
    if (!el) return;
    if (!fallbackItems || fallbackItems.length === 0) return;

    let rowIdx = 0;
    const rowsHtml = fallbackItems.map(item => {
      const rid = `af-${job.job_id}-${rowIdx++}`;
      const typeCode = (item.type_code || item.tipo || item.type || '').toLowerCase();
      const typeLabel = getTypeLabel(typeCode) || resolveExtractionType(item).label;
      const value = renderValueCell(item);
      const hasValue = value && value !== '—' && !value.includes('empty-reason');

      // Build detail content (reuse existing tabbed detail)
      const source = item.synthetic_source || item;
      const detailHtml = renderTabbedDetail(buildExtractionTabs(source));

      return `
        <tr class="dr" data-id="${rid}">
          <td class="tt">${escapeHtml(typeLabel)}</td>
          <td class="tv${hasValue ? '' : ' abs'}">${value}</td>
          <td><button class="dbtn" onclick="(function(){var e=document.getElementById('e-${rid}');var a=document.getElementById('ar-${rid}');var b=a&&a.closest('.dbtn');var o=e.classList.contains('show');e.classList.toggle('show',!o);if(a)a.classList.toggle('open',!o);if(b)b.classList.toggle('on',!o);})()"><i class="ar" id="ar-${rid}">&#8250;</i> Dettagli</button></td>
        </tr>
        <tr class="er" id="e-${rid}">
          <td colspan="3">
            <div class="er-inner${hasValue ? '' : ' w'}">
              <div class="details-inner">${detailHtml}</div>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    el.innerHTML = `
      <div class="gd-sec">
        <div class="tcard collapsible">
          <div class="tcard-hd tcard-toggle" onclick="this.parentElement.classList.toggle('open')">Tutti i campi estratti (${fallbackItems.length}) <svg class="chv" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></div>
          <div class="tcard-body">
            <table>
              <colgroup><col class="ca"><col class="cb"><col class="cc"></colgroup>
              <thead><tr><th>Campo</th><th>Valore estratto</th><th>Azioni</th></tr></thead>
              <tbody>${rowsHtml}</tbody>
            </table>
          </div>
        </div>
      </div>
    `;
    showSection('gd-all-fields');
    initializeExtractionTables(el);

    // Render highlighted PDF links for fallback items
    const detailInners = el.querySelectorAll('.details-inner');
    detailInners.forEach((detailInner, index) => {
      const item = fallbackItems[index];
      if (item) {
        renderHighlightedPdfLinks(
          { ...item, ext_job_id: item.ext_job_id || job.ext_job_id, job_id: job.job_id },
          detailInner
        );
      }
    });

    // Attach tab handlers within the fallback section
    attachDetailHandlers(el);
  }

  /**
   * Render the action bar with buttons.
   */
  function renderActionBar(job) {
    const el = document.getElementById('gd-actions');
    if (!el) return;

    el.className = 'gd-actions';
    el.innerHTML = `
      <button class="abtn pri" onclick="window.print()">&#8595; Stampa</button>
      <button class="abtn" id="gd-btn-reextract">&#8635; Ri-estrai</button>
      <div class="sp"></div>
      <button class="abtn del" id="gd-btn-delete">&#10005; Elimina gara</button>
    `;
    showSection('gd-actions');

    // Re-extract handler
    const reextractBtn = document.getElementById('gd-btn-reextract');
    if (reextractBtn) {
      reextractBtn.addEventListener('click', async () => {
        if (!confirm('Vuoi ripetere l\'estrazione? I dati attuali verranno sostituiti.')) return;
        try {
          reextractBtn.disabled = true;
          reextractBtn.textContent = 'Estrazione in corso...';
          const res = await customFetch('gare', 'reExtract', { job_id: job.job_id });
          if (res && res.success) {
            window.location.reload();
          } else {
            alert(res?.message || 'Errore durante la ri-estrazione');
            reextractBtn.disabled = false;
            reextractBtn.innerHTML = '&#8635; Ri-estrai';
          }
        } catch (e) {
          console.error('Re-extract error:', e);
          alert('Errore durante la ri-estrazione');
          reextractBtn.disabled = false;
          reextractBtn.innerHTML = '&#8635; Ri-estrai';
        }
      });
    }

    // Delete handler
    const deleteBtn = document.getElementById('gd-btn-delete');
    if (deleteBtn && garaId) {
      deleteBtn.addEventListener('click', async () => {
        if (!confirm('Sei sicuro di voler eliminare questa gara? L\'operazione non e reversibile.')) return;
        try {
          deleteBtn.disabled = true;
          const res = await customFetch('gare', 'deleteGara', { gara_id: garaId });
          if (res && res.success) {
            window.location.href = '/index.php?page=elenco_gare';
          } else {
            alert(res?.message || 'Errore durante l\'eliminazione');
            deleteBtn.disabled = false;
          }
        } catch (e) {
          console.error('Delete error:', e);
          alert('Errore durante l\'eliminazione');
          deleteBtn.disabled = false;
        }
      });
    }
  }

  /**
   * Restituisce la chiave di ordinamento per un type_code
   * Se il type_code è nella mappa, usa l'indice corrispondente (1-19)
   * Altrimenti assegna 1000+ per ordinamento alfabetico in coda
   */
  function getSortKeyForType(typeCode) {
    if (!typeCode || typeof typeCode !== 'string') {
      return 10000;
    }
    const normalized = typeCode.toLowerCase().trim();
    if (DETTAGLIO_GARA_ORDER.hasOwnProperty(normalized)) {
      return DETTAGLIO_GARA_ORDER[normalized];
    }
    // Type_code non nella mappa → finisce in coda con ordinamento alfabetico
    // Usa 1000 come base + hash del nome per ordinamento alfabetico stabile
    const baseOffset = 1000;
    const typeLower = normalized;
    let alphabeticalOffset = 0;
    const maxChars = Math.min(3, typeLower.length);
    for (let i = 0; i < maxChars; i++) {
      const char = typeLower.charCodeAt(i) - 'a'.charCodeAt(0);
      if (char >= 0 && char <= 25) {
        alphabeticalOffset += char * Math.pow(26, 2 - i);
      }
    }
    alphabeticalOffset = Math.min(999, Math.max(0, alphabeticalOffset));
    return baseOffset + alphabeticalOffset;
  }

  /**
   * Ordina gli items usando DETTAGLIO_GARA_ORDER
   */
  function sortExtractionItems(items) {
    if (!Array.isArray(items)) return items;
    return [...items].sort((a, b) => {
      const typeA = (a.type_code || a.tipo || a.type || '').toLowerCase().trim();
      const typeB = (b.type_code || b.tipo || b.type || '').toLowerCase().trim();
      
      const orderA = getSortKeyForType(typeA);
      const orderB = getSortKeyForType(typeB);
      
      if (orderA === orderB) {
        // Se hanno lo stesso ordine, ordina alfabeticamente per type_code
        if (typeA === typeB) {
          return 0;
        }
        return typeA.localeCompare(typeB);
      }
      
      return orderA - orderB;
    });
  }

  /**
   * Verifica se una stringa è una data in formato ISO (yyyy-mm-dd)
   */
  function isISODateString(str) {
    if (!str || typeof str !== 'string') return false;
    const trimmed = str.trim();
    // Pattern per yyyy-mm-dd (con o senza ora)
    const isoPattern = /^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}(?::\d{2})?)?$/;
    return isoPattern.test(trimmed);
  }

  /**
   * Formatta una data in formato italiano (dd/mm/yyyy)
   * Gestisce sia stringhe ISO che oggetti Date
   */
  function formatDateItalian(dateValue) {
    if (!dateValue) return null;
    
    // Se è già formattato in italiano, restituiscilo così com'è
    if (typeof dateValue === 'string' && /^\d{2}\/\d{2}\/\d{4}/.test(dateValue.trim())) {
      return dateValue.trim();
    }
    
    // Usa formatDate globale se disponibile
    if (typeof formatDate === 'function') {
      const formatted = formatDate(dateValue);
      if (formatted) return formatted;
    }
    
    // Fallback: parsing manuale
    if (typeof dateValue === 'string') {
      const trimmed = dateValue.trim();
      // Se è formato ISO (yyyy-mm-dd), convertilo
      if (isISODateString(trimmed)) {
        const parts = trimmed.split(' ')[0].split('-'); // Prendi solo la parte data
        if (parts.length === 3) {
          return `${parts[2]}/${parts[1]}/${parts[0]}`;
        }
      }
      // Se è già formato italiano, restituiscilo
      if (/^\d{2}\/\d{2}\/\d{4}/.test(trimmed)) {
        return trimmed;
      }
    }
    
    // Prova a parsare come Date
    try {
      const date = new Date(dateValue);
      if (!isNaN(date.getTime())) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
      }
    } catch (e) {
      // Ignora errori di parsing
    }
    
    return null;
  }

  function renderValueCell(item) {
    const valueState = (item.value_state || '').toLowerCase();
    if (valueState === 'see_citations') {
      return renderSeeCitationsSummary(item);
    }
    
    const typeCode = (item.type_code || item.tipo || item.type || '').toLowerCase();
    const isDateType = typeCode === 'data_scadenza_gara_appalto' || 
                       typeCode === 'data_uscita_gara_appalto' ||
                       typeCode === 'sopralluogo_deadline' ||
                       typeCode.includes('data') || 
                       typeCode.includes('deadline');
    
    if (valueState === 'boolean' || valueState === 'scalar') {
      const rawValue = item.display_value || extractPrimaryValue(item);
      const text = stringifyValue(rawValue);
      
      // Se è un tipo data o il valore è una data ISO, formattalo
      if (isDateType || (text && isISODateString(text))) {
        const formatted = formatDateItalian(rawValue || text);
        if (formatted) {
          return escapeHtml(formatted);
        }
      }
      
      return text ? escapeHtml(text) : '&mdash;';
    }

    const displayValue = item.display_value || item.valore_display;

    if (typeCode === 'requisiti_tecnico_professionali' || typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
      // Se c'è una tabella pre-costruita, mostra il conteggio
      if (item.table && Array.isArray(item.table.rows) && item.table.rows.length > 0) {
        const count = item.table.rows.length;
        return `<span class="table-badge" title="Tabella con ${count} ${count === 1 ? 'requisito' : 'requisiti'}">${count} requisito/i</span>`;
      }
      // Altrimenti usa display_value o conteggio da entries
      const summarySource =
        displayValue !== undefined && displayValue !== null
          ? displayValue
          : extractPrimaryValue(item);
      const summaryText = stringifyValue(summarySource);
      if (summaryText) {
        return `<span class="value-chip">${escapeHtml(
          truncate(summaryText.replace(/\s+/g, ' '), 160)
        )}</span>`;
      }
      if (Array.isArray(item.requirements) && item.requirements.length) {
        const count = item.requirements.length;
        return `<span class="table-badge" title="Tabella con ${count} ${count === 1 ? 'requisito' : 'requisiti'}">${count} requisito/i</span>`;
      }
    }

    if (typeCode === 'sopralluogo_obbligatorio') {
      const parsedValueJson = parseJsonObject(item.value_json);
      const boolAnswer = resolveSopralluogoBool(parsedValueJson, item);
      const statusChip = boolAnswer === true
        ? `<span class="value-chip value-chip-deadline sopralluogo-required">Sopralluogo obbligatorio</span>`
        : `<span class="value-chip sopralluogo-not-required">Nessun obbligo di sopralluogo</span>`;
      const deadlineLabel = resolveSopralluogoDeadline(item, parsedValueJson);
      const deadlineChip = deadlineLabel
        ? `<span class="value-chip value-chip-deadline">${escapeHtml(deadlineLabel)}</span>`
        : '';
      return [statusChip, deadlineChip].filter(Boolean).join('<br>');
    }

    const parsedValueJson = parseJsonObject(item.value_json);
    if (parsedValueJson && Array.isArray(parsedValueJson.entries) && parsedValueJson.entries.length) {
      const count = parsedValueJson.entries.length;
      const tooltip = count === 1 ? '1 elemento' : `${count} elementi`;
      return `<span class="table-badge" title="${escapeHtml(tooltip)}">${count} elementi</span>`;
    }
    
    if (item.table && Array.isArray(item.table.rows) && item.table.rows.length) {
      const tableRows = item.table.rows.length;
      const tooltip =
        tableRows === 1 ? 'Tabella con 1 riga' : `Tabella con ${tableRows} righe`;
      return `<span class="table-badge" title="${escapeHtml(tooltip)}">${escapeHtml(
        summarizeTable(item.table)
      )}</span>`;
    }

    if (displayValue) {
      const displayText = stringifyValue(displayValue);
      
      // Se è un tipo data o il valore è una data ISO, formattalo
      if (isDateType || (displayText && isISODateString(displayText))) {
        const formatted = formatDateItalian(displayValue || displayText);
        if (formatted) {
          return `<span class="value-chip">${escapeHtml(formatted)}</span>`;
        }
      }
      
      if (displayText) {
        return `<span class="value-chip">${escapeHtml(displayText)}</span>`;
      }
    }
    if (item.empty_reason) {
      return `<span class="value-chip value-chip-empty">${escapeHtml(
        truncate(item.empty_reason, 160)
      )}</span>`;
    }
    const primaryValue = extractPrimaryValue(item);
    
    // Se è un tipo data o il valore è una data ISO, formattalo
    if (isDateType || (primaryValue && isISODateString(stringifyValue(primaryValue)))) {
      const formatted = formatDateItalian(primaryValue);
      if (formatted) {
        return `<span class="value-chip">${escapeHtml(formatted)}</span>`;
      }
    }
    
    // Usa formatDate globale come fallback
    const formatted = formatDate(primaryValue);
    const text = stringifyValue(primaryValue);
    if (formatted) {
      return `<span class="value-chip">${escapeHtml(formatted)}</span>`;
    }
    if (!text) return '—';
    return `<span class="value-chip">${escapeHtml(truncate(text, 120))}</span>`;
  }

  function collectCitations(item, parsedValueJson, parsedContextJson) {
    const citations = [];
    const addList = (list) => {
      if (!Array.isArray(list)) return;
      list.forEach((cit) => {
        if (cit && typeof cit === 'object') {
          citations.push(cit);
        }
      });
    };

    addList(item?.citations);

    if (parsedValueJson === undefined) {
      parsedValueJson = parseJsonObject(item?.value_json);
    }
    if (parsedValueJson && typeof parsedValueJson === 'object') {
      addList(parsedValueJson.citations);
    }

    if (parsedContextJson === undefined) {
      parsedContextJson = parseJsonObject(item?.context);
    }
    if (parsedContextJson && typeof parsedContextJson === 'object') {
      addList(parsedContextJson.citations);
    }

    const seen = new Set();
    return citations.filter((cit) => {
      const page = cit.page_number || cit.page || '';
      const textArray = Array.isArray(cit.text) ? cit.text : (cit.text ? [cit.text] : []);
      const key = `${page}|${textArray.join(' ')}|${cit.reason_for_relevance || ''}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function renderSeeCitationsSummary(item) {
    const citations = collectCitations(item);
    const pages = Array.from(new Set(citations
      .map((cit) => cit.page_number || cit.page)
      .filter((page) => page !== null && page !== undefined)));
    const pageLabel = pages.length
      ? `Consulta citazioni (pag. ${pages.join(', ')})`
      : 'Consulta citazioni nel documento';
    return `
      <div class="see-citations-summary">
        <span class="see-citations-summary-note">Nessun dato estratto automaticamente</span>
        <span class="see-citations-summary-pages">${escapeHtml(pageLabel)}</span>
      </div>
    `;
  }

  function renderSeeCitationsDetail(item, parsedValueJson, parsedContextJson) {
    const citations = collectCitations(item, parsedValueJson, parsedContextJson);
    // NON usare chain_of_thought come messaggio principale se ci sono entries vuoti
    const hasEmptyEntries = parsedValueJson && Array.isArray(parsedValueJson.entries) && parsedValueJson.entries.length === 0;
    const rawMessage = (!hasEmptyEntries && parsedValueJson && typeof parsedValueJson.chain_of_thought === 'string' && parsedValueJson.chain_of_thought.trim())
      || (item.display_value && item.display_value.trim())
      || (item.empty_reason && item.empty_reason.trim())
      || 'Informazione non recuperata automaticamente. Consulta le citazioni per i riferimenti.';
    const message = truncate(rawMessage, 420);

    const rowsHtml = citations.map((cit) => {
      const page = cit.page_number || cit.page;
      const pageText = page ? `Pag. ${page}` : '—';
      const textSegments = Array.isArray(cit.text) ? cit.text : (cit.text ? [cit.text] : []);
      const context = textSegments.filter(Boolean).join(' — ');
      const reason = cit.reason_for_relevance ? truncate(cit.reason_for_relevance, 360) : '';
      const link = cit.highlight_rel_path
        ? `<a class="see-citation-link" href="${escapeAttribute(cit.highlight_rel_path)}" target="_blank" rel="noopener">PDF evidenziato</a>`
        : '—';
      return `
        <tr>
          <td>${escapeHtml(pageText)}</td>
          <td>${escapeHtml(truncate(context, 360) || '—')}</td>
          <td>${escapeHtml(reason || '—')}</td>
          <td>${link}</td>
        </tr>
      `;
    }).join('');

    const tableHtml = rowsHtml
      ? `
        <div class="table-wrapper see-citations-table-wrapper">
          <table class="table table-filterable table-compact see-citations-table">
            <thead>
              <tr>
                <th>Pagina</th>
                <th>Contesto</th>
                <th>Motivazione</th>
                <th>Highlight</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
      `
      : '';

    return `
      <div class="see-citations-detail">
        <p>${escapeHtml(message)}</p>
        ${tableHtml}
      </div>
    `;
  }

  function buildSopralluogoDetails(item, parsedValueJson) {
    const boolAnswer = resolveSopralluogoBool(parsedValueJson, item);
    const deadlineLabel = resolveSopralluogoDeadline(item, parsedValueJson);
    let audit = null;
    if (parsedValueJson && typeof parsedValueJson === 'object' && parsedValueJson.chain_of_thought) {
      audit = parsedValueJson.chain_of_thought;
    } else if (item && item.display_value) {
      audit = item.display_value;
    } else if (item && item.empty_reason) {
      audit = item.empty_reason;
    }
    return {
      required: Boolean(boolAnswer),
      deadline: deadlineLabel,
      audit: audit || '',
    };
  }

  function renderTable(tableData, compact = false) {
    const purified = sanitizeTableData(tableData);
    const headers = purified.headers;
    const rows = purified.rows;

    const forceRenderEmptyTable =
      Boolean(tableData && tableData.keep_all_columns) && headers.length > 0;

    if (
      (!rows.length || (rows[0] && rows[0].length === 0)) &&
      tableData.synthetic !== 'sopralluogo' &&
      !forceRenderEmptyTable
    ) {
      return '<div class="empty-table">—</div>';
    }

    const baseTableClass = compact ? 'table table-filterable table-compact' : 'table table-filterable table-extraction';
    const customCssClass = tableData.css_class || '';
    const tableClass = customCssClass ? `${baseTableClass} ${customCssClass}` : baseTableClass;

    const headerHtml = headers.length
      ? `<tr>${headers.map((h) => `<th>${escapeHtml(h ?? '')}</th>`).join('')}</tr>`
      : '';

    const rowsHtml = rows
      .map((row) => {
        const cells = Array.isArray(row) ? row : [];
        return `<tr>${cells
          .map((cell) => `<td>${escapeHtml(extractTableCellValue(cell))}</td>`)
          .join('')}</tr>`;
      })
      .join('');

    const source = tableData.source_csv && !compact
      ? `<div class="table-source"><a href="${escapeAttribute(tableData.source_csv)}" target="_blank" rel="noopener">Scarica CSV originale</a></div>`
      : '';

    return `
      <div class="table-wrapper extraction-table-wrapper">
        <table class="${tableClass}" data-extraction-table="true">
          ${headerHtml ? `<thead>${headerHtml}</thead>` : ''}
          <tbody>${rowsHtml}</tbody>
        </table>
        ${source}
      </div>
    `;
  }

  function escapeAttribute(value) {
    return String(value).replace(/"/g, '&quot;');
  }

  function sanitizeForJson(value, seen = new WeakSet()) {
    if (value === null || value === undefined) return value;
    if (typeof value !== 'object') return value;
    if (value instanceof Date) return value.toISOString();
    if (seen.has(value)) return '[Circular]';
    seen.add(value);
    if (Array.isArray(value)) {
      return value.map((entry) => sanitizeForJson(entry, seen));
    }
    const out = {};
    Object.keys(value).forEach((key) => {
      const child = value[key];
      if (typeof child === 'function' || child === undefined) return;
      out[key] = sanitizeForJson(child, seen);
    });
    return out;
  }

  function parseJsonObject(source) {
    if (source === null || source === undefined) return null;
    if (typeof source === 'string') {
      const trimmed = source.trim();
      if (!trimmed) return null;
      try {
        return JSON.parse(trimmed);
      } catch (_) {
        return null;
      }
    }
    if (typeof source === 'object') {
      return source;
    }
    return null;
  }

  function buildExtractionTabs(item) {
    const sourceItem = item && item.synthetic_source ? item.synthetic_source : item;
    const tabs = [];

    const parsedValueJson = parseJsonObject(sourceItem.value_json);
    const parsedContextJson = parseJsonObject(sourceItem.context);
    const valueState = (sourceItem.value_state || item.value_state || '').toLowerCase();
    const typeCode = sourceItem.type_code || sourceItem.tipo || sourceItem.type;

    if (valueState === 'see_citations') {
      tabs.push({
        id: 'response',
        label: 'Risposta',
        content: renderSeeCitationsDetail(sourceItem, parsedValueJson, parsedContextJson),
        active: true,
      });
    } else if (typeCode === 'importi_corrispettivi_categoria_id_opere') {
      // Per importi_corrispettivi_categoria_id_opere, usa ESCLUSIVAMENTE la tabella pre-costruita dal backend
      // Ignora completamente i dati raw dall'AI (context, motivation, fallback in inglese)
      if (item.table && Array.isArray(item.table.headers) && Array.isArray(item.table.rows) && item.table.rows.length > 0) {
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderTable({ headers: item.table.headers, rows: item.table.rows }),
          active: true,
        });
      } else if (parsedValueJson && Array.isArray(parsedValueJson.entries) && parsedValueJson.entries.length > 0) {
        // Se non c'è tabella ma ci sono entries normalizzate, usale
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderEntriesTable(parsedValueJson.entries, typeCode, []),
          active: true,
        });
      } else {
        // Nessun dato normalizzato disponibile
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: '<div class="response-value empty-reason">Dati non disponibili</div>',
          active: true,
        });
      }
    } else if (typeCode === 'importi_requisiti_tecnici_categoria_id_opere') {
      // Per importi_requisiti_tecnici_categoria_id_opere, usa la tabella pre-costruita dal backend
      // (dati da gar_gara_requisiti_tecnici_categoria)
      if (item.table && Array.isArray(item.table.headers) && Array.isArray(item.table.rows) && item.table.rows.length > 0) {
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderTable({ headers: item.table.headers, rows: item.table.rows }),
          active: true,
        });
      } else if (parsedValueJson && Array.isArray(parsedValueJson.requirements) && parsedValueJson.requirements.length > 0) {
        // Se non c'è tabella ma ci sono requirements, costruisci la tabella da requirements
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderEntriesTable(parsedValueJson.requirements, typeCode, []),
          active: true,
        });
      } else {
        // Nessun dato disponibile
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: '<div class="response-value empty-reason">Dati non disponibili</div>',
          active: true,
        });
      }
    } else if (parsedValueJson && Array.isArray(parsedValueJson.entries)) {
      // Gestisci anche il caso di entries vuoto ma con citazioni disponibili
      // Priorità: usa entries dal JSON invece della tabella pre-costruita dal backend
      const citations = collectCitations(sourceItem, parsedValueJson, parsedContextJson);
      tabs.push({
        id: 'response',
        label: 'Risposta',
        content: renderEntriesTable(parsedValueJson.entries, typeCode, citations),
        active: true,
      });
    } else if (item.table && Array.isArray(item.table.rows) && item.table.rows.length) {
      // Fallback: usa la tabella pre-costruita dal backend se entries non è disponibile
      tabs.push({
        id: 'response',
        label: 'Risposta',
        content: renderTable(item.table),
        active: true,
      });
    } else if (typeCode === 'requisiti_tecnico_professionali') {
      // Per requisiti_tecnico_professionali, usa la tabella pre-costruita dal backend
      // (dati da gar_gara_idoneita_professionale)
      if (item.table && Array.isArray(item.table.headers) && Array.isArray(item.table.rows) && item.table.rows.length > 0) {
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderTable({ headers: item.table.headers, rows: item.table.rows }),
          active: true,
        });
      } else if (parsedValueJson && Array.isArray(parsedValueJson.entries) && parsedValueJson.entries.length > 0) {
        // Se non c'è tabella ma ci sono entries normalizzate, usale
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderEntriesTable(parsedValueJson.entries, typeCode, []),
          active: true,
        });
      } else if (Array.isArray(item.requirements) && item.requirements.length) {
        // Fallback: usa requirements se disponibili
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderRequirementsList(item.requirements, item.empty_reason),
          active: true,
        });
      } else {
        // Nessun dato disponibile
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: '<div class="response-value empty-reason">Dati non disponibili</div>',
          active: true,
        });
      }
    } else if (typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
      // Per requisiti_idoneita_professionale_gruppo_lavoro, usa la tabella pre-costruita dal backend
      // (dati da gar_gara_idoneita_professionale)
      if (item.table && Array.isArray(item.table.headers) && Array.isArray(item.table.rows) && item.table.rows.length > 0) {
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderTable({ headers: item.table.headers, rows: item.table.rows }),
          active: true,
        });
      } else if (parsedValueJson && Array.isArray(parsedValueJson.entries) && parsedValueJson.entries.length > 0) {
        // Se non c'è tabella ma ci sono entries normalizzate, usale
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: renderEntriesTable(parsedValueJson.entries, typeCode, []),
          active: true,
        });
      } else {
        // Nessun dato disponibile
        tabs.push({
          id: 'response',
          label: 'Risposta',
          content: '<div class="response-value empty-reason">Dati non disponibili</div>',
          active: true,
        });
      }
    } else if (hasScalarValue(item)) {
      tabs.push({
        id: 'response',
        label: 'Risposta',
        content: renderScalarValue(item),
        active: true,
      });
    } else if (item.empty_reason) {
      tabs.push({
        id: 'response',
        label: 'Risposta',
        content: renderEmptyReason(item.empty_reason),
        active: true,
      });
    }

    const combinedCitations = collectCitations(sourceItem, parsedValueJson, parsedContextJson);
    if (combinedCitations.length) {
      tabs.push({
        id: 'citations',
        label: 'Citazioni',
        content: `<ul class="citations-list">
          ${combinedCitations
            .map(
              (cit) => `
                <li class="citation-item">
                  <span class="citation-page">Pagina ${escapeHtml(cit.page_number ?? cit.page ?? '—')}</span>
                  <div class="citation-text">${escapeHtml((Array.isArray(cit.text) ? cit.text.join(' — ') : cit.text || cit.snippet || '').trim())}</div>
                  ${cit.highlight_rel_path ? `<a href="${escapeAttribute(cit.highlight_rel_path)}" target="_blank" rel="noopener">Apri highlight</a>` : ''}
                </li>`
            )
            .join('')}
        </ul>`,
      });
    }

    const valueJsonRaw = typeof item.value_json === 'string' ? item.value_json.trim() : null;
    const contextRaw = typeof item.context === 'string' ? item.context.trim() : null;

    // Per importi_corrispettivi_categoria_id_opere e requisiti_tecnico_professionali, 
    // non mostrare tab "Ragionamento" (contiene solo fallback AI o testi inglesi)
    if (typeCode !== 'importi_corrispettivi_categoria_id_opere' && typeCode !== 'requisiti_tecnico_professionali') {
      const reasoning = parsedValueJson && parsedValueJson.chain_of_thought ? parsedValueJson.chain_of_thought : null;
      let contextReasoning = null;
      if (!reasoning && parsedContextJson && typeof parsedContextJson === 'object' && parsedContextJson.chain_of_thought) {
        contextReasoning = parsedContextJson.chain_of_thought;
      }
      if (reasoning || contextReasoning) {
        tabs.push({
          id: 'reasoning',
          label: 'Ragionamento',
          content: `<pre class="json-raw">${escapeHtml(reasoning || contextReasoning)}</pre>`,
        });
      }
    }

    const jsonBundle = {};
    if (parsedValueJson !== null) {
      jsonBundle.value_json = sanitizeForJson(parsedValueJson);
    } else if (valueJsonRaw) {
      jsonBundle.value_json_raw = valueJsonRaw;
    }

    if (parsedContextJson !== null) {
      jsonBundle.context = sanitizeForJson(parsedContextJson);
    } else if (contextRaw) {
      jsonBundle.context_raw = contextRaw;
    }

    jsonBundle.item = sanitizeForJson(sourceItem);

    const jsonKeys = Object.keys(jsonBundle);
    if (jsonKeys.length) {
      let jsonString = '';
      try {
        jsonString = JSON.stringify(jsonBundle, null, 2);
      } catch (_) {
        jsonString = JSON.stringify(sanitizeForJson(jsonBundle), null, 2);
      }
      tabs.push({
        id: 'json',
        label: 'JSON',
        content: `<pre class="json-raw">${escapeHtml(jsonString)}</pre>`,
      });
    }

    return tabs;
  }

  function renderTabbedDetail(tabs) {
    if (!Array.isArray(tabs) || !tabs.length) {
      return '<div class="response-value">—</div>';
    }

    const tabId = `tabset-${Math.random().toString(36).slice(2, 8)}`;
    const nav = tabs
      .map((tab, idx) => {
        const active = tab.active || (idx === 0 && !tabs.some((t) => t.active));
        return `<button class="tab-nav${active ? ' active' : ''}" data-tab="${tabId}-${tab.id}">${escapeHtml(tab.label)}</button>`;
      })
      .join('');

    const panes = tabs
      .map((tab, idx) => {
        const active = tab.active || (idx === 0 && !tabs.some((t) => t.active));
        return `<div class="tab-pane${active ? ' active' : ''}" id="${tabId}-${tab.id}">${tab.content}</div>`;
      })
      .join('');

    return `
      <div class="tabbed-detail" data-tab-group="${tabId}">
        <div class="tab-navs">${nav}</div>
        <div class="tab-panes">${panes}</div>
      </div>
    `;
  }

  function renderPrintResponseContent(item) {
    if (item && item.synthetic === 'sopralluogo' && item.synthetic_kind === 'deadline') {
      return '';
    }
    const source = item && item.synthetic_source ? item.synthetic_source : item;
    const tabs = buildExtractionTabs(source);
    if (!Array.isArray(tabs) || !tabs.length) return '';
    const responseTab = tabs.find((tab) => tab.id === 'response') || tabs[0];
    if (!responseTab) return '';
    return responseTab.content || '';
  }

  function stringifyValue(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'string') return value;
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);
    if (Array.isArray(value)) {
      return value.map((part) => stringifyValue(part)).filter(Boolean).join(' ');
    }
    if (typeof value === 'object') {
      const nested = pickFirst([value.text, value.content, value.value, value.summary, value.description]);
      if (typeof nested === 'string') return nested;
    }
    return '';
  }

  function truncate(text, max = 120) {
    if (!text) return '';
    if (text.length <= max) return text;
    return `${text.slice(0, max - 1)}…`;
  }

  function resolveSopralluogoDeadline(item, parsedValueJson) {
    if (!item || typeof item !== 'object') return null;
    const details = item.sopralluogo_details || {};
    
    // Priorità 1: label dal backend (più affidabile)
    const labelFromBackend =
      item.sopralluogo_deadline_label || details.deadline_label || null;
    if (labelFromBackend && String(labelFromBackend).trim() !== '') {
      const label = String(labelFromBackend).trim();
      // Verifica che non sia testo a caso (es. chain_of_thought)
      if (label.length < 200 && !label.toLowerCase().includes('chain_of_thought')) {
        return label;
      }
    }

    // Priorità 2: deadline esplicita dal backend
    const explicit = item.sopralluogo_deadline || details.deadline_display;
    if (explicit && String(explicit).trim() !== '') {
      const explicitStr = String(explicit).trim();
      if (explicitStr.length < 200) {
        return normalizeSopralluogoDeadlineLabel(explicitStr);
      }
    }

    // Priorità 3: ISO date dal backend
    const iso = item.sopralluogo_deadline_iso || details.deadline_iso;
    if (iso) {
      const formattedIso = formatDate(iso);
      if (formattedIso) {
        return formattedIso;
      }
    }

    // Priorità 4: testo dal backend
    const textual = item.sopralluogo_deadline_text || details.deadline_text;
    if (textual && String(textual).trim() !== '') {
      const textualStr = String(textual).trim();
      // Verifica che non sia testo troppo lungo o chain_of_thought
      if (textualStr.length < 200 && !textualStr.toLowerCase().includes('chain_of_thought')) {
        return normalizeSopralluogoDeadlineLabel(textualStr);
      }
    }

    // Priorità 5: dal JSON parsato (solo se deadlines array non è vuoto)
    if (!parsedValueJson) parsedValueJson = parseJsonObject(item.value_json);

    if (parsedValueJson && typeof parsedValueJson === 'object') {
      // Se deadlines è un array vuoto, non c'è deadline
      if (Array.isArray(parsedValueJson.deadlines) && parsedValueJson.deadlines.length === 0) {
        return null;
      }
      
      const normalized = pickFirst([
        parsedValueJson.deadline,
        parsedValueJson.deadline_label,
        parsedValueJson.deadline_text,
      ]);
      if (normalized && String(normalized).trim() !== '') {
        const normalizedStr = String(normalized).trim();
        // Verifica che non sia testo troppo lungo o chain_of_thought
        if (normalizedStr.length < 200 && !normalizedStr.toLowerCase().includes('chain_of_thought')) {
          return normalizeSopralluogoDeadlineLabel(normalizedStr);
        }
      }
    }

    return null;
  }

  function summarizeTable(table) {
    const rows = Array.isArray(table.rows) ? table.rows : [];
    const count = rows.length;
    return 'Tabella';
  }

  function sanitizeTableData(tableData = {}) {
    const keepAllColumns = Boolean(
      tableData && (tableData.keep_all_columns || tableData.preserve_all_columns)
    );

    const rawHeaders = Array.isArray(tableData.headers) ? [...tableData.headers] : [];
    const rawRows = Array.isArray(tableData.rows)
      ? tableData.rows.map((row) => {
          if (Array.isArray(row)) return [...row];
          if (row && typeof row === 'object' && !Array.isArray(row)) {
            return Object.entries(row).map(([key, value]) => ({
              role: key,
              value,
            }));
          }
          return row;
        })
      : [];

    const normalizeHeader = (header) =>
      header === undefined || header === null ? '' : String(header);

    if (keepAllColumns) {
      const normalizedHeaders = rawHeaders.map((header) => normalizeHeader(header));
      const expectedLength = normalizedHeaders.length;

      const normalizedRows = rawRows.map((row) => {
        const cells = Array.isArray(row)
          ? row.map((cell) => extractTableCellValue(cell))
          : [extractTableCellValue(row)];

        if (expectedLength > 0) {
          if (cells.length < expectedLength) {
            return cells.concat(new Array(expectedLength - cells.length).fill(''));
          }
          if (cells.length > expectedLength) {
            return cells.slice(0, expectedLength);
          }
        }

        return cells;
      });

      return { headers: normalizedHeaders, rows: normalizedRows };
    }

    if (!rawRows.length) {
      return { headers: rawHeaders.map((header) => normalizeHeader(header)), rows: rawRows };
    }

    const columnCount = Math.max(
      rawHeaders.length,
      ...rawRows.map((row) =>
        Array.isArray(row)
          ? row.length
          : row && typeof row === 'object'
            ? Object.keys(row).length
            : 0
      )
    );

    if (columnCount === 0) {
      return {
        headers: rawHeaders,
        rows: rawRows,
      };
    }

    const columnsToKeep = new Array(columnCount).fill(false);

    for (let col = 0; col < columnCount; col += 1) {
      for (const row of rawRows) {
        const value = extractTableCellValue(row ? row[col] : undefined);
        const normalized = value.trim().toLowerCase();
        if (normalized && normalized !== 'n/a' && normalized !== 'na' && normalized !== '-') {
          columnsToKeep[col] = true;
          break;
        }
      }
    }

    if (!columnsToKeep.some(Boolean)) {
      return {
        headers: rawHeaders,
        rows: rawRows,
      };
    }

    const filteredHeaders = rawHeaders
      .filter((_, idx) => columnsToKeep[idx])
      .map((header) => (header === undefined || header === null ? '' : String(header)));

    const filteredRows = rawRows.map((row) =>
      row
        .filter((_, idx) => columnsToKeep[idx])
        .map((cell) => extractTableCellValue(cell))
    );

    return { headers: filteredHeaders, rows: filteredRows };
  }

  function extractTableCellValue(cell) {
    if (cell === null || cell === undefined) return '';
    if (typeof cell === 'string' || typeof cell === 'number' || typeof cell === 'boolean') {
      return String(cell);
    }
    if (Array.isArray(cell)) {
      return cell.map((item) => extractTableCellValue(item)).filter(Boolean).join(' | ');
    }
    if (typeof cell === 'object') {
      if (cell.role !== undefined && cell.value !== undefined) {
        return `${cell.role}: ${extractTableCellValue(cell.value)}`;
      }
      if (cell.value !== undefined && cell.value !== null) {
        return String(cell.value);
      }
      if (cell.raw !== undefined && cell.raw !== null) {
        return String(cell.raw);
      }
      if (cell.role !== undefined || cell.detail !== undefined) {
        const parts = [cell.role, cell.detail].filter(Boolean).map((part) => extractTableCellValue(part));
        return parts.join(': ');
      }
      if (cell.requirements !== undefined && Array.isArray(cell.requirements)) {
        return cell.requirements.map((req) => extractTableCellValue(req)).filter(Boolean).join(' | ');
      }
      return String(cell.text ?? cell.content ?? '');
    }
    return '';
  }

  function stripDiacritics(value) {
    if (typeof value !== 'string') {
      return '';
    }
    if (value.normalize) {
      return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return value;
  }

  function normalizeSopralluogoStatus(value) {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'object' && !Array.isArray(value)) {
      if (value.status_label !== undefined) {
        return normalizeSopralluogoStatus(value.status_label);
      }
      if (value.display_value !== undefined) {
        return normalizeSopralluogoStatus(value.display_value);
      }
    }
    if (typeof value === 'boolean') {
      return value ? 'si' : 'no';
    }

    const raw = stringifyValue(value);
    if (!raw) {
      return '';
    }

    const ascii = stripDiacritics(raw);
    const normalized = ascii.trim().toLowerCase();
    if (!normalized) {
      return '';
    }

    if (['1', 'true', 'si', 'sì', 'yes', 'obbligatorio'].includes(normalized)) {
      return 'si';
    }
    if (['0', 'false', 'no', 'non', 'facoltativo'].includes(normalized)) {
      return 'no';
    }

    return raw.trim();
  }

  function renderScalarValue(item) {
    const typeCode = item.type_code || item.tipo || item.type;
    if (typeCode === 'sopralluogo_obbligatorio') {
      const statusText =
        normalizeSopralluogoStatus(item.display_value || item.valore_display) ||
        normalizeSopralluogoStatus(extractPrimaryValue(item)) ||
        stringifyValue(item.display_value || item.valore_display || extractPrimaryValue(item));
      const deadlineLabel = resolveSopralluogoDeadline(item);
      const fragments = [];
      if (statusText) {
        fragments.push(`<div class="sopralluogo-status">${escapeHtml(statusText)}</div>`);
      }
      if (deadlineLabel) {
        fragments.push(
          `<div class="sopralluogo-deadline">${escapeHtml(deadlineLabel)}</div>`
        );
      }
      if (fragments.length) {
        return `<div class="response-value sopralluogo-response">${fragments.join('')}</div>`;
      }
    }

    const primary = extractPrimaryValue(item);
    const text = stringifyValue(primary);
    const formatted = formatDate(primary) || formatDate(text);
    if (formatted) {
      return `<div class="response-value">${escapeHtml(formatted)}</div>`;
    }
    if (!text) {
      if (item.empty_reason) {
        return `<div class="response-value empty-reason">${escapeHtml(item.empty_reason)}</div>`;
      }
      return '<div class="response-value">—</div>';
    }
    if (/\n/.test(text) || text.length > 160) {
      return `<pre class="response-value">${escapeHtml(text)}</pre>`;
    }
    return `<div class="response-value">${escapeHtml(text)}</div>`;
  }

  function hasScalarValue(item) {
    const primary = extractPrimaryValue(item);
    const text = stringifyValue(primary);
    if (text && text.trim()) {
      return true;
    }
    const typeCode = item.type_code || item.tipo || item.type;
    if (typeCode === 'sopralluogo_obbligatorio') {
      return Boolean(
        normalizeSopralluogoStatus(item.display_value || item.valore_display || primary) ||
          resolveSopralluogoDeadline(item)
      );
    }
    return false;
  }

  function renderEmptyReason(reason) {
    const message = reason ? truncate(reason, 320) : 'Dato non presente nel bando.';
    return `<div class="empty-reason">${escapeHtml(message)}</div>`;
  }

  function renderRequirementsList(requirements, emptyReason) {
    if (!Array.isArray(requirements) || !requirements.length) {
      return `<div class="requirements-list-wrapper empty">${renderEmptyReason(
        emptyReason
      )}</div>`;
    }
    const items = requirements
      .map((req, index) => {
        const pieces = [];
        if (req.title) {
          pieces.push(`<span class="req-title">${escapeHtml(req.title)}</span>`);
        }
        if (req.description) {
          pieces.push(`<span class="req-desc">${escapeHtml(req.description)}</span>`);
        }
        if (req.reference) {
          pieces.push(`
            <span class="req-ref">${escapeHtml(req.reference)}</span>`);
        }
        const content = pieces.join(' — ');
        return `<li><span class="req-index">${index + 1}.</span> ${content || '—'}</li>`;
      })
      .join('');

    return `
      <div class="requirements-list-wrapper">
        <ol class="requirements-list">${items}</ol>
      </div>
    `;
  }

  function normalizeJob(row) {
    return {
      job_id: row.job_id || row.id,
      status: row.status || 'queued',
      status_label: row.status_label || statusLabel(row.status),
      file_name: row.file_name || row.original_name,
      oggetto_appalto: row.oggetto_appalto || row.object || row.title || '',
      titolo: row.titolo || '',
      progress_done: row.progress_done ?? 0,
      progress_total: row.progress_total ?? 100,
      created_at: row.created_at || row.inserted_at,
      updated_at: row.updated_at,
      completed_at: row.completed_at,
      gara_id: row.gara_id || row.garaId || null,
      ext_batch_id: row.ext_batch_id || row.batch_id || null,
      ext_job_id: row.ext_job_id || row.job_id || row.id || null,
      results: null,
    };
  }

  function attachDetailHandlers(scope) {
    if (!scope) return;
    scope.querySelectorAll('.toggle-details').forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-target');
        const row = document.getElementById(targetId);
        if (!row) return;
        row.classList.toggle('hidden');
        btn.classList.toggle('active');
        btn.textContent = row.classList.contains('hidden') ? 'Dettagli' : 'Nascondi';
      });
    });

    scope.querySelectorAll('.toggle-json').forEach((btn) => {
      btn.addEventListener('click', () => {
        const pre = btn.nextElementSibling;
        if (!pre) return;
        pre.classList.toggle('hidden');
        btn.textContent = pre.classList.contains('hidden') ? 'Mostra JSON' : 'Nascondi JSON';
      });
    });

    scope.querySelectorAll('.tabbed-detail').forEach((wrapper) => {
      const panes = wrapper.querySelector('.tab-panes');
      if (panes) {
        panes.style.maxWidth = '100%';
        panes.style.overflow = 'hidden';
      }

      const navButtons = wrapper.querySelectorAll('.tab-nav');
      navButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          const target = btn.getAttribute('data-tab');
          if (!target) return;

          wrapper.querySelectorAll('.tab-nav').forEach((nav) => nav.classList.remove('active'));
          wrapper.querySelectorAll('.tab-pane').forEach((pane) => pane.classList.remove('active'));

          btn.classList.add('active');
          const pane = wrapper.querySelector(`#${target}`);
          if (pane) {
            pane.classList.add('active');
            pane.style.maxWidth = '100%';
            pane.style.overflow = 'hidden';
          }
        });
      });
    });
  }

  function renderEntriesTable(entries, typeCode, citations = []) {
    if (!Array.isArray(entries)) return '<div class="response-value">—</div>';
    
    // Per importi_corrispettivi_categoria_id_opere, ignora completamente citazioni e fallback
    if (typeCode === 'importi_corrispettivi_categoria_id_opere') {
      if (entries.length === 0) {
        return '<div class="response-value empty-reason">Dati non disponibili</div>';
      }
    } else {
      // Se entries è vuoto ma ci sono citazioni, mostra le citazioni (solo per altri type_code)
      if (entries.length === 0) {
        if (citations && citations.length > 0) {
          // Estrai dati strutturati dalle citazioni se possibile
          const extractedData = extractDataFromCitations(citations, typeCode);
          if (extractedData && extractedData.length > 0) {
            return renderTableFromCitations(extractedData, typeCode);
          }
          // Altrimenti mostra le citazioni in formato tabella
          return renderCitationsAsTable(citations);
        }
        return '<div class="response-value empty-reason">Nessun dato disponibile per questa estrazione</div>';
      }
    }
    
    // Documentazione tecnica richiesta
    if (typeCode === 'documentazione_richiesta_tecnica') {
      const headers = ['Documento', 'Tipo', 'Stato', 'Formato', 'Max pagine'];
      const rows = entries.map(entry => {
        const documento = escapeHtml(entry.documento || entry.titolo || '—');
        const tipo = escapeHtml(entry.tipo || entry.tipo_documento || '—');
        const stato = escapeHtml(entry.stato || entry.obbligatorieta || '—');
        const formato = escapeHtml(entry.formato || '—');
        const maxPagine = escapeHtml(entry.max_pagine || entry.max_pages || '—');
        return [documento, tipo, stato, formato, maxPagine];
      });
      return renderTable({ headers, rows });
    }
    
    // Requisiti di capacità economico-finanziaria
    if (typeCode === 'requisiti_di_capacita_economica_finanziaria') {
      const headers = ['ID opere', 'Importo corrispettivo', 'Coefficiente moltiplicativo', 'Importo requisito', 'Importo posseduto'];
      const rows = entries.map(entry => {
        const idOpera = escapeHtml(entry.id_opera || entry.categoria_codice || '—');
        
        let importoCorrispettivo = '—';
        if (entry.importo_corrispettivo_eur !== undefined && entry.importo_corrispettivo_eur !== null) {
          importoCorrispettivo = parseFloat(entry.importo_corrispettivo_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        } else if (entry.importo_corrispettivo_raw) {
          importoCorrispettivo = escapeHtml(entry.importo_corrispettivo_raw);
        } else if (entry.riferimento_valore_categoria && !isNaN(parseFloat(entry.riferimento_valore_categoria))) {
          importoCorrispettivo = parseFloat(entry.riferimento_valore_categoria).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        }
        
        const coefficiente = entry.coefficiente_moltiplicativo !== undefined && entry.coefficiente_moltiplicativo !== null
          ? (typeof entry.coefficiente_moltiplicativo === 'number'
              ? entry.coefficiente_moltiplicativo.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : escapeHtml(String(entry.coefficiente_moltiplicativo)))
          : (entry.moltiplicatore !== undefined && entry.moltiplicatore !== null
              ? (typeof entry.moltiplicatore === 'number'
                  ? entry.moltiplicatore.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                  : escapeHtml(String(entry.moltiplicatore)))
              : '—');
        
        let importoRequisito = '—';
        if (entry.importo_requisito_eur !== undefined && entry.importo_requisito_eur !== null) {
          importoRequisito = parseFloat(entry.importo_requisito_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        } else if (entry.importo_minimo_eur !== undefined && entry.importo_minimo_eur !== null) {
          importoRequisito = parseFloat(entry.importo_minimo_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        }
        
        const importoPosseduto = entry.importo_posseduto_eur !== undefined && entry.importo_posseduto_eur !== null
          ? parseFloat(entry.importo_posseduto_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' })
          : '—';
        
        return [idOpera, importoCorrispettivo, coefficiente, importoRequisito, importoPosseduto];
      });
      return renderTable({ headers, rows });
    }
    
    // Requisiti di idoneità professionale del gruppo di lavoro
    if (typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
      const headers = ['Requisito', 'Descrizione', 'Riferimento'];
      const rows = entries.map(entry => {
        const requisito = escapeHtml(entry.requisito || entry.ruolo || entry.role_name || '—');
        const descrizione = escapeHtml(entry.descrizione || entry.requisiti || entry.description || '—');
        const riferimento = escapeHtml(entry.riferimento || '—');
        return [requisito, descrizione, riferimento];
      });
      return renderTable({ headers, rows });
    }
    
    if (typeCode === 'importi_opere_per_categoria_id_opere') {
      // Nuova struttura con colonne preimpostate
      const headers = ['ID Opere', 'Categoria', 'Descrizione', 'Importo stimato dei lavori'];
      const rows = entries.map(entry => {
        // ID Opere: usa id_opera_normalized (o id_opera_raw come fallback)
        const id = escapeHtml(entry.id_opera_normalized || entry.id_opera_raw || '—');
        
        // Categoria e Descrizione: vengono popolate dal backend usando gar_opere_dm50
        // Se non sono presenti nei dati, mostriamo '—'
        const cat = escapeHtml(entry.categoria || '—');
        const desc = escapeHtml(entry.descrizione || entry.identificazione_opera || '—');
        
        // Importo stimato dei lavori: usa amount_eur (o amount_raw come fallback)
        const amount = entry.amount_eur 
          ? parseFloat(entry.amount_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' })
          : (entry.amount_raw ? escapeHtml(entry.amount_raw) : '—');
        
        return [id, cat, desc, amount];
      });
      return renderTable({ headers, rows });
    }
    
    // Importi corrispettivi per categoria - usa ESCLUSIVAMENTE dati normalizzati dal DB
    if (typeCode === 'importi_corrispettivi_categoria_id_opere') {
      // Se entries è vuoto, mostra solo messaggio "Dati non disponibili"
      if (!entries || entries.length === 0) {
        return '<div class="response-value empty-reason">Dati non disponibili</div>';
      }
      
      // Colonne fisse: ID opere, Categoria, Descrizione, Grado di complessità, Importo del corrispettivo
      const headers = ['ID opere', 'Categoria', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo'];
      const rows = entries.map(entry => {
        // Usa solo i campi normalizzati dal database
        const id = escapeHtml(entry.id_opera || '—');
        const cat = escapeHtml(entry.categoria || '—');
        const desc = escapeHtml(entry.descrizione || entry.identificazione_opera || '—');
        
        // Grado di complessità: solo da grado_complessita normalizzato
        const gradoComplessita = entry.grado_complessita !== undefined && entry.grado_complessita !== null
          ? (typeof entry.grado_complessita === 'number'
              ? entry.grado_complessita.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : escapeHtml(String(entry.grado_complessita)))
          : '—';
        
        // Importo del corrispettivo: solo da importo_corrispettivo_eur normalizzato
        const importoCorrispettivo = entry.importo_corrispettivo_eur !== undefined && entry.importo_corrispettivo_eur !== null
          ? parseFloat(entry.importo_corrispettivo_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' })
          : (entry.importo_corrispettivo_raw ? escapeHtml(entry.importo_corrispettivo_raw) : '—');
        
        return [id, cat, desc, gradoComplessita, importoCorrispettivo];
      });
      return renderTable({ headers, rows });
    }
    
    // Importi requisiti tecnici per categoria
    if (typeCode === 'importi_requisiti_tecnici_categoria_id_opere') {
      // Headers: ID Opera, Categoria, Descrizione, Importo minimo
      const headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo'];
      const rows = entries.map(entry => {
        const idOpera = escapeHtml(entry.id_opera || entry.id_opera_raw || '—');
        const categoria = escapeHtml(entry.categoria || entry.category_name || '—');
        const descrizione = escapeHtml(entry.descrizione || entry.description || entry.identificazione_opera || '—');
        
        // Importo minimo: formatta da importo_minimo_eur o minimum_amount_eur
        let importoMinimo = '—';
        if (entry.importo_minimo_eur !== undefined && entry.importo_minimo_eur !== null) {
          importoMinimo = parseFloat(entry.importo_minimo_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        } else if (entry.minimum_amount_eur !== undefined && entry.minimum_amount_eur !== null) {
          importoMinimo = parseFloat(entry.minimum_amount_eur).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
        } else if (entry.importo_minimo_raw) {
          importoMinimo = escapeHtml(entry.importo_minimo_raw);
        }
        
        return [idOpera, categoria, descrizione, importoMinimo];
      });
      return renderTable({ headers, rows });
    }
    
    // Requisiti tecnico-professionali
    if (typeCode === 'requisiti_tecnico_professionali') {
      const headers = ['Requisito', 'Descrizione', 'Obbligatorio'];
      
      if (!entries || entries.length === 0) {
        return '<div class="response-value empty-reason">Dati non disponibili</div>';
      }
      
      const rows = entries.map(entry => {
        const requisito = escapeHtml(entry.requisito || '—');
        const descrizione = escapeHtml(entry.descrizione || '—');
        const obbligatorio = escapeHtml(entry.obbligatorio || '—');
        
        return [requisito, descrizione, obbligatorio];
      });
      
      return renderTable({ headers, rows });
    }
    
    // Fallback generico per altri tipi di entries
    const headers = Object.keys(entries[0] || {}).filter(k => k !== 'source_page' && k !== 'service_phase');
    const rows = entries.map(entry => 
      headers.map(h => {
        const val = entry[h];
        if (val === null || val === undefined) return '—';
        if (typeof val === 'number') {
          if (h.includes('amount') || h.includes('importo')) {
            return parseFloat(val).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
          }
          return String(val);
        }
        if (Array.isArray(val)) return val.map(v => escapeHtml(String(v))).join('<br>');
        return escapeHtml(String(val));
      })
    );
    return renderTable({ headers, rows });
  }

  function extractDataFromCitations(citations, typeCode) {
    if (!citations || !Array.isArray(citations) || citations.length === 0) return null;
    
    // Per importi_corrispettivi_categoria_id_opere, cerca tabelle con corrispettivi nelle citazioni
    if (typeCode === 'importi_corrispettivi_categoria_id_opere') {
      const extracted = [];
      citations.forEach(cit => {
        if (!cit.text || !Array.isArray(cit.text)) return;
        
        const lines = cit.text;
        const amountPattern = /€\s*[\d.,]+/;
        let foundCorrispettivi = false;
        
        // Cerca la sezione "CORRISPETTIVI"
        for (let i = 0; i < lines.length; i++) {
          const line = String(lines[i]).trim();
          
          if (line.includes('CORRISPETTIVI') || line.includes('PRESTAZIONI')) {
            foundCorrispettivi = true;
            continue;
          }
          
          if (foundCorrispettivi) {
            // Se contiene "IMPORTO COMPLESSIVO", abbiamo finito
            if (line.includes('IMPORTO COMPLESSIVO') || line.includes('TOTALE')) {
              break;
            }
            
            // Se contiene un importo, cerca il servizio nella riga precedente
            if (amountPattern.test(line)) {
              const amountMatch = line.match(amountPattern);
              if (amountMatch && i > 0) {
                const prevLine = String(lines[i - 1]).trim();
                // Se la riga precedente non è un importo e non è un'intestazione
                if (prevLine && !amountPattern.test(prevLine) && 
                    !prevLine.includes('CORRISPETTIVI') && 
                    !prevLine.includes('PRESTAZIONI') &&
                    !prevLine.includes('IMPORTO')) {
                  extracted.push({
                    servizio: prevLine,
                    importo: amountMatch[0].replace(/[=]+$/, ''), // Rimuovi eventuali "=" finali
                    pagina: cit.page_number || cit.page || '—'
                  });
                }
              }
            }
          }
        }
      });
      
      return extracted.length > 0 ? extracted : null;
    }
    
    return null;
  }

  function renderTableFromCitations(extractedData, typeCode) {
    if (!extractedData || extractedData.length === 0) return '';
    
    if (typeCode === 'importi_corrispettivi_categoria_id_opere') {
      const headers = ['Servizio', 'Importo', 'Pagina'];
      const rows = extractedData.map(item => [
        escapeHtml(item.servizio || '—'),
        escapeHtml(item.importo || '—'),
        escapeHtml(String(item.pagina || '—'))
      ]);
      
      return `
        <div class="response-value">
          <p class="empty-reason" style="margin-bottom: 1rem;">
            <strong>Nota:</strong> I dati non sono disponibili per categoria ID Opera come richiesto, 
            ma sono disponibili i corrispettivi per tipo di servizio:
          </p>
          ${renderTable({ headers, rows })}
        </div>
      `;
    }
    
    return '';
  }

  function renderCitationsAsTable(citations) {
    if (!citations || citations.length === 0) return '';
    
    const headers = ['Pagina', 'Contesto', 'Motivazione'];
    const rows = citations.map(cit => {
      const page = cit.page_number || cit.page || '—';
      const textSegments = Array.isArray(cit.text) ? cit.text : (cit.text ? [cit.text] : []);
      const context = textSegments.filter(Boolean).slice(0, 5).join(' — '); // Limita a 5 segmenti
      const reason = cit.reason_for_relevance ? truncate(cit.reason_for_relevance, 200) : '—';
      
      return [
        escapeHtml(String(page)),
        escapeHtml(truncate(context, 300) || '—'),
        escapeHtml(reason)
      ];
    });
    
    return `
      <div class="response-value">
        <p class="empty-reason" style="margin-bottom: 1rem;">
          <strong>Nota:</strong> I dati non sono disponibili nella struttura richiesta, 
          ma sono disponibili le seguenti informazioni dal documento:
        </p>
        ${renderTable({ headers, rows })}
      </div>
    `;
  }

  function resolveSopralluogoBool(parsedValueJson, item) {
    if (parsedValueJson && typeof parsedValueJson === 'object') {
      if (typeof parsedValueJson.bool_answer === 'boolean') return parsedValueJson.bool_answer;
      if (typeof parsedValueJson.answer === 'string') {
        const answer = parsedValueJson.answer.trim().toLowerCase();
        if (answer === 'true' || answer === 'si' || answer === 'yes') return true;
        if (answer === 'false' || answer === 'no') return false;
      }
    }
    const primary = (item && extractPrimaryValue(item)) || '';
    const normalized = normalizeSopralluogoStatus(primary);
    if (normalized === 'si') return true;
    if (normalized === 'no') return false;
    return null;
  }


  function normalizeSopralluogoDeadlineLabel(value) {
    if (!value) return null;
    const normalized = String(value).trim();
    if (!normalized) return null;
    if (/^(termine|entro)/i.test(normalized)) {
      return normalized.charAt(0).toUpperCase() + normalized.slice(1);
    }
    return `Entro ${normalized}`;
  }

  // --- PDF download buttons for highlighted PDFs ---
  function renderHighlightedPdfLinks(extraction, container) {
    const paths = extraction.highlighted_pdf_paths;
    if (!paths || !Array.isArray(paths) || paths.length === 0) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'highlighted-pdf-links';
    paths.forEach(path => {
      const filename = path.split('/').pop();
      const btn = document.createElement('a');
      btn.className = 'btn btn-sm btn-secondary';
      btn.textContent = 'Vedi nel PDF';
      const jobId = extraction.ext_job_id || extraction.job_id;
      btn.href = `ajax.php?section=gare&action=downloadHighlightedPdf&job_id=${encodeURIComponent(jobId)}&filename=${encodeURIComponent(filename)}`;
      btn.target = '_blank';
      wrapper.appendChild(btn);
    });
    container.appendChild(wrapper);
  }

  // --- Batch usage/cost info after completion ---
  async function loadBatchUsage(batchId, container) {
    if (!batchId) return;
    try {
      const res = await customFetch('gare', 'getBatchUsage', { batch_id: batchId }, { showLoader: false });
      if (res.success && res.data) {
        const d = res.data;
        const usageHtml = `
          <div class="batch-usage-info">
            <span><strong>Token:</strong> ${(d.tokens?.prompt_tokens || 0).toLocaleString()} in / ${(d.tokens?.output_tokens || 0).toLocaleString()} out</span>
            <span><strong>Costo:</strong> $${(d.cost?.total_cost || 0).toFixed(4)}</span>
          </div>`;
        container.insertAdjacentHTML('beforeend', usageHtml);
      }
    } catch (e) {
      console.warn('Failed to load batch usage', e);
    }
  }

  // --- API health status — info icon with popover ---
  async function loadApiStatus(container) {
    if (!container) return;
    try {
      const [healthRes, quotaRes] = await Promise.all([
        customFetch('gare', 'apiHealth', {}, { showLoader: false }),
        customFetch('gare', 'getQuota', {}, { showLoader: false })
      ]);

      const isHealthy = healthRes.success && healthRes.data && healthRes.data.status === 'healthy';
      const model = (healthRes.success && healthRes.data) ? (healthRes.data.gemini_model || 'N/A') : 'N/A';
      const dotColor = isHealthy ? 'var(--gd-green)' : 'var(--gd-red)';
      const statusText = isHealthy ? 'Online' : 'Offline';

      let quotaHtml = '';
      if (quotaRes.success && quotaRes.data) {
        const q = quotaRes.data;
        const pctUsed = q.percentage_used || 0;
        quotaHtml = `
          <div class="asi-row">
            <span class="asi-label">Quota</span>
            <span class="asi-val">${q.rpd_remaining || 0} / ${q.rpd_limit || 0} rimanenti</span>
          </div>
          <div class="asi-bar"><div class="asi-bar-fill" style="width:${pctUsed}%"></div></div>
        `;
      }

      container.innerHTML = `
        <div class="asi-wrap">
          <button class="asi-btn" title="Stato API Estrazione" type="button">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span class="asi-dot" style="background:${dotColor}"></span>
          </button>
          <div class="asi-pop">
            <div class="asi-pop-title">Stato API Estrazione</div>
            <div class="asi-row">
              <span class="asi-label">Stato</span>
              <span class="asi-val"><span class="asi-dot-inline" style="background:${dotColor}"></span> ${escapeHtml(statusText)}</span>
            </div>
            <div class="asi-row">
              <span class="asi-label">Modello</span>
              <span class="asi-val">${escapeHtml(model)}</span>
            </div>
            ${quotaHtml}
          </div>
        </div>
      `;

      const btn = container.querySelector('.asi-btn');
      const pop = container.querySelector('.asi-pop');
      if (btn && pop) {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          pop.classList.toggle('open');
        });
        document.addEventListener('click', () => pop.classList.remove('open'));
        pop.addEventListener('click', (e) => e.stopPropagation());
      }
    } catch (e) {
      console.warn('Failed to load API status', e);
    }
  }
})();