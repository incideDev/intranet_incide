<div class="main-container">
<div class="card">
  <h3>Menu personalizzati</h3>
  <div class="row">
    <div class="col">
      <label>Sezione</label>
      <select id="mc-section"></select>
    </div>
    <div class="col">
      <label>Menu padre</label>
      <select id="mc-parent"></select>
    </div>
  </div>

  <div class="row">
    <div class="col">
      <label>Titolo voce</label>
      <input id="mc-title" type="text" />
    </div>
    <div class="col">
      <label>Link</label>
      <input id="mc-link" type="text" placeholder="index.php?section=...&page=..." />
    </div>
  </div>

  <div class="row">
    <div class="col">
      <label>Attivo</label>
      <input id="mc-attivo" type="checkbox" checked />
    </div>
    <div class="col">
      <label>Ordinamento</label>
      <input id="mc-ord" type="number" value="100" />
    </div>
  </div>

    <button id="mc-save" class="button" data-tooltip="salva o aggiorna la voce">Salva/aggiorna</button>
</div>

<hr>

<div class="card">
  <h3>Voci esistenti</h3>
  <table id="mc-table">
    <thead>
      <tr><th>ID</th><th>Sezione</th><th>Padre</th><th>Titolo</th><th>Link</th><th>Attivo</th><th>Ord.</th><th></th></tr>
    </thead>
    <tbody></tbody>
  </table>
</div>
</div>

<script>
(function(){

  // attendo che customfetch sia disponibile prima di partire
  function waitForCustomFetch(max_ms = 5000) {
    return new Promise((resolve, reject) => {
      const started = Date.now();

      (function tick() {
        // shim: se per caso esiste la variante minuscola, creiamo l'alias
        if (typeof window.customFetch !== 'function' && typeof window.customfetch === 'function') {
          window.customFetch = window.customfetch;
        }
        if (typeof window.customFetch === 'function') {
          return resolve();
        }
        if (Date.now() - started > max_ms) {
          return reject(new Error('customFetch non disponibile (timeout)'));
        }
        setTimeout(tick, 50);
      })();
    });
  }

  const $sec   = document.getElementById('mc-section');
  const $par   = document.getElementById('mc-parent');
  const $title = document.getElementById('mc-title');
  const $link  = document.getElementById('mc-link');
  const $attivo= document.getElementById('mc-attivo');
  const $ord   = document.getElementById('mc-ord');
  const $save  = document.getElementById('mc-save');
  const $tbody = document.querySelector('#mc-table tbody');

  let sectionsmap = {};
  let editingid = null;

  function loadsections() {
    return window.customFetch('menu_custom', 'getSectionsAndParents').then(res => {
      if (!res.success) throw new Error(res.message || 'errore caricamento sezioni');
      sectionsmap = res.data || {};
      $sec.innerHTML = '';
      Object.keys(sectionsmap).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s;
        opt.textContent = s;
        $sec.appendChild(opt);
      });
      updateparents();
    });
  }

  function updateparents() {
    const s = $sec.value;
    $par.innerHTML = '';
    (sectionsmap[s] || []).forEach(p => {
      const opt = document.createElement('option');
      opt.value = p;
      opt.textContent = p;
      $par.appendChild(opt);
    });
  }

  function loadtable() {
    return window.customFetch('menu_custom', 'list').then(res => {
      if (!res.success) throw new Error(res.message || 'errore lista');
      $tbody.innerHTML = '';
      (res.data || []).forEach(r => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${r.id}</td>
          <td>${r.section}</td>
          <td>${r.parent_title}</td>
          <td>${r.title}</td>
          <td>${r.link}</td>
          <td>${r.attivo == 1 ? '✓' : '✗'}</td>
          <td><input type="number" value="${r.ordinamento}" data-id="${r.id}" class="mc-ord-row"></td>
          <td>
            <button data-id="${r.id}" class="mc-edit" data-tooltip="modifica">modifica</button>
            <button data-id="${r.id}" class="mc-del"  data-tooltip="elimina">elimina</button>
          </td>`;
        $tbody.appendChild(tr);
      });
    });
  }

  $sec.addEventListener('change', updateparents);

  $save.setAttribute('data-tooltip', 'salva o aggiorna la voce');
  $save.addEventListener('click', () => {
    const payload = {
      id: editingid,
      section: $sec.value,
      parent_title: $par.value,
      title: ($title.value || '').trim(),
      link:  ($link.value  || '').trim(),
      attivo: $attivo.checked ? 1 : 0,
      ordinamento: parseInt($ord.value || '100', 10)
    };
    window.customFetch('menu_custom', 'upsert', payload).then(res => {
      if (!res.success) { alert(res.message || 'errore salvataggio'); return; }
      editingid = null;
      $title.value = '';
      $link.value  = '';
      $attivo.checked = true;
      $ord.value = '100';
      loadtable().then(() => window.refreshsidebarmenu && window.refreshsidebarmenu($sec.value));
    });
  });

  $tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    if (btn.classList.contains('mc-edit')) {
      const tr = btn.closest('tr'); if (!tr) return;
      editingid = parseInt(id, 10);
      $sec.value = tr.children[1].textContent; updateparents();
      $par.value = tr.children[2].textContent;
      $title.value = tr.children[3].textContent;
      $link.value  = tr.children[4].textContent;
      $attivo.checked = tr.children[5].textContent.trim() === '✓';
      $ord.value = tr.querySelector('.mc-ord-row').value;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else if (btn.classList.contains('mc-del')) {
      if (!confirm('eliminare la voce?')) return;
      window.customFetch('menu_custom', 'delete', { id }).then(res => {
        if (!res.success) { alert(res.message || 'errore eliminazione'); return; }
        loadtable().then(() => window.refreshsidebarmenu && window.refreshsidebarmenu($sec.value));
      });
    }
  });

  $tbody.addEventListener('change', (e) => {
    const input = e.target.closest('.mc-ord-row');
    if (!input) return;
    const rows = [...document.querySelectorAll('.mc-ord-row')].map(i => ({
      id: parseInt(i.dataset.id, 10),
      ordinamento: parseInt(i.value || '100', 10)
    }));
    window.customFetch('menu_custom', 'reorder', { rows }).then(res => {
      if (!res.success) alert(res.message || 'errore riordino');
      else window.refreshsidebarmenu && window.refreshsidebarmenu($sec.value);
    });
  });

  // init sicuro: parte solo quando customfetch è pronto
  document.addEventListener('DOMContentLoaded', function() {
    waitForCustomFetch()
      .then(() => loadsections().then(loadtable))
      .catch(err => {
        console.error(err);
        alert('impossibile inizializzare: api ajax non disponibile.');
      });
  });

})();
</script>

