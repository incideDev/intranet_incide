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

  const jobsEl = document.getElementById('gare-jobs');

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

  function formatTenderSectorValue(rawValue) {
    if (!rawValue) return '';
    const text = String(rawValue).trim();
    if (!text) return '';

    const cpvPattern = /^(?:cpv(?:\s*principale)?[:\s]*)?([0-9]{8}-\d)\s*[-:]\s*([^()]+?)(?:\s*\(([^)]+)\))?$/i;
    const match = text.match(cpvPattern);
    if (match) {
      const code = match[1] ? match[1].trim() : '';
      let label = match[2] ? match[2].trim() : '';
      if (!label && match[3]) {
        label = match[3].trim();
      }
      const cleanedLabel = label.replace(/\s+/g, ' ').replace(/[;]+$/, '').trim();
      const pieces = [];
      if (cleanedLabel) {
        pieces.push(cleanedLabel);
      }
      if (code) {
        pieces.push(`CPV ${code}`);
      }
      return pieces.join(' (') + (pieces.length > 1 ? ')' : '');
    }

    const genericMatch = text.match(/([0-9]{8}-\d)/);
    if (genericMatch) {
      const code = genericMatch[1];
      const remainder = text.replace(/(?:cpv(?:\s*principale)?[:\s]*)?[0-9]{8}-\d\s*[-:]?\s*/i, '').trim();
      const cleanedRemainder = remainder
        .replace(/\(([^)]+)\)$/, '')
        .replace(/\s+/g, ' ')
        .replace(/[;]+$/, '')
        .trim();
      if (cleanedRemainder) {
        return `${cleanedRemainder} (CPV ${code})`;
      }
      return `CPV ${code}`;
    }

    return text;
  }

  document.addEventListener('gare:upload:completed', () => {
    if (garaId) {
      scheduleLoadJobs();
    }
  });

  scheduleLoadJobs();

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
        jobsEl.classList.add('hidden');
        return;
      }

      jobsEl.classList.remove('hidden');

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
      const res = await customFetch('gare', 'jobPull', { job_id: jobId });
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
      jobsEl.classList.add('hidden');
      return;
    }

    jobsEl.classList.remove('hidden');

    const contents = Array.from(jobs.values())
      .map((job) => {
        return `
          <div class="job-content single" id="gare-job-${job.job_id}">
            ${renderJobSummary(job)}
            ${renderJobResultsHTML(job)}
          </div>
        `;
      })
      .join('');

    jobsEl.innerHTML = `
      <div class="jobs-content jobs-content-single no-print">${contents}</div>
      <div class="jobs-print print-only" id="gare-print-root">
        ${renderJobsPrint()}
      </div>
    `;

    const printRoot = document.getElementById('gare-print-root');
    if (printRoot) {
      printRoot.setAttribute('data-print-root', 'true');
    }

    initializeExtractionTables(jobsEl);

    attachDetailHandlers(jobsEl);
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

  function renderJobSummary(job) {
    const percent = progressPercent(job);
    const updated = job.updated_at || job.completed_at || job.created_at;
    const createdLabel = formatDate(job.created_at);
    const updatedLabel = formatDate(updated);
    const completedLabel = formatDate(job.completed_at);
    const statusText = job.status_label || statusLabel(job.status);
    const resultsCount =
      job.results && job.results.ok && Array.isArray(job.results.data)
        ? job.results.data.length
        : null;
    return `
      <section class="job-summary">
        <header class="job-summary-header">
          <div class="job-summary-title">
            <span class="job-summary-icon" aria-hidden="true">📄</span>
            <div class="job-summary-text">
              <h2 class="job-summary-name">${escapeHtml(job.file_name || 'Documento senza nome')}</h2>
              <div class="job-summary-meta">
                ${job.gara_id ? `<span class="job-summary-chip">Gara #${escapeHtml(String(job.gara_id))}</span>` : ''}
                <span class="job-summary-chip job-summary-chip-muted">Aggiornato ${updatedLabel}</span>
              </div>
            </div>
          </div>
          <div class="job-summary-status-group">
            <span class="status-chip status-${job.status}">${escapeHtml(statusText)}</span>
            <div class="job-summary-progress">
              <div class="job-summary-progress-track">
                <span style="width:${percent}%;"></span>
              </div>
              <span class="job-summary-progress-value">${percent}%</span>
            </div>
          </div>
        </header>
        <div class="gare-detail-summary job-summary-grid">
          <div class="summary-card">
            <span class="field-label">Job ID</span>
            <span class="field-value">#${escapeHtml(String(job.job_id))}</span>
          </div>
          <div class="summary-card">
            <span class="field-label">Creato</span>
            <span class="field-value">${createdLabel}</span>
          </div>
          <div class="summary-card">
            <span class="field-label">Completato</span>
            <span class="field-value">${completedLabel}</span>
          </div>
          ${job.gara_id ? `<div class="summary-card"><span class="field-label">Codice gara</span><span class="field-value">#${escapeHtml(String(job.gara_id))}</span></div>` : ''}
          ${resultsCount !== null ? `<div class="summary-card"><span class="field-label">Elementi estratti</span><span class="field-value">${escapeHtml(String(resultsCount))}</span></div>` : ''}
        </div>
      </section>
    `;
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

  function renderJobPrintSummary(job) {
    const percent = progressPercent(job);
    const statusText = job.status_label || statusLabel(job.status);
    const createdLabel = formatDate(job.created_at);
    const updatedLabel = formatDate(job.completed_at || job.updated_at || job.created_at);
    const completedLabel = formatDate(job.completed_at);

    return `
      <header class="print-job-header">
        <div class="print-job-title">
          <h2>${escapeHtml(resolveOggettoAppalto(job) || job.file_name || 'Documento senza nome')}</h2>
        </div>
        <div class="print-job-meta">
          <dl>
            <div><dt>Job ID</dt><dd>#${escapeHtml(String(job.job_id))}</dd></div>
            <div><dt>Stato</dt><dd>${escapeHtml(statusText)}</dd></div>
            <div><dt>Avanzamento</dt><dd>${percent}%</dd></div>
            <div><dt>Creato</dt><dd>${createdLabel}</dd></div>
            <div><dt>Ultimo aggiornamento</dt><dd>${updatedLabel}</dd></div>
            <div><dt>Completato</dt><dd>${completedLabel}</dd></div>
          </dl>
        </div>
      </header>
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

  function renderJobResultsHTML(job) {
    const data = job.results;
    if (!data || !data.ok || !Array.isArray(data.data) || !data.data.length) {
      return '<div class="processing-message">Nessun dato disponibile.</div>';
    }

    if (!job.normalized_items) {
      job.normalized_items = normalizeItems(data.data);
      // IMPORTANTE: Ordina gli items usando DETTAGLIO_GARA_ORDER
      job.normalized_items = sortExtractionItems(job.normalized_items);
    }

    // Se c'è un titolo "Oggetto dell'appalto", nascondi la voce duplicata nella lista
    const hasOggettoAppaltoTitle = resolveOggettoAppalto(job);
    let itemsToRender = job.normalized_items;
    if (hasOggettoAppaltoTitle) {
      itemsToRender = job.normalized_items.filter((item) => {
        const typeCode = (item.type_code || item.tipo || item.type || '').toLowerCase();
        return typeCode !== 'oggetto_appalto' && 
               typeCode !== 'oggetto_dell_appalto' && 
               typeCode !== 'oggetto_della_gara';
      });
    }

    const rows = itemsToRender
      .map((item, index) => {
        const detailId = `job-${job.job_id}-detail-${index}`;
        const typeInfo = resolveExtractionType(item);
        const typeCellHtml = `<span class="type-label">${escapeHtml(typeInfo.label)}</span>`;
        const value = renderValueCell(item);
        return `
          <tr class="extraction-row ${index % 2 === 0 ? 'even' : 'odd'}">
            <td class="type-cell">${typeCellHtml}</td>
            <td class="value-cell">${value}</td>
            <td class="details-cell"><button class="toggle-details" data-target="${detailId}">Dettagli</button></td>
          </tr>
          <tr class="details-row hidden" id="${detailId}">
            <td class="details-spacer"></td>
            <td colspan="2" class="details-content">
              <div class="details-inner">${renderExtractionDetail(item)}</div>
            </td>
          </tr>
        `;
      })
      .join('');

    return `
      <div class="results-container">
        <table class="results-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Valore</th>
              <th class="details-col no-print">Dettagli</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
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

  function renderSopralluogoSplit(item, parsedValueJson) {
    const details = buildSopralluogoDetails(item, parsedValueJson);
    return `
      <div class="sopralluogo-split">
        <div class="sopralluogo-row">
          <span class="sopralluogo-label">Sopralluogo obbligatorio</span>
          <span class="sopralluogo-value">${details.required ? 'Sì' : 'No'}</span>
        </div>
        <div class="sopralluogo-row">
          <span class="sopralluogo-label">Richiesta sopralluogo entro</span>
          <span class="sopralluogo-value">${escapeHtml(details.deadline || '—')}</span>
        </div>
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

  function summarizeExtractionValue(item) {
    const typeCode = item.type_code || item.tipo || item.type;
    if (typeCode === 'sopralluogo_obbligatorio') {
      const status =
        normalizeSopralluogoStatus(item.display_value || item.valore_display) ||
        normalizeSopralluogoStatus(extractPrimaryValue(item)) ||
        stringifyValue(item.display_value || item.valore_display || extractPrimaryValue(item));
      const deadlineLabel = resolveSopralluogoDeadline(item);
      const combined = [status, deadlineLabel].filter(Boolean).join(' – ');
      if (combined) {
        return truncate(combined.replace(/\s+/g, ' '), 150);
      }
    }

    if (item.table && Array.isArray(item.table.rows) && item.table.rows.length) {
      if (typeCode === 'requisiti_tecnico_professionali') {
        const summary = stringifyValue(
          item.display_value || item.valore_display || extractPrimaryValue(item)
        );
        if (summary) {
          return truncate(summary.replace(/\s+/g, ' '), 150);
        }
      }
      return summarizeTable(item.table);
    }
    const summary = stringifyValue(
      item.display_value || item.valore_display || extractPrimaryValue(item)
    );
    if (!summary && item.empty_reason) {
      return truncate(item.empty_reason, 150);
    }
    if (!summary) return '';
    return truncate(summary, 150);
  }

  function renderExtractionDetail(item) {
    if (item && item.synthetic === 'sopralluogo' && item.synthetic_kind === 'deadline') {
      // Se la deadline è vuota o "—", mostra un messaggio appropriato
      const deadlineValue = item.display_value || '';
      if (!deadlineValue || deadlineValue === '—' || deadlineValue.trim() === '') {
        return '<div class="response-value">Non è prevista una data specifica per la richiesta di sopralluogo.</div>';
      }
      // Altrimenti mostra il valore normalmente
      return renderTabbedDetail(buildExtractionTabs(item.synthetic_source || item));
    }
    const source = item && item.synthetic_source ? item.synthetic_source : item;
    return renderTabbedDetail(buildExtractionTabs(source));
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
      results: null,
    };
  }

  function progressPercent(job) {
    const total = Math.max(1, job.progress_total || 100);
    const done = Math.max(0, Math.min(total, job.progress_done || 0));
    return Math.round((done / total) * 100);
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

  // ── NEXTCLOUD FILES WIDGET ────────────────────────────────────────────────
  (function initNcWidget() {
    const widget = document.getElementById('gare-nc-widget');
    const filesList = document.getElementById('gd-nc-files-list');
    const browseBtn = document.getElementById('gd-nc-browse-btn');
    const modal = document.getElementById('gd-nc-modal');
    const modalClose = document.getElementById('gd-nc-modal-close');
    const modalList = document.getElementById('gd-nc-modal-list');
    const modalConfirm = document.getElementById('gd-nc-modal-confirm');
    if (!widget || !filesList || !browseBtn || !modal) return;

    let selectedPaths = new Set();
    let ncBrowserItems = [];

    function getJobId() { return garaId || initialJobId; }

    function renderFileChip(file) {
      const chip = document.createElement('div');
      chip.style.cssText = 'display:flex;align-items:center;gap:6px;padding:5px 10px;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:20px;font-size:12px;max-width:260px;';
      chip.title = file.path;
      const nameEl = document.createElement('span');
      nameEl.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:190px;';
      nameEl.textContent = file.name || file.path;
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = 'Rimuovi';
      removeBtn.style.cssText = 'background:none;border:none;cursor:pointer;color:#e74c3c;font-size:14px;padding:0;line-height:1;flex-shrink:0;';
      removeBtn.addEventListener('click', () => detachFile(file.path));
      chip.appendChild(nameEl);
      chip.appendChild(removeBtn);
      return chip;
    }

    async function loadFiles() {
      const jid = getJobId();
      if (!jid) { setTimeout(loadFiles, 300); return; }
      try {
        const res = await customFetch('gare', 'getNcFiles', { job_id: jid });
        renderFiles((res && res.success && Array.isArray(res.data)) ? res.data : []);
        widget.style.display = '';
      } catch (e) { console.warn('NC widget: errore caricamento', e); }
    }

    function renderFiles(files) {
      filesList.innerHTML = '';
      if (!files.length) {
        filesList.innerHTML = '<span style="color:#6c757d;font-size:12px;">Nessun documento allegato</span>';
        return;
      }
      files.forEach(f => filesList.appendChild(renderFileChip(f)));
    }

    async function detachFile(path) {
      const jid = getJobId();
      if (!jid) return;
      if (!confirm('Rimuovere questo documento dalla gara?')) return;
      try {
        const res = await customFetch('gare', 'detachNcFile', { job_id: jid, path });
        renderFiles((res && res.success && Array.isArray(res.data)) ? res.data : []);
      } catch (e) { console.warn('NC widget: errore detach', e); }
    }

    async function openBrowser() {
      const jid = getJobId();
      if (!jid) return;
      selectedPaths.clear();
      modalList.innerHTML = '<em style="color:#6c757d;font-size:13px;">Caricamento...</em>';
      modal.style.display = '';
      try {
        const res = await customFetch('gare', 'listNcFolder', { job_id: jid });
        ncBrowserItems = (res && res.success && Array.isArray(res.data)) ? res.data : [];
        renderBrowserList();
      } catch (e) {
        modalList.innerHTML = '<em style="color:#e74c3c;font-size:13px;">Errore caricamento cartella NC</em>';
      }
    }

    function renderBrowserList() {
      modalList.innerHTML = '';
      if (!ncBrowserItems.length) {
        modalList.innerHTML = '<em style="color:#6c757d;font-size:13px;">Cartella vuota</em>';
        return;
      }
      ncBrowserItems.forEach(item => {
        const row = document.createElement('label');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px;border-radius:6px;cursor:pointer;font-size:13px;';
        row.addEventListener('mouseenter', () => { row.style.background = '#f6f8fa'; });
        row.addEventListener('mouseleave', () => { row.style.background = ''; });
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = item.path;
        cb.checked = selectedPaths.has(item.path);
        cb.addEventListener('change', () => {
          if (cb.checked) selectedPaths.add(item.path);
          else selectedPaths.delete(item.path);
        });
        const nameEl = document.createElement('span');
        nameEl.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        nameEl.textContent = item.name || item.path;
        row.appendChild(cb);
        row.appendChild(nameEl);
        modalList.appendChild(row);
      });
    }

    async function attachSelected() {
      const jid = getJobId();
      if (!jid || !selectedPaths.size) return;
      modal.style.display = 'none';
      const toAttach = ncBrowserItems.filter(i => selectedPaths.has(i.path));
      for (const item of toAttach) {
        try {
          await customFetch('gare', 'attachNcFile', {
            job_id: jid,
            path: item.path,
            name: item.name || item.path,
            mime: item.mime || 'application/octet-stream',
            size: item.size || 0,
          });
        } catch (e) { console.warn('NC widget: errore attach', e); }
      }
      loadFiles();
    }

    browseBtn.addEventListener('click', openBrowser);
    modalClose.addEventListener('click', () => { modal.style.display = 'none'; });
    modalConfirm.addEventListener('click', attachSelected);
    modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });

    loadFiles();
  })();

  // ── COMMESSA WIDGET ───────────────────────────────────────────────────────
  (function initCommessaWidget() {
    const widget = document.getElementById('gare-commessa-widget');
    const input = document.getElementById('gd-commessa');
    const suggestBox = document.getElementById('gd-commessa-suggestions');
    if (!widget || !input || !suggestBox) return;

    // Load current value when job_id is known
    async function loadCommessa(jobId) {
      try {
        const res = await customFetch('gare', 'getGaraMetadata', { job_id: jobId });
        if (res && res.success && res.data) {
          input.value = res.data.codice_commessa || '';
        }
        widget.style.display = '';
      } catch (e) {
        console.warn('Commessa widget: errore caricamento', e);
      }
    }

    // Watch for garaId to be set
    function tryInit() {
      const jid = garaId || initialJobId;
      if (jid) {
        loadCommessa(jid);
      } else {
        setTimeout(tryInit, 300);
      }
    }
    tryInit();

    let debTimer;
    function debounceSearch(fn, ms) {
      return function(q) { clearTimeout(debTimer); debTimer = setTimeout(() => fn(q), ms); };
    }

    function renderSuggestions(items) {
      suggestBox.innerHTML = '';
      if (!items.length) { suggestBox.style.display = 'none'; return; }
      items.forEach(item => {
        const div = document.createElement('div');
        div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;';
        div.textContent = item.label || item.value;
        div.addEventListener('mousedown', e => {
          e.preventDefault();
          input.value = item.value;
          suggestBox.style.display = 'none';
          saveCommessa(item.value);
        });
        div.addEventListener('mouseenter', () => { div.style.background = '#f0f4ff'; });
        div.addEventListener('mouseleave', () => { div.style.background = ''; });
        suggestBox.appendChild(div);
      });
      suggestBox.style.display = '';
    }

    async function saveCommessa(codice) {
      const jid = garaId || initialJobId;
      if (!jid) return;
      try {
        const res = await customFetch('gare', 'updateGaraField', { job_id: jid, field: 'codice_commessa', value: codice });
        if (!res || !res.success) console.warn('Commessa widget: errore salvataggio', res);
      } catch (e) {
        console.warn('Commessa widget: errore salvataggio', e);
      }
    }

    const doSearch = debounceSearch(async q => {
      if (q.length < 2) { suggestBox.style.display = 'none'; return; }
      try {
        const res = await customFetch('gare', 'searchCommesse', { q });
        renderSuggestions((res && res.success && Array.isArray(res.data)) ? res.data : []);
      } catch (e) { suggestBox.style.display = 'none'; }
    }, 300);

    input.addEventListener('input', () => doSearch(input.value));
    input.addEventListener('blur', () => setTimeout(() => { suggestBox.style.display = 'none'; }, 150));
  })();

  function normalizeSopralluogoDeadlineLabel(value) {
    if (!value) return null;
    const normalized = String(value).trim();
    if (!normalized) return null;
    if (/^(termine|entro)/i.test(normalized)) {
      return normalized.charAt(0).toUpperCase() + normalized.slice(1);
    }
    return `Entro ${normalized}`;
  }
})();