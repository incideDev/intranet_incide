// assets/js/modules/modal_dock.js
// Dock flottante per job estrazione PDF (multi-upload, max 6)
// - Gestisce una coda "jobs[]" {jobId, fileName, status, progress, payload, draft}
// - Polling round-robin su job "in_progress"
// - Bolla cliccabile: toggle pannello espanso con elenco job
// - "Apri scheda" emette evento con il draft mappato; salvi solo su conferma

(function () {
  if (window.__GARE_DOCK_INITED) return;
  if (typeof window.customFetch !== 'function') {
    console.warn('[GareDock] customFetch non trovato: la dock non potrà contattare il server.');
  }

  const STATE_KEY = 'gareDockState';
  const MIN_POLL_MS = 3000;
  const MAX_POLL_MS = 15000;
  const MAX_BATCH = 6;
  let pollTimer = window.__GARE_DOCK_POLL_TIMER || null;
  let idleCycles = window.__GARE_DOCK_IDLE_CYCLES || 0;

  const store = (() => {
    try { const k = '__t__' + Math.random(); sessionStorage.setItem(k, '1'); sessionStorage.removeItem(k); return sessionStorage; }
    catch { return localStorage; }
  })();

  const qs = (sel) => document.querySelector(sel);
  const byId = (id) => document.getElementById(id);

  function safeParse(j, fb) { try { return JSON.parse(j); } catch { return fb; } }
  function safeSet(k, obj) {
    try { const s = JSON.stringify(obj); JSON.parse(s); store.setItem(k, s); } catch { }
  }

  function getState() { return safeParse(store.getItem(STATE_KEY), null); }
  function setState(st) { if (!st) store.removeItem(STATE_KEY); else safeSet(STATE_KEY, st); }

  function ensureDock() {
    if (byId('modal-dock')) return;
    const wrap = document.createElement('div');
    wrap.id = 'modal-dock';
    wrap.className = 'modal-dock';
    wrap.innerHTML = `
    <button class="dock-bubble is-hidden" id="dockGareBubble" aria-expanded="false" aria-controls="dockGarePanel" type="button">
      <span class="dock-title">Estrazione gara</span>
      <span class="dock-status" id="dockGareStatus" aria-live="polite">In corso…</span>
      <span class="dock-progress" id="dockGareProg"></span>
      <span class="dock-close" id="dockGareClose" aria-label="Rimuovi" role="button" tabindex="0">×</span>
    </button>
    <div class="dock-panel" id="dockGarePanel" role="dialog" aria-label="Estrazioni in corso o completate" aria-hidden="true">
      <div class="panel-head">
        <h4>Estrazioni</h4>
        <button class="btn" id="dockClearAll" type="button">Svuota</button>
      </div>
      <ul class="panel-list" id="dockJobList"></ul>
    </div>
  `;
    document.body.appendChild(wrap);

    const bubble = byId('dockGareBubble');
    const panel = byId('dockGarePanel');
    const closeX = byId('dockGareClose');

    function togglePanel(forceOpen = null) {
      const open = (forceOpen === null) ? !panel.classList.contains('open') : !!forceOpen;
      panel.classList.toggle('open', open);
      bubble.setAttribute('aria-expanded', String(open));
      panel.setAttribute('aria-hidden', String(!open));
      if (open) {
        renderPanel();
        // focus “gestibile” all’apertura
        const firstBtn = panel.querySelector('button, .btn, [tabindex]:not([tabindex="-1"])');
        if (firstBtn) { try { firstBtn.focus(); } catch (e) { } }
      } else {
        // ritorna focus alla bubble
        try { bubble.focus(); } catch (e) { }
      }
    }

    closeX.addEventListener('click', (e) => {
      e.stopPropagation();
      togglePanel(false);
      stopPolling();
      setState(null);
      updateDock();
    });
    closeX.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); closeX.click(); }
    });

    bubble.addEventListener('click', () => togglePanel());
    bubble.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePanel(); }
      if (e.key === 'Escape') { e.preventDefault(); togglePanel(false); }
    });
    document.addEventListener('keydown', (e) => {
      // ESC chiude il pannello se focalizzato/visibile
      if (e.key === 'Escape' && panel.classList.contains('open')) {
        togglePanel(false);
      }
    });

    byId('dockClearAll').addEventListener('click', () => {
      // mantieni solo job in_progress
      const st = getState() || {};
      const jobs = (st.jobs || []).filter(j => j.status === 'in_progress');
      st.jobs = jobs;
      setState(st);
      renderPanel();
      updateDock();
    });
  }

  function summarize(jobs) {
    const total = jobs.length;
    const done = jobs.filter(j => j.status === 'done').length;
    const inprog = jobs.filter(j => j.status === 'in_progress').length;
    const error = jobs.filter(j => j.status === 'error').length;
    return { total, done, inprog, error };
  }

  function updateDock() {
    const bubble = byId('dockGareBubble');
    const statusSpan = byId('dockGareStatus');
    const progSpan = byId('dockGareProg');
    const panel = byId('dockGarePanel');
    if (!bubble || !statusSpan) return;

    const st = getState();
    const hasJobs = !!(st && Array.isArray(st.jobs) && st.jobs.length > 0);

    if (!hasJobs) {
      bubble.classList.add('is-hidden');
      panel?.classList.remove('open');
      bubble.setAttribute('aria-expanded', 'false');
      panel?.setAttribute('aria-hidden', 'true');
      stopPolling(); // risparmia CPU quando non serve
      return;
    }

    bubble.classList.remove('is-hidden');
    bubble.classList.remove('is-progress', 'is-done', 'is-error', 'is-stalled');

    const { total, done, inprog, error } = summarize(st.jobs);
    let statusTxt = '';
    if (inprog > 0) { statusTxt = 'In corso…'; bubble.classList.add('is-progress'); }
    else if (error > 0 && done === 0) { statusTxt = 'Errori'; bubble.classList.add('is-error'); }
    else { statusTxt = 'Completate'; bubble.classList.add('is-done'); }
    statusSpan.textContent = statusTxt;
    progSpan.textContent = ` (${done}/${total})`;
  }

  function renderPanel() {
    const list = byId('dockJobList');
    if (!list) return;
    const st = getState() || { jobs: [] };
    list.innerHTML = '';
    (st.jobs || []).slice().reverse().forEach(job => {
      const li = document.createElement('li');
      li.className = 'panel-item';
      const statusCls = job.status === 'done' ? 'ok' : (job.status === 'error' ? 'bad' : '');
const p = job.progress && Number.isFinite(job.progress.done) && Number.isFinite(job.progress.total)
  ? ` ${job.progress.done}/${job.progress.total}`
  : (job.progress && Number.isFinite(job.progress.done) && !job.progress.total ? ` ${job.progress.done}%` : '');

      li.innerHTML = `
      <div>
        <div class="file-name" title="${job.fileName || job.jobId}">${job.fileName || job.jobId}</div>
        <div class="meta ${statusCls}">
          ${job.status === 'done' ? 'Completata' : job.status === 'error' ? ('Errore: ' + (job.message || 'estrazione')) : 'In corso…'}${p}
        </div>
      </div>
      <div class="actions">
        <button class="btn btn-open" type="button" ${job.status === 'done' ? '' : 'disabled'}>Apri scheda</button>
        <button class="btn btn-discard" type="button" title="Rimuovi dalla lista">Chiudi</button>
      </div>
    `;
      // open
      li.querySelector('.btn-open').addEventListener('click', () => {
        if (job.status !== 'done') return;
        const draft = buildDraft(job.payload || {});
        job.draft = draft; setJob(job);
        try {
          document.dispatchEvent(new CustomEvent('gare:draft:open', {
            detail: {
              jobId: job.jobId,
              garaId: job.gara_id || null,
              draft,
              fileName: job.fileName || ''
            }
          }));
        } catch (e) { }
      });
      // close (rimuove dalla lista locale)
      li.querySelector('.btn-discard').addEventListener('click', () => {
        removeJob(job.jobId);
        renderPanel();
        updateDock();
      });
      list.appendChild(li);
    });
  }

  // === MAPPATURA PAYLOAD -> DRAFT (coerente con gare.js::raccogliDraftForm)
  function buildDraft(v) {
    // date -> YYYY-MM-DD
    const toISODate = (s) => {
      if (typeof s !== 'string') return s;
      const m = s.trim().match(/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/);
      if (!m) return /^\d{4}-\d{2}-\d{2}$/.test(s) ? s : s;
      const dd = m[1].padStart(2, '0'), mm = m[2].padStart(2, '0'), yy = (m[3].length === 2 ? ('20' + m[3]) : m[3]);
      return `${yy}-${mm}-${dd}`;
    };
    const d = {};
    if (v.oggetto_appalto) d.titolo = String(v.oggetto_appalto).trim();
    if (v.stazione_appaltante) d.ente = v.stazione_appaltante;
    if (v.data_scadenza_gara_appalto) d.data_scadenza = toISODate(v.data_scadenza_gara_appalto);
    if (v.data_uscita_gara_appalto) d.data_uscita = toISODate(v.data_uscita_gara_appalto);
    if (v.tipologia_di_gara) d.tipo_lavori = v.tipologia_di_gara;
    if (v.tipologia_di_appalto) d.tipologia_appalto = v.tipologia_di_appalto;
    if (v.luogo_provincia_appalto) d.luogo = v.luogo_provincia_appalto;
    if (v.sopralluogo_obbligatorio) {
      const val = String(v.sopralluogo_obbligatorio).toLowerCase();
      d.sopralluogo = (val === 'si' || val === 'sì' || val === 'yes' || val === 'obbligatorio') ? 'Sì' : 'No';
    }
    if (v.link_portale_stazione_appaltante) d.link_portale = v.link_portale_stazione_appaltante;
    return d;
  }

  function setJob(job) {
    const st = getState() || { jobs: [] };
    const i = (st.jobs || []).findIndex(j => j.jobId === job.jobId);
    if (i >= 0) st.jobs[i] = job; else st.jobs.push(job);
    st.lastHeartbeat = Date.now();
    setState(st);
  }
  function removeJob(jobId) {
    const st = getState() || { jobs: [] };
    st.jobs = (st.jobs || []).filter(j => j.jobId !== jobId);
    setState(st);
  }

  // === LANCIO CONCORRENTE: avvia N PDF insieme (via ajax.php/customFetch) ===
  async function startPdfExtractionsConcurrent(files, types) {
    const all = Array.from(files || []);
    const batch = all.slice(0, MAX_BATCH);
    const tasks = [];
    for (const file of batch) {
      const fd = new FormData();
      fd.append('file', file, file.name);
      fd.append('types', JSON.stringify(types || []));
      // passa SEMPRE da ajax.php
      const p = customFetch('gare', 'startExtraction', fd)
        .then(payload => ({ file, payload }))
        .catch(() => ({ file, payload: null }));
      tasks.push(p);
    }

    const results = await Promise.allSettled(tasks);
    if (all.length > MAX_BATCH && typeof window.showToast === 'function') {
      showToast(`Considero solo i primi ${MAX_BATCH} PDF`, 'error');
    }

    const started = [];
    for (const r of results) {
      if (r.status !== 'fulfilled') continue;
      const { file, payload } = r.value || {};
      if (payload && payload.success && payload.job_id) {
        const jobId = payload.job_id;
        const fileName = file?.name || '';
        window.GareDock.addJob({ jobId, fileName });
        started.push({ job_id: jobId, file_name: fileName });
      }
    }

    if (!started.length) return { success: false, message: 'Nessun job avviato' };
    return { success: true, count: started.length, jobs: started };
  }

  function schedulePoll(delayMs) {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
    pollTimer = setTimeout(async () => {
      window.__GARE_DOCK_POLL_TIMER = null;
      pollTimer = null;
      await pollTick();
    }, Math.max(0, delayMs));
    window.__GARE_DOCK_POLL_TIMER = pollTimer;
  }

  function startPolling() {
    if (pollTimer) return;
    idleCycles = 0;
    window.__GARE_DOCK_IDLE_CYCLES = idleCycles;
    schedulePoll(0);
  }

  function stopPolling() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    window.__GARE_DOCK_POLL_TIMER = null;
    idleCycles = 0;
    window.__GARE_DOCK_IDLE_CYCLES = idleCycles;
  }

  async function pollTick() {
    const st = getState();
    if (!st || !Array.isArray(st.jobs) || st.jobs.length === 0) { updateDock(); return; }

    const active = st.jobs.filter(j => j.status === 'in_progress');
    if (active.length === 0) { updateDock(); return; }

    const ids = active.map(j => j.jobId);
    const signature = (status, progress) => {
      const done = progress && Number.isFinite(progress.done) ? progress.done : 'na';
      const total = progress && Number.isFinite(progress.total) ? progress.total : 'na';
      return `${status || ''}::${done}/${total}`;
    };
    const beforeSig = {};
    active.forEach(j => { beforeSig[j.jobId] = signature(j.status, j.progress); });
    let somethingChanged = false;

    try {
      const payload = await customFetch('gare', 'getExtractionStatus', { job_ids: ids, __bg: 1 });
      const results = Array.isArray(payload?.results) ? payload.results : [];

      results.forEach((res) => {
        const jid = String(res.job_id || '');
        if (!jid) return;
        const st2 = getState() || { jobs: [] };
        const job = (st2.jobs || []).find(x => x.jobId === jid);
        if (!job) return;

        const status = (res.status || '').toLowerCase();
        const progress = res.progress || null;
        const prevSignature = beforeSig[jid];
        let nextSignature = prevSignature;

        if (status === 'processing' || status === 'in_progress' || status === 'queued') {
          job.status = 'in_progress';
          job.progress = progress;
          if (res.pdf_name) job.fileName = res.pdf_name;
          setJob(job);
          nextSignature = signature(job.status, job.progress);
        } else if (status === 'completed' || status === 'done') {
          removeJob(job.jobId);
          try {
            document.dispatchEvent(new CustomEvent('gare:estrazione:completed', { detail: { jobId: job.jobId } }));
          } catch { }
          if (typeof window.playGareExtractionComplete === 'function') window.playGareExtractionComplete();
          nextSignature = 'completed::100/100';
        } else if (status === 'error' || status === 'failed') {
          job.status = 'error';
          job.message = res.error || res.detail || 'Errore estrazione';
          job.progress = progress;
          if (res.pdf_name) job.fileName = res.pdf_name;
          setJob(job);
          nextSignature = signature(job.status, job.progress);
        } else {
          job.status = status || job.status;
          job.progress = progress || job.progress;
          if (res.pdf_name) job.fileName = res.pdf_name;
          setJob(job);
          nextSignature = signature(job.status, job.progress);
        }

        if (nextSignature !== prevSignature) {
          somethingChanged = true;
        }
      });

    } catch (e) {
      // opzionale: segna errore tutti gli active
      const stErr = getState();
      if (stErr && Array.isArray(stErr.jobs)) {
        stErr.jobs = stErr.jobs.map(j => (j.status === 'in_progress' ? { ...j, status: 'error', message: 'Errore rete' } : j));
        setState(stErr);
      }
    } finally {
      updateDock();
      renderPanel();
      // stop se non restano job attivi
      const st2 = getState();
      const stillActive = !!(st2 && Array.isArray(st2.jobs) && st2.jobs.some(j => j.status === 'in_progress'));
      if (!stillActive) {
        stopPolling();
      } else {
        if (somethingChanged) {
          idleCycles = 0;
        } else {
          idleCycles = Math.min(idleCycles + 1, 5);
        }
        window.__GARE_DOCK_IDLE_CYCLES = idleCycles;
        const delay = Math.min(MAX_POLL_MS, MIN_POLL_MS * (idleCycles + 1));
        schedulePoll(delay);
      }
    }
  }

  // API pubblica
  window.GareDock = {
    // collega un job a un id gara (opzionale, per UI)
    linkRow(jobId, garaId) {
      const st = getState() || { jobs: [] };
      const j = (st.jobs || []).find(x => x.jobId === jobId);
      if (!j) return;
      j.gara_id = garaId || null;
      setJob(j);
      renderPanel();
    },
    addJob({ jobId, fileName }) {
      ensureDock();
      const st = getState() || { jobs: [] };
      if ((st.jobs || []).length === 0) st.startedAt = Date.now();
      st.jobs.push({ jobId, fileName, status: 'in_progress', progress: null, payload: null, draft: null, createdAt: Date.now() });
      setState(st);
      updateDock(); renderPanel(); startPolling();
    },

    async startConcurrent(files, types) {
      ensureDock();
      const res = await startPdfExtractionsConcurrent(files, types);
      return res;
    },

    hasJob() {
      const st = getState();
      return !!(st && Array.isArray(st.jobs) && st.jobs.length > 0);
    },
    getDraft(jobId) {
      const st = getState() || {};
      const j = (st.jobs || []).find(x => x.jobId === jobId);
      return j?.draft || null;
    },
    clear() { stopPolling(); setState(null); updateDock(); renderPanel(); }
  };
  // bootstrap
  document.addEventListener('DOMContentLoaded', () => { ensureDock(); updateDock(); });
})();

