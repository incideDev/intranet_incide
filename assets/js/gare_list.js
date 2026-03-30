(function () {
  // Usa funzioni globali da main_core.js
  // statusLabel Ã¨ specifico per job/estrazione gare, definito localmente
  function statusLabel(status) {
    const map = {
      queued: "In coda",
      processing: "In elaborazione",
      in_progress: "In elaborazione",
      completed: "Completato",
      done: "Completato",
      failed: "Fallito",
      error: "Errore",
    };
    const key = (status || "").toLowerCase();
    if (!key) return "â€”";
    return map[key] || key.charAt(0).toUpperCase() + key.slice(1);
  }

  // Stati gare (sincronizzati con GareService.php)
  const GARE_STATUS_MAP = {
    1: "In valutazione",
    2: "In corso",
    3: "Consegnata",
    4: "Aggiudicata",
    5: "Non aggiudicata",
  };

  const GARE_STATUS_COLORS = {
    1: "#f0b429", // In valutazione - giallo/arancio
    2: "#5b8def", // In corso - blu
    3: "#17a2b8", // Consegnata - azzurro/turchese
    4: "#63b365", // Aggiudicata - verde
    5: "#e74c3c", // Non aggiudicata - rosso
  };

  function getGaraStatusLabel(statusId) {
    if (statusId === null || statusId === undefined)
      return GARE_STATUS_MAP[1] || "In valutazione";
    return GARE_STATUS_MAP[statusId] || "Sconosciuto";
  }

  function getGaraStatusColor(statusId) {
    if (statusId === null || statusId === undefined)
      return GARE_STATUS_COLORS[1] || "#f0b429";
    return GARE_STATUS_COLORS[statusId] || "#f0b429";
  }

  function formatCurrencyValue(value) {
    if (value === null || value === undefined || value === "") return null;
    const number =
      typeof value === "number"
        ? value
        : parseFloat(
            String(value)
              .replace(/[^0-9,.-]/g, "")
              .replace(/\.(?=\d{3,})/g, "")
              .replace(",", "."),
          );
    if (Number.isNaN(number)) return null;
    return number.toLocaleString("it-IT", {
      style: "currency",
      currency: "EUR",
    });
  }

  const tableBody = document.querySelector("#gare-table tbody");
  const emptyMessage = document.getElementById("gare-empty");
  const hasTable = !!tableBody;

  const overlay = document.getElementById("modalAddGaraOverlay");
  const modal = document.getElementById("modalAddGara");
  const closeBtn = document.getElementById("gare-modal-close");
  const cancelBtn = document.getElementById("gareUploadCancel");

  const form = document.getElementById("gareUploadForm");
  const uploadArea = document.getElementById("gareUploadArea");
  const fileInput = document.getElementById("gareUploadInput");
  const selectLink = document.getElementById("gareUploadSelect");
  const selectedWrapper = document.getElementById("gareSelectedFiles");
  const selectedList = document.getElementById("gareSelectedList");
  const selectedCounter = document.getElementById("gareSelectedCounter");
  const submitBtn = document.getElementById("gareUploadSubmit");
  const statusBox = document.getElementById("gareUploadStatus");
  const statusList = document.getElementById("gareUploadStatusList");
  let currentFiles = [];
  let isUploadingForm = false;

  const POLL_INTERVAL_MIN = 2000;
  const POLL_INTERVAL_MAX = 15000;
  let isLoading = false;
  let currentRows = [];
  const pollingJobs = new Map();
  const pollingIntervals = new Map(); // jobId → current interval ms
  const blacklistedJobs = new Set();

  function openModal() {
    overlay.classList.add("show");
    modal.classList.add("show");
  }

  function closeModal() {
    overlay.classList.remove("show");
    modal.classList.remove("show");
    resetUpload();
  }

  window.openGareUploadModal = openModal;
  window.closeGareUploadModal = closeModal;
  window.updateParticipation = updateParticipation;

  overlay?.addEventListener("click", closeModal);
  closeBtn?.addEventListener("click", closeModal);
  cancelBtn?.addEventListener("click", closeModal);

  // ESC per chiudere il modale è gestito dal KeyboardManager globale in main_core.js

  selectLink?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    fileInput?.click();
  });

  fileInput?.addEventListener("change", (e) => {
    handleFiles(e.target.files);
  });

  if (uploadArea) {
    uploadArea.setAttribute("tabindex", "0");

    uploadArea.addEventListener("click", (e) => {
      if (
        e.target instanceof HTMLElement &&
        e.target.closest(".gare-upload-select")
      ) {
        return;
      }
      fileInput?.click();
    });

    uploadArea.addEventListener("keydown", (e) => {
      if (!fileInput) return;
      const isActionKey = e.key === "Enter" || e.key === " ";
      if (isActionKey) {
        e.preventDefault();
        fileInput.click();
      }
    });

    ["dragenter", "dragover", "dragleave", "drop"].forEach((eventName) => {
      uploadArea.addEventListener(eventName, preventDefaults, false);
    });

    ["dragenter", "dragover"].forEach((eventName) => {
      uploadArea.addEventListener(eventName, () =>
        uploadArea.classList.add("drag-over"),
      );
    });

    ["dragleave", "drop"].forEach((eventName) => {
      uploadArea.addEventListener(eventName, () =>
        uploadArea.classList.remove("drag-over"),
      );
    });

    uploadArea.addEventListener("drop", (e) => {
      const dt = e.dataTransfer;
      handleFiles(dt.files);
    });
  }

  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }

  function handleFiles(list) {
    const files = Array.from(list || []);
    const valid = files.filter((file) => {
      if (file.type !== "application/pdf") {
        alert(`Il file "${file.name}" non Ã¨ un PDF valido e verrÃ  ignorato.`);
        return false;
      }
      return true;
    });

    if (!valid.length) return;

    currentFiles = valid;
    renderSelectedFiles();
  }

  function renderSelectedFiles() {
    selectedList.innerHTML = "";

    if (!currentFiles.length) {
      selectedWrapper.classList.add("hidden");
      if (selectedCounter) selectedCounter.textContent = "";
      submitBtn.disabled = true;
      return;
    }

    currentFiles.forEach((file, idx) => {
      const li = document.createElement("li");
      li.className = "gare-selected-item";

      const icon = document.createElement("span");
      icon.className = "gare-selected-icon";
      const iconGlyph = document.createElement("span");
      iconGlyph.className = "gare-icon-file";
      icon.appendChild(iconGlyph);

      const info = document.createElement("div");
      info.className = "gare-selected-info";

      const name = document.createElement("span");
      name.className = "gare-selected-name";
      name.textContent = file.name;

      const size = document.createElement("span");
      size.className = "gare-selected-size";
      size.textContent = formatSize(file.size);

      info.appendChild(name);
      info.appendChild(size);

      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "gare-remove-file";
      remove.innerHTML = "&times;";
      remove.setAttribute("aria-label", `Rimuovi ${file.name}`);
      remove.addEventListener("click", () => {
        currentFiles.splice(idx, 1);
        if (fileInput) {
          const dt = new DataTransfer();
          currentFiles.forEach((f) => dt.items.add(f));
          fileInput.files = dt.files;
        }
        renderSelectedFiles();
      });

      li.appendChild(icon);
      li.appendChild(info);
      li.appendChild(remove);
      selectedList.appendChild(li);
    });

    selectedWrapper.classList.remove("hidden");
    if (selectedCounter) {
      selectedCounter.textContent =
        currentFiles.length === 1
          ? "1 file selezionato"
          : `${currentFiles.length} file selezionati`;
    }
    submitBtn.disabled = false;
  }

  function resetUpload() {
    currentFiles = [];
    if (fileInput) fileInput.value = "";
    renderSelectedFiles();
    statusBox.classList.add("hidden");
    statusList.innerHTML = "";
  }

  form?.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    await performUpload();
  });

  // Helper per verificare permessi (fallback a true se la funzione non esiste)
  function hasPermission(perm) {
    if (typeof window.userHasPermission === "function") {
      return window.userHasPermission(perm);
    }
    // Fallback: se la funzione non esiste, verifica tramite CURRENT_USER
    if (window.CURRENT_USER) {
      const perms = window.CURRENT_USER.permissions || [];
      const roleId = window.CURRENT_USER.role_id || window.CURRENT_USER.roleId;
      // Superuser (role_id = 1) ha tutti i permessi
      if (roleId === 1 || roleId === "1") return true;
      return perms.includes(perm) || perms.includes("*");
    }
    // Se non c'è CURRENT_USER, assume permesso (per retrocompatibilità)
    return true;
  }

  // Funzione per caricare un singolo file specifico
  async function performSingleFileUpload(fileName, force = false) {
    // Trova il file nell'array currentFiles
    const file = currentFiles.find((f) => f.name === fileName);
    if (!file) {
      alert(`File ${fileName} non trovato nella lista.`);
      return;
    }

    const types = await loadExtractionTypes();
    const extractionTypes = JSON.stringify(types);

    const fd = new FormData();
    fd.append("file[]", file, file.name);
    fd.append("extraction_types", extractionTypes);
    if (window.currentGaraIdForUpload) {
      fd.append("gara_id", window.currentGaraIdForUpload);
    }
    if (force) {
      fd.append("force", "1");
    }
    fd.append("section", "gare");
    fd.append("action", "uploadExtraction");

    try {
      const response = await fetch("ajax.php", {
        method: "POST",
        credentials: "same-origin",
        headers: buildCsrfHeader(),
        body: fd,
      });

      const json = await response.json();

      // Aggiorna solo l'item corrispondente nella lista
      const existingLi = statusList.querySelector(
        `li[data-file-name="${fileName}"]`,
      );
      if (existingLi) {
        // Rimuovi il vecchio item
        existingLi.remove();
      }

      // Aggiungi il nuovo stato
      renderUploadStatusItem(fileName, json, force, 1, 1);

      if (json && json.ok) {
        loadGareList();
      }
    } catch (error) {
      const existingLi = statusList.querySelector(
        `li[data-file-name="${fileName}"]`,
      );
      if (existingLi) {
        existingLi.innerHTML = `<span>${fileName}:</span> Errore: ${window.escapeHtml ? window.escapeHtml(error.message || error) : error.message || error}`;
      }
    }
  }

  async function performUpload(force = false) {
    // Controllo permesso: create_gare o edit_gare, fallback a view_gare
    if (
      !hasPermission("create_gare") &&
      !hasPermission("edit_gare") &&
      !hasPermission("view_gare")
    ) {
      alert("Non hai i permessi per caricare nuovi bandi di gara.");
      return;
    }
    if (!currentFiles.length || isUploadingForm) return;

    submitBtn.disabled = true;
    isUploadingForm = true;

    const types = await loadExtractionTypes();
    const extractionTypes = JSON.stringify(types);
    const allResults = [];
    let successCount = 0;
    let errorCount = 0;

    // Mostra il modale e inizializza la lista
    statusBox.classList.remove("hidden");
    statusList.innerHTML = "";

    const files = currentFiles;

    // Pre-flight: check API is online and has enough quota
    try {
      const healthRes = await customFetch('gare', 'apiHealth');
      if (!healthRes.success || !healthRes.data || healthRes.data.status !== 'healthy') {
        if (typeof window.showToast === 'function') {
          showToast('API di estrazione non disponibile. Riprova più tardi.', 'error');
        }
        isUploadingForm = false;
        submitBtn.disabled = false;
        return;
      }

      const needed = files.length * types.length;
      const quotaRes = await customFetch('gare', 'checkQuota', { needed });
      if (quotaRes.success && quotaRes.data && quotaRes.data.can_fulfill === false) {
        const remaining = quotaRes.data.rpd_remaining ?? 0;
        if (typeof window.showToast === 'function') {
          showToast(`Quota API insufficiente: servono ${needed} richieste ma ne restano solo ${remaining}. Riprova domani.`, 'warning');
        }
        isUploadingForm = false;
        submitBtn.disabled = false;
        return;
      }
    } catch (e) {
      console.warn('Pre-flight check failed, proceeding anyway', e);
    }

    // Carica i file uno alla volta per evitare problemi con post_max_size
    for (let i = 0; i < currentFiles.length; i++) {
      const file = currentFiles[i];

      const fd = new FormData();
      fd.append("file[]", file, file.name);
      fd.append("extraction_types", extractionTypes);
      if (window.currentGaraIdForUpload) {
        fd.append("gara_id", window.currentGaraIdForUpload);
      }
      if (force) {
        fd.append("force", "1");
      }
      fd.append("section", "gare");
      fd.append("action", "uploadExtraction");

      try {
        const response = await fetch("ajax.php", {
          method: "POST",
          credentials: "same-origin",
          headers: buildCsrfHeader(),
          body: fd,
        });

        const json = await response.json();
        allResults.push({ file: file.name, result: json });

        if (json && json.ok) {
          successCount++;
        } else {
          errorCount++;
        }

        // Aggiungi lo stato per questo file alla lista (non sostituire tutto)
        renderUploadStatusItem(
          file.name,
          json,
          force,
          i + 1,
          currentFiles.length,
        );

        // Piccola pausa tra un upload e l'altro per non sovraccaricare il server
        if (i < currentFiles.length - 1) {
          await new Promise((resolve) => setTimeout(resolve, 100));
        }
      } catch (error) {
        errorCount++;
        allResults.push({ file: file.name, error: error.message || error });
        const errorLi = document.createElement("li");
        errorLi.innerHTML = `<span>Errore per ${file.name}:</span> ${window.escapeHtml ? window.escapeHtml(error.message || error) : error.message || error}`;
        statusList.appendChild(errorLi);
      }
    }

    // Mostra riepilogo finale
    if (successCount > 0 || errorCount > 0) {
      const summary = `Caricati: ${successCount}${errorCount > 0 ? `, Errori: ${errorCount}` : ""}`;
      if (successCount > 0) {
        loadGareList();
      }
    }

    submitBtn.disabled = false;
    isUploadingForm = false;
  }

  function buildCsrfHeader() {
    const token = sessionStorage.getItem("CSRFtoken") || "";
    return token ? { "Csrf-Token": token } : {};
  }

  // Nuova funzione per aggiungere un singolo item alla lista invece di sostituirla
  function renderUploadStatusItem(
    fileName,
    json,
    isForced = false,
    currentIndex = 1,
    totalFiles = 1,
  ) {
    if (!statusList || !statusBox) return;

    statusBox.classList.remove("hidden");

    // Crea un nuovo elemento li per questo file
    const li = document.createElement("li");
    li.setAttribute("data-file-name", fileName);

    if (!json) {
      li.innerHTML = `<span>${fileName} (${currentIndex}/${totalFiles}):</span> Risposta vuota dal server.`;
      statusList.appendChild(li);
      return;
    }

    if (json.error) {
      li.innerHTML = `<span>${fileName} (${currentIndex}/${totalFiles}):</span> ${window.escapeHtml ? window.escapeHtml(json.error) : json.error}`;
      statusList.appendChild(li);
      return;
    }

    if (!json.ok) {
      li.innerHTML = `<span>${fileName} (${currentIndex}/${totalFiles}):</span> ${window.escapeHtml ? window.escapeHtml(JSON.stringify(json)) : JSON.stringify(json)}`;
      statusList.appendChild(li);
      return;
    }

    if (json.jobs && json.jobs.length > 0) {
      const job = json.jobs[0];
      const jobId = job.job_id || json.job_id;
      const batchId = job.ext_batch_id || json.ext_batch_id;
      const isDuplicate = job.duplicate || false;
      const errorMsg = job.error || "";

      let content = `<span>${fileName} (${currentIndex}/${totalFiles}):</span> `;

      if (isDuplicate) {
        li.classList.add("duplicate");
        // Se errorMsg contiene già "File già caricato", usa solo quello
        // Altrimenti costruisci il messaggio con il job_id
        if (errorMsg && errorMsg.toLowerCase().includes("file già caricato")) {
          content += window.escapeHtml ? window.escapeHtml(errorMsg) : errorMsg;
        } else if (errorMsg) {
          content += window.escapeHtml ? window.escapeHtml(errorMsg) : errorMsg;
        } else {
          content += `File già caricato (job #${jobId})`;
        }
      } else {
        content += `Caricato con successo`;
        if (jobId) {
          content += ` (job #${jobId})`;
        }
        if (batchId) {
          content += ` - batch: ${batchId.substring(0, 8)}...`;
        }
      }

      li.innerHTML = content;

      // Aggiungi pulsante refresh se è un duplicato e non è forzato
      if (isDuplicate && !isForced && jobId) {
        const refreshBtn = document.createElement("button");
        refreshBtn.className = "refresh-existing";
        refreshBtn.textContent = "Ri-estrai";
        refreshBtn.title = "Riesegui estrazione forzando il ricaricamento";
        refreshBtn.onclick = async (e) => {
          e.preventDefault();
          await performSingleFileUpload(fileName, true);
        };
        li.appendChild(refreshBtn);
      }

      statusList.appendChild(li);
    } else {
      li.innerHTML = `<span>${fileName} (${currentIndex}/${totalFiles}):</span> ${window.escapeHtml ? window.escapeHtml(JSON.stringify(json)) : JSON.stringify(json)}`;
      statusList.appendChild(li);
    }
  }

  function renderUploadStatus(json, isForced = false) {
    statusList.innerHTML = "";
    statusBox.classList.remove("hidden");

    if (!json) {
      statusList.innerHTML =
        "<li><span>Errore:</span> Risposta vuota dal server.</li>";
      return;
    }

    let hasDuplicate = false;

    if (json.ok && Array.isArray(json.jobs)) {
      json.jobs.forEach((job, idx) => {
        const li = document.createElement("li");
        if (job.error) {
          const lower = (job.error || "").toLowerCase();
          const isDuplicate =
            job.duplicate ||
            lower.includes("giÃ  caricato") ||
            lower.includes("giÃ  elaborato");
          if (isDuplicate && !isForced) {
            hasDuplicate = true;
            li.classList.add("duplicate");
            li.innerHTML = `
              <span>${window.escapeHtml ? window.escapeHtml(job.file_name || `File ${idx + 1}`) : job.file_name || `File ${idx + 1}`}:</span>
              ${window.escapeHtml ? window.escapeHtml(job.error) : job.error}
              <button type="button" class="refresh-existing" title="Riesegui estrazione forzando il ricaricamento">Ri-estrai</button>
            `;
          } else {
            li.innerHTML = `<span>${window.escapeHtml ? window.escapeHtml(job.file_name || `File ${idx + 1}`) : job.file_name || `File ${idx + 1}`}:</span> ${window.escapeHtml ? window.escapeHtml(job.error) : job.error}`;
          }
        } else {
          li.innerHTML = `<span>${window.escapeHtml ? window.escapeHtml(job.file_name || `File ${idx + 1}`) : job.file_name || `File ${idx + 1}`}:</span> Job #${job.job_id} avviato.`;
        }
        statusList.appendChild(li);
      });
      document.dispatchEvent(
        new CustomEvent("gare:upload:completed", { detail: json }),
      );
      if (hasDuplicate && !isForced) {
        const buttons = statusList.querySelectorAll(".refresh-existing");
        buttons.forEach((btn) => {
          btn.addEventListener(
            "click",
            async (e) => {
              e.preventDefault();
              await performUpload(true);
            },
            { once: true },
          );
        });
      }
    } else if (json.error) {
      statusList.innerHTML = `<li><span>Errore:</span> ${window.escapeHtml ? window.escapeHtml(json.error) : json.error}</li>`;
    } else {
      statusList.innerHTML = `<li>${window.escapeHtml ? window.escapeHtml(JSON.stringify(json)) : JSON.stringify(json)}</li>`;
    }
  }

  // Determina se siamo in "Elenco Gare" (participation=1) o "Estrazione Bandi" (participation=0 o NULL)
  function isElencoGarePage() {
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get("page");
    return page === "elenco_gare";
  }

  // Determina se siamo in "Archivio Gare"
  function isArchivioGarePage() {
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get("page");
    return page === "archivio_gare";
  }

  async function loadGareList() {
    if (!hasTable || isLoading) {
      return;
    }
    isLoading = true;
    // Mostra stato di caricamento solo se la tabella è vuota (primo load)
    if (tableBody && !tableBody.children.length) {
      tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#6c757d;padding:24px;font-size:13px;">Caricamento gare...</td></tr>';
      emptyMessage?.classList.add('hidden');
    }
    try {
      // Se siamo in "Archivio Gare", filtra solo archiviate
      // Se siamo in "Elenco Gare", filtra solo participation=1 e non archiviate
      // Se siamo in "Estrazione Bandi", filtra solo participation=0 o NULL e non archiviate
      const filters = isArchivioGarePage()
        ? { archiviata: true }
        : isElencoGarePage()
          ? { participation: true }
          : { participation: false };
      const res = await customFetch("gare", "getGare", filters);
      if (!res || res.success === false) {
        console.error('Errore caricamento gare (API):', res?.error || 'Errore sconosciuto');
        tableBody.innerHTML = '';
        if (emptyMessage) {
          emptyMessage.textContent = 'Errore nel caricamento delle gare. Riprova più tardi.';
          emptyMessage.classList.remove('hidden');
        }
        return;
      }
      const rows = Array.isArray(res?.data) ? res.data : [];
      const uniqueRows = dedupeRows(rows);
      currentRows = uniqueRows;
      renderTable(uniqueRows);

      // Kanban solo per elenco_gare
      if (isElencoGarePage()) {
        renderKanban(uniqueRows);
        renderKpiPanel(uniqueRows);
        populateFilters(uniqueRows);
      }

      uniqueRows.forEach((row) => {
        if (isJobInProgress(row)) {
          ensureJobPolling(row.job_id);
        } else {
          stopJobPolling(row.job_id);
        }
      });
    } catch (e) {
      console.error("Errore caricamento gare:", e);
    } finally {
      isLoading = false;
    }
  }

  function dedupeRows(rows) {
    const map = new Map();
    rows.forEach((row) => {
      if (!row || typeof row.job_id === "undefined") return;
      const existing = map.get(row.job_id);
      if (!existing) {
        map.set(row.job_id, row);
        return;
      }
      const preferNew =
        Boolean(!existing.titolo && row.titolo) ||
        Boolean(!existing.ente && row.ente) ||
        Boolean(!existing.luogo && row.luogo);
      if (preferNew) {
        map.set(row.job_id, Object.assign({}, existing, row));
      }
    });
    return Array.from(map.values());
  }

  function ensureJobPolling(jobId) {
    if (pollingJobs.has(jobId) || blacklistedJobs.has(jobId)) return;
    pollingJobs.set(jobId, null);
    pollJob(jobId);
  }

  function stopJobPolling(jobId) {
    const timer = pollingJobs.get(jobId);
    if (timer) {
      clearTimeout(timer);
    }
    pollingJobs.delete(jobId);
    pollingIntervals.delete(jobId);
  }

  function stopAllPolling() {
    pollingJobs.forEach((timer) => {
      if (timer) clearTimeout(timer);
    });
    pollingJobs.clear();
    pollingIntervals.clear();
  }

  async function pollJob(jobId) {
    try {
      const res = await customFetch('gare', 'jobPull', { job_id: jobId }, { showLoader: false });
      
      // Se c'è un errore o il job è local_only, blocchiamo il polling per questo job
      if (!res || res.success === false || (res && res.local_only)) {
        if (res && res.local_only) {
          console.warn(`Job ${jobId} è local_only (manca batch_id), fermiamo il polling`);
        } else {
          console.error(`Errore nel polling del job ${jobId}:`, res?.error || res?.message || 'Risposta invalida');
        }
        
        blacklistedJobs.add(jobId);
        stopJobPolling(jobId);
        
        // Aggiorna lo stato locale per riflettere l'errore senza ricaricare tutto (che causerebbe loop)
        const row = currentRows.find(r => r.job_id === jobId);
        if (row) {
          row.estrazione = 'error';
          row.estrazione_label = (res && res.local_only) ? 'Errore API (Manca batch_id)' : 'Errore Collegamento API';
          if (hasTable) renderTable(currentRows);
        }
        return;
      }

      applyJobUpdate(jobId, res);

      if (res && (res.estrazione || res.status) && isJobInProgress({ estrazione: res.estrazione || res.status })) {
        // Adaptive polling: start fast, slow down over time
        const currentInterval = pollingIntervals.get(jobId) || POLL_INTERVAL_MIN;
        const nextInterval = Math.min(currentInterval * 1.5, POLL_INTERVAL_MAX);
        pollingIntervals.set(jobId, nextInterval);
        const timer = setTimeout(() => pollJob(jobId), Math.round(currentInterval));
        pollingJobs.set(jobId, timer);
      } else {
        stopJobPolling(jobId);
        if (!isLoading) {
          loadGareList();
        }
      }
    } catch (e) {
      console.error('Errore eccezionale jobPull:', e);
      blacklistedJobs.add(jobId);
      stopJobPolling(jobId);
    }
  }

  function applyJobUpdate(jobId, res) {
    if (!res) return;
    const row = currentRows.find((r) => r.job_id === jobId);
    if (!row) return;

    // Aggiorna stato estrazione (rinominato da 'status' per evitare conflitti)
    if (res.estrazione || res.status) {
      row.estrazione = res.estrazione || res.status;
      row.estrazione_label = statusLabel(row.estrazione);
      // RetrocompatibilitÃ  (deprecato)
      row.status = row.estrazione;
      // NOTA: status_label ora si riferisce allo stato gara, non piÃ¹ all'estrazione
    }

    // Aggiorna stato gara se presente
    if (res.status_id !== undefined || res.gara_status_label !== undefined) {
      row.status_id =
        res.status_id !== undefined ? res.status_id : row.status_id;
      row.stato = row.status_id; // Alias
      row.status_label =
        res.gara_status_label ||
        res.status_label ||
        (row.status_id ? getGaraStatusLabel(row.status_id) : row.status_label);
      row.status_color =
        res.gara_status_color ||
        res.status_color ||
        (row.status_id ? getGaraStatusColor(row.status_id) : row.status_color);
    }

    if (res.progress) {
      const total = Math.max(1, parseInt(res.progress.total || 0, 10));
      let done = Math.max(0, parseInt(res.progress.done || 0, 10));
      if (done > total) done = total;
      row.progress_total = total;
      row.progress_done = done;
      row.progress_percent = Math.round((done / total) * 100);
    }

    row.updated_at = new Date().toISOString();

    if (hasTable) {
      renderTable(currentRows);
    }

    // Kanban solo per elenco_gare
    if (isElencoGarePage()) {
      renderKanban(currentRows);
    }
  }

  function isJobInProgress(row) {
    // Usa 'estrazione' se disponibile, altrimenti 'status' per retrocompatibilitÃ
    const estrazione = (row?.estrazione || row?.status || "").toLowerCase();
    return (
      estrazione === "queued" ||
      estrazione === "processing" ||
      estrazione === "in_progress"
    );
  }

  async function updateParticipation(jobId, participation) {
    // Controllo permesso: edit_gare o view_gare
    if (!hasPermission("edit_gare") && !hasPermission("view_gare")) {
      alert("Non hai i permessi per modificare la partecipazione alla gara.");
      return;
    }
    try {
      const res = await customFetch("gare", "updateParticipation", {
        job_id: jobId,
        participation: participation,
      });
      if (res.success) {
        // Se siamo su "Estrazione Bandi" e clicchiamo "SÃ¬" (participation = true),
        // la gara deve essere SPOSTATA in "Elenco Gare", quindi rimuovila dalla tabella corrente
        if (!isElencoGarePage() && participation === true) {
          // Rimuovi la riga dalla tabella e dall'array locale
          const rowIndex = currentRows.findIndex((r) => r.job_id === jobId);
          if (rowIndex !== -1) {
            currentRows.splice(rowIndex, 1);
          }
          // Rimuovi la riga dal DOM
          const row = tableBody
            .querySelector(`tr button[data-job-id="${jobId}"]`)
            ?.closest("tr");
          if (row) {
            row.remove();
          }
          // Se non ci sono piÃ¹ righe, mostra il messaggio vuoto
          if (currentRows.length === 0) {
            emptyMessage?.classList.remove("hidden");
          }
        } else if (!isElencoGarePage() && participation === false) {
          // Se siamo su "Estrazione Bandi" e clicchiamo "No" (participation = false),
          // la gara deve essere SPOSTATA in "Archivio", quindi rimuovila dalla tabella corrente
          const rowIndex = currentRows.findIndex((r) => r.job_id === jobId);
          if (rowIndex !== -1) {
            currentRows.splice(rowIndex, 1);
          }
          // Rimuovi la riga dal DOM
          const row = tableBody
            .querySelector(`tr button[data-job-id="${jobId}"]`)
            ?.closest("tr");
          if (row) {
            row.remove();
          }
          // Se non ci sono piÃ¹ righe, mostra il messaggio vuoto
          if (currentRows.length === 0) {
            emptyMessage?.classList.remove("hidden");
          }
        } else if (isElencoGarePage() && participation === false) {
          // Se siamo su "Elenco Gare" e clicchiamo "No" (participation = false),
          // la gara deve tornare in "Archivio", quindi rimuovila dalla tabella corrente
          const rowIndex = currentRows.findIndex((r) => r.job_id === jobId);
          if (rowIndex !== -1) {
            currentRows.splice(rowIndex, 1);
          }
          // Rimuovi la riga dal DOM
          const row =
            tableBody.querySelector(`tr[data-job-id="${jobId}"]`) ||
            Array.from(tableBody.querySelectorAll("tr")).find((tr) => {
              const firstCell = tr.querySelector("td");
              return (
                firstCell && firstCell.textContent.trim() === String(jobId)
              );
            });
          if (row) {
            row.remove();
          }
          // Aggiorna anche kanban se presente
          if (isElencoGarePage()) {
            renderKanban(currentRows);
          }
          // Se non ci sono piÃ¹ righe, mostra il messaggio vuoto
          if (currentRows.length === 0) {
            emptyMessage?.classList.remove("hidden");
          }
        } else {
          // Altrimenti aggiorna solo il valore locale
          const row = currentRows.find((r) => r.job_id === jobId);
          if (row) {
            row.participation = participation;
          }
          // Ricarica la tabella per aggiornare i bottoni
          renderTable(currentRows);
        }
      }
      return res;
    } catch (e) {
      console.error("Errore aggiornamento participation:", e);
      return { success: false, message: "Errore aggiornamento" };
    }
  }

  function renderTable(rows) {
    if (!hasTable) return;

    // NASCONDI la tabella immediatamente per evitare il "battito di ciglia"
    const table = document.getElementById("gare-table");
    if (table) {
      table.classList.remove("table-ready");
    }

    tableBody.innerHTML = "";

    if (!rows.length) {
      emptyMessage?.classList.remove("hidden");
      // Rimuovi paginazione se presente
      if (table) {
        const wrapper = table.closest(".gare-table-wrapper");
        const pagination = wrapper?.querySelector(".table-pagination");
        if (pagination) pagination.remove();
        // Mostra la tabella anche se vuota
        table.classList.add("table-ready");
      }
      return;
    }
    emptyMessage?.classList.add("hidden");

    const isElenco = isElencoGarePage();
    const isArchivio = isArchivioGarePage();

    rows.forEach((row) => {
      const tr = document.createElement("tr");
      tr.dataset.stato = row.gara_status_id || row.status_id || '';
      tr.dataset.bu = row.business_unit || '';
      tr.dataset.assegnato = row.assegnato_a || '';
      tr.dataset.ente = row.ente || '';

      if (isElenco || isArchivio) {
        // "Elenco Gare": mostra solo status_id (stato gara), non stato estrazione
        // Colonne: Numero Gara, Ente, Titolo, Settore, Tipologia, Luogo, Data Uscita, Data Scadenza, Stato
        // Per le gare confermate (Elenco Gare), genera formato "Ga_XX/YY" basato sulla data
        // Per le gare archiviate, mantieni "tmp_#job_id"
        let codiceGaraElenco = "\u2014";
        if (row.job_id) {
          if (isArchivio) {
            // Archivio: mantieni formato tmp_#job_id
            codiceGaraElenco = `tmp_#${row.job_id}`;
          } else {
            // Elenco Gare: genera formato "Ga_XX/YY" basato sulla data di creazione
            const dataCreazione = row.created_at || row.data_uscita;
            if (dataCreazione) {
              try {
                const date = new Date(dataCreazione);
                const anno = date.getFullYear().toString().slice(-2); // Ultimi 2 numeri dell'anno
                const mese = String(date.getMonth() + 1).padStart(2, "0");
                // Usa le ultime 2 cifre del job_id come numero progressivo
                const numero = String(row.job_id).slice(-2).padStart(2, "0");
                codiceGaraElenco = `Ga_${numero}/${anno}`;
              } catch (e) {
                // Fallback se la data non è valida
                codiceGaraElenco = `Ga_${String(row.job_id).slice(-2).padStart(2, "0")}/24`;
              }
            } else {
              // Fallback se non c'è data
              codiceGaraElenco = `Ga_${String(row.job_id).slice(-2).padStart(2, "0")}/24`;
            }
          }
        }
        tr.innerHTML = `
          <td class="gara-number">${window.escapeHtml ? window.escapeHtml(codiceGaraElenco) : codiceGaraElenco}</td>
          <td>${window.escapeHtml ? window.escapeHtml(row.ente || "\u2014") : row.ente || "\u2014"}</td>
          <td class="cell-title">${window.escapeHtml ? window.escapeHtml(row.titolo || "\u2014") : row.titolo || "\u2014"}</td>
          <td>${(() => {
            // Per Elenco Gare, mostra identificazione_opera se disponibile (descrizione completa),
            // altrimenti primo_id_opera (codice), altrimenti settore
            const valoreSettore =
              row.identificazione_opera !== null &&
              row.identificazione_opera !== undefined &&
              row.identificazione_opera !== ""
                ? row.identificazione_opera
                : row.primo_id_opera !== null &&
                    row.primo_id_opera !== undefined &&
                    row.primo_id_opera !== ""
                  ? row.primo_id_opera
                  : row.settore || "\u2014";
            return window.escapeHtml
              ? window.escapeHtml(valoreSettore)
              : valoreSettore;
          })()}</td>
          <td>${window.escapeHtml ? window.escapeHtml(row.tipologia || "\u2014") : row.tipologia || "\u2014"}</td>
          <td>${window.escapeHtml ? window.escapeHtml(row.luogo || "\u2014") : row.luogo || "\u2014"}</td>
          <td>${window.formatDate ? window.formatDate(row.data_uscita) || "\u2014" : "\u2014"}</td>
          <td>${window.formatDate ? window.formatDate(row.data_scadenza) || "\u2014" : "\u2014"}</td>
          <td class="gare-status-cell">
            <select class="gare-status-select" data-job-id="${row.job_id}" data-gara-id="${row.gara_id || row.job_id}">
              ${Object.keys(GARE_STATUS_MAP)
                .map((statusId) => {
                  const statusIdNum = parseInt(statusId, 10);
                  const isSelected =
                    (row.gara_status_id || row.status_id || 1) === statusIdNum;
                  const statusColor = GARE_STATUS_COLORS[statusIdNum];
                  const statusLabel = GARE_STATUS_MAP[statusIdNum];
                  return `<option value="${statusIdNum}" ${isSelected ? "selected" : ""} data-color="${statusColor}">${statusLabel}</option>`;
                })
                .join("")}
            </select>
          </td>
        `;
      } else {
        // "Estrazione Bandi": mostra due bottoni Sï¿½/No per participation
        // Colonne: Numero Gara, Bando, Importo Lavori, Importo Corrispettivo, Ente, Luogo, Data Uscita, Data Scadenza, Participation, Avanzamento, Aggiornato
        const participationActive = row.participation === true;
        // Per estrazione_bandi, mostra "tmp_#job_id" invece del numero gara
        const codiceGara = row.job_id ? `tmp_#${row.job_id}` : "\u2014";
        const importoLavoriLabel =
          row.importo_lavori_formatted ||
          formatCurrencyValue(row.importo_lavori) ||
          "\u2014";
        const importoCorrispettiviLabel =
          row.importo_corrispettivi_formatted ||
          formatCurrencyValue(row.importo_corrispettivi) ||
          "\u2014";
        const esc = window.escapeHtml || ((s) => s);
        const fDate = window.formatDate || (() => "\u2014");
        const fDateTime = window.formatDateTime || (() => "\u2014");
        const titolo = row.titolo || row.file_name || `Job ${row.job_id}`;
        const fileName = row.file_name || '';
        const pctVal = row.progress_percent || 0;
        const isComplete = pctVal >= 100 || row.estrazione === 'completed';
        const isFailed = row.estrazione === 'error' || row.estrazione === 'failed';
        const progressCls = isComplete ? 'table-pill--success' : (isFailed ? 'table-pill--danger' : (pctVal > 0 ? 'table-pill--info' : 'table-pill--default'));

        tr.classList.add('table-row-clickable');
        tr.innerHTML = `
          <td class="col-code">${esc(codiceGara)}</td>
          <td class="col-description cell-title">
            <div class="cell-stack">
              <span class="cell-primary">${esc(titolo)}</span>
              ${fileName && fileName !== titolo ? `<span class="cell-secondary">${esc(fileName)}</span>` : ''}
            </div>
          </td>
          <td class="col-amount">${esc(importoLavoriLabel)}</td>
          <td class="col-amount">${esc(importoCorrispettiviLabel)}</td>
          <td>${esc(row.ente || "\u2014")}</td>
          <td>${esc(row.luogo || "\u2014")}</td>
          <td class="col-date">${fDate(row.data_uscita) || "\u2014"}</td>
          <td class="col-date">${fDate(row.data_scadenza) || "\u2014"}</td>
          <td class="col-status">
            <div class="participation-toggle">
              <button type="button" class="part-btn part-yes ${participationActive ? 'on' : ''}"
                      data-job-id="${row.job_id}" data-participation="true"
                      onclick="event.stopPropagation(); updateParticipation(${row.job_id}, true);">Si</button>
              <button type="button" class="part-btn part-no ${!participationActive ? 'on' : ''}"
                      data-job-id="${row.job_id}" data-participation="false"
                      onclick="event.stopPropagation(); updateParticipation(${row.job_id}, false);">No</button>
            </div>
          </td>
          <td class="col-status">
            <span class="table-pill ${progressCls}">${isComplete ? 'Completato' : (isFailed ? 'Errore' : pctVal + '%')}</span>
          </td>
          <td class="col-date">${fDateTime(row.updated_at || row.completed_at || row.created_at) || "\u2014"}</td>
        `;
      }
      tr.addEventListener("click", (e) => {
        // Non navigare se clicchi sui bottoni participation o sul select stato
        if (
          e.target.classList.contains("participation-btn") ||
          e.target.closest(".participation-buttons") ||
          e.target.classList.contains("gare-status-select") ||
          e.target.closest(".gare-status-select")
        ) {
          return;
        }
        if (row.gara_id || row.job_id) {
          window.location.href = `index.php?section=commerciale&page=gare_dettaglio&gara_id=${row.gara_id || row.job_id}`;
        }
      });

      tableBody.appendChild(tr);
    });

    // Aggiungi event listener per i select di stato (solo per Elenco Gare)
    if (isElenco || isArchivio) {
      document.querySelectorAll(".gare-status-select").forEach((select) => {
        const jobId = parseInt(select.dataset.jobId, 10);
        const currentStatusId = parseInt(select.value, 10);

        // Applica colore al select basato sull'opzione selezionata
        updateSelectColor(select);

        select.addEventListener("change", async function () {
          const newStatusId = parseInt(this.value, 10);
          if (newStatusId === currentStatusId) return;

          await updateGaraStatusFromSelect(jobId, newStatusId, select);
        });
      });
    }

    // Inizializza paginazione client-side dopo il rendering
    // NASCONDI tutte le righe immediatamente per evitare il "battito di ciglia"
    const allRows = Array.from(tableBody.querySelectorAll("tr"));
    allRows.forEach((row) => {
      row.style.display = "none";
    });

    setTimeout(() => {
      const table = document.getElementById("gare-table");
      if (table && typeof window.initClientSidePagination === "function") {
        // Reset flag per permettere reinizializzazione
        table.dataset.paginationInitialized = "false";
        // Rimuovi paginazione esistente se presente
        const wrapper = table.closest(".gare-table-wrapper");
        const existingPagination = wrapper?.querySelector(".table-pagination");
        if (existingPagination) {
          existingPagination.remove();
        }
        // La tabella verrà mostrata quando initClientSidePagination completa updateView()
        window.initClientSidePagination(table);
      } else if (table) {
        // Fallback: se initClientSidePagination non è disponibile, mostra comunque la tabella
        table.classList.add("table-ready");
      }
    }, 200);
  }

  function updateSelectColor(select) {
    const statusId = parseInt(select.value, 10);
    const color = GARE_STATUS_COLORS[statusId] || GARE_STATUS_COLORS[1];

    // Applica colore al bordo e al testo
    select.style.borderColor = color;
    select.style.color = color;

    // Applica background con trasparenza (converti hex a rgba)
    const hex = color.replace("#", "");
    const r = parseInt(hex.substr(0, 2), 16);
    const g = parseInt(hex.substr(2, 2), 16);
    const b = parseInt(hex.substr(4, 2), 16);
    select.style.backgroundColor = `rgba(${r}, ${g}, ${b}, 0.1)`;
  }

  async function updateGaraStatusFromSelect(jobId, statusId, selectElement) {
    if (!hasPermission("edit_gare") && !hasPermission("view_gare")) {
      selectElement.value = selectElement.dataset.originalValue || "1";
      updateSelectColor(selectElement);
      alert("Non hai i permessi per modificare lo stato della gara.");
      return;
    }

    // Salva il valore originale per il rollback in caso di errore
    if (!selectElement.dataset.originalValue) {
      selectElement.dataset.originalValue = selectElement.value;
    }

    try {
      const res = await customFetch("gare", "updateGaraStatus", {
        job_id: jobId,
        status_id: statusId,
      });

      if (res.success) {
        // Aggiorna il valore locale
        const row = currentRows.find((r) => r.job_id === jobId);
        if (row) {
          row.gara_status_id = statusId;
          row.status_id = statusId;
          row.status_label = getGaraStatusLabel(statusId);
          row.status_color = getGaraStatusColor(statusId);
        }

        // Aggiorna il colore del select
        updateSelectColor(selectElement);
        selectElement.dataset.originalValue = statusId.toString();

        // Aggiorna anche il kanban se la vista kanban è attiva
        const kanbanView = document.getElementById("kanban-view");
        if (kanbanView && !kanbanView.classList.contains("hidden")) {
          renderKanban(currentRows);
        }

        // Mostra notifica di successo
        if (typeof window.showToast === "function") {
          const statusLabel = getGaraStatusLabel(statusId);
          showToast(`Stato aggiornato: ${statusLabel}`, "success");
        }
      } else {
        console.error("Errore aggiornamento stato gara:", res.message);
        // Ripristina il valore precedente
        selectElement.value = selectElement.dataset.originalValue || "1";
        updateSelectColor(selectElement);
        if (typeof window.showToast === "function") {
          showToast(res.message || "Errore aggiornamento stato", "error");
        }
      }
    } catch (error) {
      console.error("Errore aggiornamento stato gara:", error);
      // Ripristina il valore precedente
      selectElement.value = selectElement.dataset.originalValue || "1";
      updateSelectColor(selectElement);
      if (typeof window.showToast === "function") {
        showToast("Errore aggiornamento stato", "error");
      }
    }
  }

  // Normalizza status_id (puÃ² essere numero o stringa)
  function normalizeStatusId(status) {
    if (typeof status === "number") return status;
    if (typeof status === "string") {
      const statusMap = {
        "in valutazione": 1,
        "in corso": 2,
        consegnata: 3,
        aggiudicata: 4,
        "non aggiudicata": 5,
        // RetrocompatibilitÃ  con vecchi stati
        bozza: 1,
        pubblicata: 2,
        archiviata: 5,
      };
      const key = status.toLowerCase().trim();
      if (statusMap[key]) return statusMap[key];
      const num = parseInt(key, 10);
      if (!isNaN(num)) return num;
    }
    return 1; // default a In valutazione
  }

  function renderKanban(rows) {
    const kanbanContainer = document.querySelector(
      "#kanban-view .kanban-container",
    );
    if (!kanbanContainer) return;

    // Pulisci tutte le colonne
    const taskContainers = kanbanContainer.querySelectorAll(".task-container");
    taskContainers.forEach((container) => {
      container.innerHTML = "";
    });

    if (!rows || !rows.length) return;

    // Filtra solo participation=1 per kanban (solo su elenco_gare)
    const filteredRows = rows.filter((row) => row.participation === true);

    // Mappa stato => array di gare (usa gara_status_id, non status estrazione)
    const byStatus = {};
    filteredRows.forEach((row) => {
      // Usa gara_status_id se disponibile, altrimenti status_id, altrimenti default 1
      const statusId = normalizeStatusId(
        row.gara_status_id || row.status_id || row.stato || 1,
      );
      if (!byStatus[statusId]) {
        byStatus[statusId] = [];
      }
      byStatus[statusId].push(row);
    });

    // Popola ogni colonna
    Object.keys(byStatus).forEach((statusId) => {
      const column = kanbanContainer.querySelector(
        `.kanban-column[data-status-id="${statusId}"]`,
      );
      if (!column) return;

      const taskContainer = column.querySelector(".task-container");
      if (!taskContainer) return;

      const gare = byStatus[statusId];
      gare.forEach((gara) => {
        const garaId = gara.gara_id || gara.id || gara.job_id;
        const titolo =
          gara.titolo || gara.file_name || `Job ${gara.job_id || "N/A"}`;
        const ente = gara.ente || "â€”";
        const luogo = gara.luogo || "â€”";
        const dataScadenza = window.formatDate
          ? window.formatDate(gara.data_scadenza) || "â€”"
          : "â€”";
        const progress = gara.progress_percent || 0;

        const taskEl = document.createElement("div");
        taskEl.className = "task";
        taskEl.id = `task-ext_jobs_${garaId}`;
        taskEl.setAttribute("data-task-id", garaId);
        taskEl.setAttribute("data-tabella", "ext_jobs"); // Ora usiamo ext_jobs
        taskEl.setAttribute("data-tipo", "gara");
        taskEl.setAttribute("data-job-id", garaId); // Aggiungi job_id esplicito
        taskEl.setAttribute("draggable", "true");

        taskEl.innerHTML = `
          <div class="task-header">
            <span class="task-title">${window.escapeHtml ? window.escapeHtml(titolo) : titolo}</span>
          </div>
          <div class="task-body">
            <div class="task-meta">
              <span class="task-meta-item"><strong>Ente:</strong> ${window.escapeHtml ? window.escapeHtml(ente) : ente}</span>
              ${luogo !== "â€”" ? `<span class="task-meta-item"><strong>Luogo:</strong> ${window.escapeHtml ? window.escapeHtml(luogo) : luogo}</span>` : ""}
              ${dataScadenza !== "â€”" ? `<span class="task-meta-item"><strong>Scadenza:</strong> ${dataScadenza}</span>` : ""}
            </div>
            ${
              progress > 0
                ? `
              <div class="task-progress">
                <div class="progress-bar" style="width: ${progress}%"></div>
                <span class="progress-text">${progress}%</span>
              </div>
            `
                : ""
            }
          </div>
        `;

        // Click per aprire dettaglio
        taskEl.addEventListener("click", (e) => {
          if (e.target.closest(".task")) {
            if (gara.gara_id) {
              window.location.href = `index.php?section=commerciale&page=gare_dettaglio&gara_id=${gara.gara_id}`;
            } else if (gara.job_id) {
              window.location.href = `index.php?section=commerciale&page=gare_dettaglio&job_id=${gara.job_id}`;
            }
          }
        });

        // Double click per aprire dettaglio
        taskEl.addEventListener("dblclick", () => {
          if (gara.gara_id) {
            window.location.href = `index.php?section=commerciale&page=gare_dettaglio&gara_id=${gara.gara_id}`;
          } else if (gara.job_id) {
            window.location.href = `index.php?section=commerciale&page=gare_dettaglio&job_id=${gara.job_id}`;
          }
        });

        taskContainer.appendChild(taskEl);
      });
    });

    // Setup drag&drop dopo aver renderizzato le task
    setupGareKanbanDragAndDrop();
  }

  // Setup drag&drop per kanban gare (event delegation)
  // Usa event delegation per funzionare con elementi creati dinamicamente
  let gareKanbanDragDropSetup = false;
  function setupGareKanbanDragAndDrop() {
    const container = document.querySelector("#kanban-view .kanban-container");
    if (!container) return;

    // Evita di aggiungere listener multipli
    if (gareKanbanDragDropSetup) return;
    gareKanbanDragDropSetup = true;

    // Event delegation per dragstart sulle task
    container.addEventListener("dragstart", (e) => {
      const task = e.target.closest(".task");
      if (!task) return;
      e.dataTransfer.setData("text/plain", task.id);
      e.stopPropagation(); // Impedisci a kanban.js di gestire lo stesso evento
      task.classList.add("dragging");
    });

    // Event delegation per dragend sulle task
    container.addEventListener("dragend", (e) => {
      const task = e.target.closest(".task");
      if (task) {
        task.classList.remove("dragging");
      }
      document.body.classList.remove("dragging");
      e.stopPropagation();
    });

    // Event delegation per dragover sulle colonne
    container.addEventListener("dragover", (e) => {
      const column = e.target.closest(".kanban-column");
      if (!column) return;
      e.preventDefault();
      e.stopPropagation(); // Impedisci a kanban.js di gestire lo stesso evento
      column.classList.add("drag-over");
    });

    // Event delegation per dragleave sulle colonne
    container.addEventListener("dragleave", (e) => {
      const column = e.target.closest(".kanban-column");
      if (!column) return;
      // Controlla se stiamo uscendo davvero dalla colonna (non entrando in un figlio)
      const rect = column.getBoundingClientRect();
      const x = e.clientX;
      const y = e.clientY;
      if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
        column.classList.remove("drag-over");
      }
      e.stopPropagation();
    });

    // Event delegation per drop sulle colonne
    container.addEventListener("drop", async (e) => {
      const column = e.target.closest(".kanban-column");
      if (!column) return;
      e.preventDefault();
      e.stopPropagation(); // Impedisci a kanban.js di gestire lo stesso evento
      column.classList.remove("drag-over");

      const taskId = e.dataTransfer.getData("text/plain");
      const task = document.getElementById(taskId);
      if (!task) {
        console.error("Task non trovata:", taskId);
        return;
      }

      const taskContainer = column.querySelector(".task-container");
      if (!taskContainer) {
        console.error("Task container non trovato nella colonna");
        return;
      }

      const newStatusId = column.getAttribute("data-status-id");
      if (!newStatusId) {
        console.error("Status ID non trovato nella colonna");
        return;
      }

      // Sposta la task visivamente
      taskContainer.appendChild(task);
      task.setAttribute("data-status-id", newStatusId);

      // Estrai job_id dal task ID (formato: task-ext_jobs_123)
      const parts = taskId.replace("task-", "").split("_");
      const jobId = parseInt(parts[parts.length - 1], 10);
      const statusId = parseInt(newStatusId, 10);

      if (!jobId || !statusId || statusId < 1 || statusId > 5) {
        console.error("Parametri non validi:", { jobId, statusId });
        return;
      }

      // Aggiorna lo stato nel backend
      try {
        // Controllo permesso: edit_gare o view_gare
        if (!hasPermission("edit_gare") && !hasPermission("view_gare")) {
          alert("Non hai i permessi per modificare lo stato della gara.");
          return;
        }
        const res = await customFetch("gare", "updateGaraStatus", {
          job_id: jobId,
          status_id: statusId,
        });

        if (res.success) {
          // Aggiorna il valore locale
          const row = currentRows.find((r) => r.job_id === jobId);
          if (row) {
            row.gara_status_id = statusId;
            row.status_id = statusId;
            row.status_label = getGaraStatusLabel(statusId);
            row.status_color = getGaraStatusColor(statusId);
          }

          // Mostra notifica di successo
          if (typeof window.showToast === "function") {
            const statusLabel = getGaraStatusLabel(statusId);
            showToast(`Gara spostata in: ${statusLabel}`, "success");
          }
        } else {
          console.error("Errore aggiornamento stato gara:", res.message);
          if (typeof window.showToast === "function") {
            showToast(res.message || "Errore aggiornamento stato", "error");
          }
          // Ripristina la posizione originale in caso di errore
          loadGareList();
        }
      } catch (error) {
        console.error("Errore aggiornamento stato gara:", error);
        if (typeof window.showToast === "function") {
          showToast("Errore aggiornamento stato", "error");
        }
        // Ripristina la posizione originale in caso di errore
        loadGareList();
      }
    });
  }

  // Registra il provider calendario per elenco_gare
  (function registerGareCalendarProvider() {
    if (typeof window.registerCalendarProvider !== "function") {
      // Fallback: registra direttamente se registerCalendarProvider non esiste
      if (!window.calendarProviders) window.calendarProviders = {};
    }

    // Provider calendario per elenco_gare
    const gareCalendarProvider = async function () {
      const filters = isArchivioGarePage()
        ? { archiviata: true }
        : isElencoGarePage()
          ? { participation: true }
          : { participation: false };

      try {
        const res = await customFetch("gare", "getGare", filters);
        const rows = Array.isArray(res?.data) ? res.data : [];

        const toISO = (s) => window.utils?.parseDateToISO?.(s) || s || "";

        return rows
          .map((r) => {
            // Usa job_id come ID principale (Ã¨ quello usato nel backend)
            const jobId = r.job_id || r.id;
            const garaId = r.gara_id;

            if (!jobId) return null;

            const titolo = r.titolo || r.file_name || `Job ${jobId}`;
            let startISO = toISO(r.data_uscita || r.data_inizio);
            let endISO = toISO(r.data_scadenza || r.data_fine);

            if (!startISO && endISO) startISO = endISO;
            if (!endISO && startISO) {
              const d = new Date(startISO);
              if (!isNaN(d.getTime())) {
                d.setDate(d.getDate() + 1);
                endISO = d.toISOString().slice(0, 10);
              }
            }

            if (!startISO || !startISO.match(/^\d{4}-\d{2}-\d{2}/)) return null;

            const statusId = r.gara_status_id || r.status_id || 1;

            // Usa gara_id se disponibile, altrimenti job_id per il link
            const linkId = garaId || jobId;

            return {
              id: `ext_jobs:${jobId}`,
              title: titolo,
              start: startISO,
              end: endISO || startISO,
              status: statusId,
              url: garaId
                ? `index.php?section=commerciale&page=gare_dettaglio&gara_id=${encodeURIComponent(garaId)}`
                : `index.php?section=commerciale&page=gare_dettaglio&job_id=${encodeURIComponent(jobId)}`,
              color: getGaraStatusColor(statusId),
              meta: {
                raw: {
                  ...r,
                  table_name: "ext_jobs",
                  id: jobId,
                  job_id: jobId,
                  gara_id: garaId,
                  gara_status_id: statusId,
                },
              },
            };
          })
          .filter(Boolean);
      } catch (error) {
        console.error("Errore provider calendario gare:", error);
        return [];
      }
    };

    // Registra per elenco_gare
    if (typeof window.registerCalendarProvider === "function") {
      window.registerCalendarProvider("elenco_gare", gareCalendarProvider);
    } else {
      window.calendarProviders["elenco_gare"] = gareCalendarProvider;
    }

    // Registra anche per 'gare' come fallback (per retrocompatibilitÃ )
    if (typeof window.registerCalendarProvider === "function") {
      window.registerCalendarProvider("gare", gareCalendarProvider);
    } else {
      window.calendarProviders["gare"] = gareCalendarProvider;
    }

    // Espone anche come provider globale se siamo su elenco_gare
    try {
      const url = new URL(window.location.href);
      const page = url.searchParams.get("page");
      if (page === "elenco_gare" || page === "gare") {
        window.calendarDataProvider = gareCalendarProvider;
      }
    } catch (e) {
      // Ignora errori di parsing URL
    }
  })();

  // Handler per drag&drop kanban (chiamato da kanban.js - fallback se kanban.js Ã¨ caricato)
  window.onKanbanTaskMoved = async function ({ id, table, newStatus }) {
    // id Ã¨ il job_id estratto dal task ID (formato: task-ext_jobs_123)
    // table Ã¨ 'ext_jobs'
    // newStatus Ã¨ il nuovo status_id (1-5)
    const jobId = parseInt(id, 10);
    const statusId = parseInt(newStatus, 10);

    if (!jobId || !statusId || statusId < 1 || statusId > 5) {
      console.error("Parametri non validi per onKanbanTaskMoved:", {
        id,
        table,
        newStatus,
      });
      return;
    }

    try {
      // Controllo permesso: edit_gare o view_gare
      if (!hasPermission("edit_gare") && !hasPermission("view_gare")) {
        alert("Non hai i permessi per modificare lo stato della gara.");
        return;
      }
      const res = await customFetch("gare", "updateGaraStatus", {
        job_id: jobId,
        status_id: statusId,
      });

      if (res.success) {
        // Aggiorna il valore locale
        const row = currentRows.find((r) => r.job_id === jobId);
        if (row) {
          row.gara_status_id = statusId;
          row.status_id = statusId;
          row.status_label = getGaraStatusLabel(statusId);
          row.status_color = getGaraStatusColor(statusId);
        }

        // Mostra notifica di successo
        if (typeof window.showToast === "function") {
          const statusLabel = getGaraStatusLabel(statusId);
          showToast(`Gara spostata in: ${statusLabel}`, "success");
        }
      } else {
        console.error("Errore aggiornamento stato gara:", res.message);
        if (typeof window.showToast === "function") {
          showToast(res.message || "Errore aggiornamento stato", "error");
        }
        // Ripristina la posizione originale in caso di errore
        loadGareList();
      }
    } catch (e) {
      console.error("Errore onKanbanTaskMoved:", e);
      if (typeof window.showToast === "function") {
        showToast("Errore aggiornamento stato", "error");
      }
      // Ripristina la posizione originale in caso di errore
      loadGareList();
    }
  };

  function defaultExtractionTypes() {
    return [
      "data_scadenza_gara_appalto",
      "data_uscita_gara_appalto",
      "importi_opere_per_categoria_id_opere",
      "link_portale_stazione_appaltante",
      "luogo_provincia_appalto",
      "oggetto_appalto",
      "requisiti_tecnico_professionali",
      "settore_industriale_gara_appalto",
      "sopralluogo_obbligatorio",
      "stazione_appaltante",
      "tipologia_di_appalto",
      "tipologia_di_gara",
      "importi_corrispettivi_categoria_id_opere",
      "importi_requisiti_tecnici_categoria_id_opere",
      "documentazione_richiesta_tecnica",
      "fatturato_globale_n_minimo_anni",
      "requisiti_di_capacita_economica_finanziaria",
      "requisiti_idoneita_professionale_gruppo_lavoro",
    ];
  }

  let cachedExtractionTypes = null;

  async function loadExtractionTypes() {
    if (cachedExtractionTypes) return cachedExtractionTypes;
    try {
      const res = await customFetch('gare', 'getExtractionTypes');
      if (res.success && Array.isArray(res.data) && res.data.length > 0) {
        cachedExtractionTypes = res.data;
        return cachedExtractionTypes;
      }
    } catch (e) {
      console.warn('Failed to load extraction types from API, using defaults', e);
    }
    return defaultExtractionTypes();
  }

  function formatSize(bytes) {
    if (!bytes && bytes !== 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${Math.round((bytes / Math.pow(k, i)) * 100) / 100} ${sizes[i]}`;
  }

  // Usa direttamente le funzioni globali da main_core.js
  // Non servono wrapper, se non sono disponibili useremo il fallback diretto

  window.currentGaraIdForUpload = null;
  loadGareList();

  // Observer per rilevare quando la vista kanban diventa visibile
  // e aggiornarla con i dati più recenti
  if (typeof MutationObserver !== "undefined") {
    const kanbanView = document.getElementById("kanban-view");
    if (kanbanView) {
      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (
            mutation.type === "attributes" &&
            mutation.attributeName === "class"
          ) {
            const isVisible = !kanbanView.classList.contains("hidden");
            if (isVisible && isElencoGarePage() && currentRows.length > 0) {
              // La vista kanban è diventata visibile, aggiorna con i dati più recenti
              renderKanban(currentRows);
            }
          }
        });
      });
      observer.observe(kanbanView, {
        attributes: true,
        attributeFilter: ["class"],
      });
    }
  }

  // Listener per i bottoni di cambio vista (fallback se MutationObserver non funziona)
  // Usa un listener con capture per intercettare il click prima di function-bar.js
  const kanbanButton = document.getElementById("kanbanButton");
  if (kanbanButton && isElencoGarePage()) {
    kanbanButton.addEventListener(
      "click",
      () => {
        // Dopo che la vista kanban è stata mostrata, aggiorna con i dati più recenti
        setTimeout(() => {
          if (currentRows.length > 0) {
            const kanbanView = document.getElementById("kanban-view");
            if (kanbanView && !kanbanView.classList.contains("hidden")) {
              renderKanban(currentRows);
            }
          }
        }, 150);
      },
      true,
    ); // Use capture phase to run before other handlers
  }

  // ── KPI PANEL ─────────────────────────────────────────────────────────────
  function renderKpiPanel(rows) {
    const panel = document.getElementById('gare-kpi-panel');
    if (!panel) return;

    const counts = { totale: rows.length };
    rows.forEach(row => {
      const sid = row.gara_status_id || row.status_id;
      if (sid) counts[sid] = (counts[sid] || 0) + 1;
    });

    const totEl = document.getElementById('kpi-val-totale');
    if (totEl) totEl.textContent = counts.totale || 0;
    [1, 2, 3, 4, 5].forEach(id => {
      const el = document.getElementById('kpi-val-' + id);
      if (el) el.textContent = counts[id] || 0;
    });

    panel.style.display = '';

    // Riapplica filtri se erano attivi (preserva stato durante refresh)
    const statoSel = document.getElementById('gf-stato');
    if (statoSel && statoSel.value) {
      applyFilters();
    }

    // Click su KPI card → filtra per stato
    if (!panel.dataset.kpiInitialized) {
      panel.dataset.kpiInitialized = '1';
      panel.querySelectorAll('.gare-kpi-card').forEach(card => {
        card.addEventListener('click', () => {
          const kpi = card.dataset.kpi;
          const statoSel = document.getElementById('gf-stato');
          if (statoSel) statoSel.value = kpi === 'totale' ? '' : kpi;
          panel.querySelectorAll('.gare-kpi-card').forEach(c => c.classList.remove('active'));
          card.classList.add('active');
          applyFilters();
        });
      });
    }
  }

  // ── FILTER BAR ────────────────────────────────────────────────────────────
  function populateFilters(rows) {
    const bar = document.getElementById('gare-filter-bar');
    if (!bar) return;

    const statoSel = document.getElementById('gf-stato');
    const buSel = document.getElementById('gf-bu');
    const assegnatoSel = document.getElementById('gf-assegnato');

    if (statoSel) {
      const seenStati = new Map();
      rows.forEach(row => {
        const id = row.gara_status_id || row.status_id;
        const label = row.gara_status_label || (typeof GARE_STATUS_MAP !== 'undefined' ? GARE_STATUS_MAP[id] : null) || id;
        if (id && !seenStati.has(id)) seenStati.set(id, label);
      });
      while (statoSel.options.length > 1) statoSel.remove(1);
      seenStati.forEach((label, id) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = label;
        statoSel.appendChild(opt);
      });
    }

    if (buSel) {
      const seenBu = new Set();
      rows.forEach(row => { if (row.business_unit) seenBu.add(row.business_unit); });
      while (buSel.options.length > 1) buSel.remove(1);
      [...seenBu].sort().forEach(bu => {
        const opt = document.createElement('option');
        opt.value = bu;
        opt.textContent = bu;
        buSel.appendChild(opt);
      });
    }

    if (assegnatoSel) {
      const seenAss = new Set();
      rows.forEach(row => { if (row.assegnato_a) seenAss.add(row.assegnato_a); });
      while (assegnatoSel.options.length > 1) assegnatoSel.remove(1);
      [...seenAss].sort().forEach(ass => {
        const opt = document.createElement('option');
        opt.value = ass;
        opt.textContent = ass;
        assegnatoSel.appendChild(opt);
      });
    }

    bar.style.display = '';

    if (!bar.dataset.filtersInitialized) {
      bar.dataset.filtersInitialized = '1';
      ['gf-stato', 'gf-bu', 'gf-assegnato'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', applyFilters);
      });
      const enteInput = document.getElementById('gf-ente');
      if (enteInput) enteInput.addEventListener('input', debounce(applyFilters, 200));
      const resetBtn = document.getElementById('gf-reset');
      if (resetBtn) {
        resetBtn.addEventListener('click', () => {
          ['gf-stato', 'gf-bu', 'gf-assegnato'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
          });
          const enteEl = document.getElementById('gf-ente');
          if (enteEl) enteEl.value = '';
          document.querySelectorAll('#gare-kpi-panel .gare-kpi-card').forEach(c => c.classList.remove('active'));
          applyFilters();
        });
      }
    }
  }

  function applyFilters() {
    const stato = document.getElementById('gf-stato')?.value || '';
    const bu = document.getElementById('gf-bu')?.value || '';
    const assegnato = document.getElementById('gf-assegnato')?.value || '';
    const ente = (document.getElementById('gf-ente')?.value || '').toLowerCase().trim();

    let visibleCount = 0;
    document.querySelectorAll('#gare-table tbody tr').forEach(tr => {
      const matchStato = !stato || String(tr.dataset.stato) === String(stato);
      const matchBu = !bu || tr.dataset.bu === bu;
      const matchAss = !assegnato || tr.dataset.assegnato === assegnato;
      const matchEnte = !ente || (tr.dataset.ente || '').toLowerCase().includes(ente);
      const visible = matchStato && matchBu && matchAss && matchEnte;
      tr.style.display = visible ? '' : 'none';
      if (visible) visibleCount++;
    });

    const countEl = document.getElementById('gf-count');
    if (countEl) {
      const hasFilter = stato || bu || assegnato || ente;
      countEl.textContent = hasFilter ? `${visibleCount} risultati` : '';
      countEl.style.display = hasFilter ? '' : 'none';
    }
  }

  function debounce(func, wait) {
    let t;
    return function(...args) {
      clearTimeout(t);
      t = setTimeout(() => func.apply(this, args), wait);
    };
  }

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && hasTable) {
      loadGareList();
      currentRows.forEach((row) => {
        if (isJobInProgress(row)) {
          ensureJobPolling(row.job_id);
        }
      });
    } else if (document.visibilityState === "hidden") {
      stopAllPolling();
    }
  });
})();
