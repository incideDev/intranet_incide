# Table Cell Typography Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migliorare la leggibilità delle tabelle aggiungendo due classi utility CSS (`.cell-title`, `.cell-entity`) e applicandole nelle tabelle gare.

**Architecture:** Classi utility scoped sotto `.table--modern` in `tables.css`. Applicazione manuale nei `<td>` generati da `gare_list.js`. Nessuna modifica strutturale — solo peso e colore del testo.

**Tech Stack:** CSS (tables.css), Vanilla JS (gare_list.js)

---

## File coinvolti

- **Modify:** `assets/css/tables.css` — aggiungere sezione 8.11 con `.cell-title` e `.cell-entity`
- **Modify:** `assets/js/gare_list.js` — applicare classi nelle `<td>` di titolo ed ente in `renderTable()`

---

### Task 1: Aggiungere classi CSS in tables.css

**Files:**
- Modify: `assets/css/tables.css` (dopo la sezione 8.10, intorno alla riga 1056)

- [ ] **Step 1: Leggere la fine della sezione 8 in tables.css**

  Verificare dove finisce la sezione 8.10 (`8.10 EMPTY/LOADING STATE`) per capire esattamente dove inserire il nuovo blocco.

- [ ] **Step 2: Aggiungere sezione 8.11 dopo la sezione 8.10**

  Inserire il seguente CSS dopo la sezione 8.10 e prima di qualsiasi altra sezione successiva:

  ```css
  /* ============================================
     8.11 TIPOGRAFIA CELLE SEMANTICHE - SOLO .table--modern
     .cell-title  → colonna titolo/oggetto principale (colore quasi-nero + bold)
     .cell-entity → colonna ente/soggetto importante (grigio scuro + semi-bold)
     ============================================ */

  .table--modern td.cell-title {
      color: #1a202c;
      font-weight: 600;
  }

  .table--modern td.cell-entity {
      color: #374151;
      font-weight: 600;
  }
  ```

- [ ] **Step 3: Verificare visivamente**

  Aprire qualsiasi tabella con `table--modern` nel browser e confermare che senza le classi applicate nulla cambia (le regole esistono ma non impattano nulla ancora).

---

### Task 2: Applicare le classi in gare_list.js

**Files:**
- Modify: `assets/js/gare_list.js` — funzione `renderTable()` (~riga 930)

Le tabelle gare hanno due sezioni di rendering distinte dentro `renderTable()`:
- **Elenco gare / Archivio** (~riga 998-999): celle `ente` e `titolo` come `<td>` plain
- **Estrazione bandi** (~riga 1065-1071): titolo dentro `cell-stack`, ente come `<td>` plain

- [ ] **Step 1: Applicare `.cell-title` e `.cell-entity` nel ramo elenco gare (~riga 998-999)**

  Trovare questo blocco (circa riga 998):
  ```javascript
  <td>${window.escapeHtml ? window.escapeHtml(row.ente || "—") : row.ente || "—"}</td>
  <td>${window.escapeHtml ? window.escapeHtml(row.titolo || "—") : row.titolo || "—"}</td>
  ```

  Sostituire con:
  ```javascript
  <td class="cell-entity">${window.escapeHtml ? window.escapeHtml(row.ente || "—") : row.ente || "—"}</td>
  <td class="cell-title">${window.escapeHtml ? window.escapeHtml(row.titolo || "—") : row.titolo || "—"}</td>
  ```

- [ ] **Step 2: Applicare `.cell-title` nel ramo estrazione bandi (~riga 1053-1071)**

  La cella titolo usa già `cell-stack` con `cell-primary`/`cell-secondary`. Aggiungere `cell-title` sulla `<td>` contenitore.

  Trovare il `<td>` che contiene `.cell-stack` per il titolo (circa riga 1063) e aggiungere la classe:
  ```javascript
  <td class="cell-title">
    <div class="cell-stack">
      <span class="cell-primary">${esc(titolo)}</span>
      ${fileName && fileName !== titolo ? `<span class="cell-secondary">${esc(fileName)}</span>` : ''}
    </div>
  </td>
  ```

  Trovare la cella ente (~riga 1071):
  ```javascript
  <td>${esc(row.ente || "—")}</td>
  ```
  Sostituire con:
  ```javascript
  <td class="cell-entity">${esc(row.ente || "—")}</td>
  ```

- [ ] **Step 3: Verificare nel browser**

  1. Aprire `elenco_gare` — verificare che titolo sia più scuro/bold, ente semi-bold
  2. Aprire `estrazione_bandi` — verificare stesso effetto su titolo bando ed ente
  3. Aprire `archivio_gare` — stesso controllo (usa stessa funzione renderTable)
  4. Verificare che le altre celle (settore, tipologia, luogo, date) restino invariate

- [ ] **Step 4: Commit**

  ```bash
  git add assets/css/tables.css assets/js/gare_list.js
  git commit -m "feat(ui): add cell-title and cell-entity typography classes to gare tables"
  ```
