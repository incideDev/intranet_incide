# Page Editor – Audit e Flusso Completo

Documento di riferimento per comprendere il sistema **page_editor** dell’intranet: creazione moduli, menu, form, salvataggio e visualizzazione.

---

## 1. Panoramica

Il **page_editor** è lo strumento per creare e configurare **moduli dinamici** (form) senza scrivere codice. Ogni modulo:

- Ha una **struttura personalizzabile** (campi, schede/tab)
- Ha **campi fissi** sempre presenti (titolo, descrizione, deadline, priorità, assegnato_a)
- Viene esposto nel **menu laterale** nella sezione scelta
- Mostra una **tabella** di record compilati
- Permette di **compilare** nuovi record tramite un form a pagina intera
- Dopo il salvataggio **reindirizza** alla visualizzazione del record compilato

---

## 2. Flusso Utente (End-to-End)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 1. CREAZIONE MODULO (page_editor)                                           │
│    views/gestione_intranet/page_editor.php                                   │
│    - Creazione/modifica struttura (campi, schede, stati)                     │
│    - Salvataggio → ensureForm + ensureFixedFields + saveFormStructure        │
│    - Voce menu creata/aggiornata (menu_custom.upsert)                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 2. MENU LATERALE                                                            │
│    core/functions.php (buildMenu)                                            │
│    - Link: index.php?section=X&page=gestione_segnalazioni&form_name=NOME     │
│    - Permesso: page_editor_form_view:<form_id>                                │
│    - Normalizzazione: link a form/view_form/form_viewer → gestione_segnalazioni│
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 3. PAGINA LISTING (tabella)                                                  │
│    views/includes/form/gestione_segnalazioni.php                             │
│    - Tabella con record compilati (Protocollo, Oggetto, Creato da, Stato…)   │
│    - Dati da: page_editor.getFilledForms                                     │
│    - Viste: tabella, kanban, calendario, gantt                               │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        │  Click su "+" (function-bar)
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 4. FORM COMPILAZIONE (view_form)                                             │
│    views/includes/form/view_form.php                                         │
│    - Pagina intera (.pagina-foglio), NON modale                              │
│    - URL: index.php?section=X&page=view_form&form_name=NOME                  │
│    - Campi fissi + campi dinamici organizzati in schede/tab                   │
│    - Barra azioni in basso (BottomBar) con Annulla / Salva                   │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        │  Click "Salva" → submitScheda
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ 5. VISUALIZZAZIONE RECORD (form_viewer)                                      │
│    views/includes/form/form_viewer.php                                       │
│    - Redirect: index.php?section=X&page=form_viewer&form_name=NOME&id=ID      │
│    - Visualizzazione read-only del modulo compilato                           │
│    - Struttura sempre readonly per tutti                                     │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Componenti Chiave

### 3.1 Page Editor (creazione modulo)

| File | Ruolo |
|------|-------|
| `views/gestione_intranet/page_editor.php` | UI editor (struttura, proprietà, menu) |
| `assets/js/gestione_intranet/page_editor.js` | Logica JS (salvataggio, anteprima, menu) |
| `services/PageEditorService.php` | Backend (ensureForm, saveFormStructure, ensureFixedFields) |

**Flusso salvataggio:**
1. `ensureForm(form_name)` → crea/aggiorna record in `forms` e tabella `mod_*`
2. `ensureFixedFields(form_name)` → crea colonne e campi fissi in DB
3. `saveFormStructure()` → salva campi dinamici in `form_fields`
4. `menu_custom.upsert` → crea/aggiorna voce nel menu

### 3.2 Function-Bar e bottone "+"

| File | Ruolo |
|------|-------|
| `assets/js/function-bar.js` | Gestione bottone "+" per pagina corrente |

Per `gestione_segnalazioni` (con `form_name` in URL):

```javascript
// function-bar.js ~linea 477
addButton.addEventListener("click", () => {
    window.location.href = `index.php?section=collaborazione&page=view_form&form_name=${encodedName}`;
});
```

Il "+" **naviga** a `view_form`, non apre un modale.

### 3.3 view_form (form da compilare)

| File | Ruolo |
|------|-------|
| `views/includes/form/view_form.php` | Pagina form compilabile |
| Layout | `.pagina-foglio` (contenitore principale) |

- **Pagina intera** (non modale)
- Campi organizzati in **schede/tab**
- **BottomBar** per azioni (Annulla, Salva)
- Dopo submit → redirect a `form_viewer` con `id` del record

### 3.4 form_viewer (visualizzazione record)

| File | Ruolo |
|------|-------|
| `views/includes/form/form_viewer.php` | Visualizzazione read-only del record |

- Struttura sempre readonly
- Possibili azioni su altre schede (submit, workflow) tramite `submitScheda`

### 3.5 Bottom Bar (barra azioni)

| File | Ruolo |
|------|-------|
| `assets/js/components/bottom_bar.js` | Componente riutilizzabile |
| `assets/css/bottom-bar.css` | Stili |