// === SUONO COMPLETAMENTO (sblocco AudioContext + beep) ===
(function () {
  // 1) crea/riusa il context globale
  function getCtx() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return null;
      window._gareAudioCtx = window._gareAudioCtx || new AudioCtx();
      return window._gareAudioCtx;
    } catch (e) {
      return null;
    }
  }

  // 2) sblocca su primo gesto utente
  let _audioUnlocked = false;
  function _unlockAudio() {
    const ctx = getCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended' && ctx.resume) ctx.resume();
    _audioUnlocked = (ctx.state === 'running');
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(evt => {
    window.addEventListener(evt, _unlockAudio, { once: true, capture: true });
  });

  // 3) beep con debounce per evitare sovrapposizioni
  let _lastBeepAt = 0;
  function playGareExtractionComplete() {
    try {
      const nowMs = Date.now();
      if (nowMs - _lastBeepAt < 400) return; // evita doppie chiamate ravvicinate
      _lastBeepAt = nowMs;

      const ctx = getCtx();
      if (!ctx) return;
      if (ctx.state === 'suspended' && ctx.resume) ctx.resume();

      const now = ctx.currentTime, base = 660, glideUp = 1.03, dur = 0.55;

      const osc1 = ctx.createOscillator(), osc2 = ctx.createOscillator();
      osc1.type = 'sine'; osc2.type = 'sine';

      osc1.frequency.setValueAtTime(base, now);
      osc1.frequency.exponentialRampToValueAtTime(base * glideUp, now + 0.06);

      const third = base * Math.pow(2, 4 / 12);
      osc2.frequency.setValueAtTime(third, now);
      osc2.frequency.exponentialRampToValueAtTime(third * glideUp, now + 0.06);

      const master = ctx.createGain();
      master.gain.setValueAtTime(0.0001, now);
      master.gain.linearRampToValueAtTime(0.18, now + 0.015);
      master.gain.exponentialRampToValueAtTime(0.0001, now + dur);

      const delay = ctx.createDelay(0.8);
      delay.delayTime.setValueAtTime(0.09, now);
      const fb = ctx.createGain(); fb.gain.setValueAtTime(0.16, now);
      const tone = ctx.createBiquadFilter(); tone.type = 'lowpass'; tone.frequency.setValueAtTime(2000, now);
      const comp = ctx.createDynamicsCompressor();
      comp.threshold.setValueAtTime(-32, now); comp.knee.setValueAtTime(24, now); comp.ratio.setValueAtTime(3, now);
      comp.attack.setValueAtTime(0.003, now); comp.release.setValueAtTime(0.12, now);

      osc1.connect(master); osc2.connect(master);
      master.connect(comp); comp.connect(ctx.destination); comp.connect(delay);
      delay.connect(tone); tone.connect(ctx.destination); tone.connect(fb); fb.connect(delay);

      osc1.start(now); osc2.start(now);
      osc1.stop(now + dur + 0.02); osc2.stop(now + dur + 0.02);

      setTimeout(() => {
        try { master.disconnect(); delay.disconnect(); tone.disconnect(); fb.disconnect(); comp.disconnect(); } catch { }
      }, 650);
    } catch { }
  }

  window.playGareExtractionComplete = playGareExtractionComplete;
})();