**API:**
- `BottomBar.setConfig({ actions: [{ id, label, className? }], statusText? })`
- `BottomBar.updateAction(actionId, { label?, disabled?, hidden? })`
- Evento `bottomBar:action` su `document` con `detail.actionId`

Barra fissa in basso, non alta, solo bottoni. Usata in `view_form`, `form_viewer`, `page_editor`.

---

## 4. Campi Fissi

Creati automaticamente alla creazione del modulo (in `ensureForm` e `ensureFixedFields`):

| Campo | Tipo | Tabella | form_fields |
|-------|------|---------|-------------|
| `titolo` | text (VARCHAR 255) | mod_* | is_fixed=1 |
| `descrizione` | textarea (TEXT) | mod_* | is_fixed=1 |
| `deadline` | date | mod_* | is_fixed=1 |
| `priority` | select (Bassa/Media/Alta) | mod_* | is_fixed=1 |
| `assegnato_a` | dbselect (personale) | mod_* | is_fixed=1 |

**Quando vengono creati:**
- `ensureForm` → crea la tabella `mod_*` con colonne base
- `ensureFixedFields` → aggiunge colonne mancanti e record in `form_fields` con `is_fixed=1`
- Chiamato in `on_attach` del modulo `gestione_richiesta` e in `before_save_structure`

**Nota:** L’utente non li aggiunge manualmente; sono sempre presenti.

---

## 5. Database

### Tabelle principali

| Tabella | Ruolo |
|---------|-------|
| `forms` | Metadati modulo (name, table_name, responsabile, color, tabs_config, …) |
| `form_fields` | Campi per form (field_name, field_type, tab_label, is_fixed, …) |
| `mod_<nome>` | Dati dei record compilati (id, titolo, descrizione, deadline, status_id, …) |
| `menu_custom` | Voci di menu (section, parent_title, title, link) |

### Esempio creazione tabella dati

```sql
CREATE TABLE mod_nome_modulo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deadline DATE NULL,
    titolo VARCHAR(255) NOT NULL,
    descrizione TEXT NOT NULL,
    priority VARCHAR(255) DEFAULT 'Media',
    assegnato_a VARCHAR(255) NULL,
    status_id INT DEFAULT 1,
    ...
);
```

---

## 6. Routing (index.php)

| page | View | Note |
|------|------|------|
| `page_editor` | `views/gestione_intranet/page_editor.php` | Editor moduli |
| `gestione_segnalazioni` | `views/includes/form/gestione_segnalazioni.php` | Listing tabella/kanban/calendario |
| `view_form` | `views/includes/form/view_form.php` | Form compilazione |
| `form_viewer` | `views/includes/form/form_viewer.php` | Visualizzazione record |
| `form_listing` / `generic_form_listing` | `views/includes/form/generic_form_listing.php` | Listing generico (alternativo) |

---

## 7. Permessi

- **Creazione/modifica moduli:** `view_moduli`
- **Accesso a un form specifico:** `page_editor_form_view:<form_id>`
- Controllo in `view_form.php`, `form_viewer.php`, `enforceFormVisibilityOrRedirect()`

---

## 8. API Page Editor (service_router / ajax)

| Action | Ruolo |
|--------|-------|
| `ensureForm` | Crea/aggiorna form e tabella dati |
| `getForm` | Struttura form + campi + tabs_config |
| `saveFormStructure` | Salva campi dinamici |
| `getFilledForms` | Elenco record compilati (per tabella) |
| `submitScheda` | Salvataggio record (creazione/aggiornamento) |
| `getFormStates` | Stati personalizzati (Aperta, In corso, Chiusa, …) |
| `getSchedeStatus` | Stato submit per scheda |
| `getMenuPlacementForForm` | Sezione/menu del form |

---

## 9. Pagina “foglio” (.pagina-foglio)

Classe CSS usata per il contenuto principale di form e viewer:

- `view_form.php` → `<div class="pagina-foglio">`
- `form_viewer.php` → `<div class="pagina-foglio">`
- `page_editor.php` → `<div class="pagina-foglio editor-mode">`

Non è un modale: è il contenitore del form a pagina intera.

---

## 10. Riepilogo per l’agente

1. **page_editor** crea moduli e li collega al menu (`gestione_segnalazioni`).
2. Dal menu si arriva alla **tabella** dei record.
3. Il **"+"** nella function-bar apre **view_form** (pagina intera).
4. Il form è in `.pagina-foglio` con **BottomBar** (Annulla, Salva).
5. Dopo il salvataggio si va a **form_viewer** con l’`id` del record.
6. I **campi fissi** (titolo, descrizione, deadline, priority, assegnato_a) sono creati in automatico nel DB.
7. La **BottomBar** è un componente riutilizzabile (`BottomBar.setConfig`) per azioni in basso.

---

*Documento generato per supportare l’integrazione e la comprensione del sistema page_editor.*
