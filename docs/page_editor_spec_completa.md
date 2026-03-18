# Page Editor – Specifica Tecnica Completa per Riproduzione

Documento maniacalmente dettagliato per riprodurre il sistema **page_editor** in un altro progetto. Include struttura UI, campi fissi, anteprima, trascinamento, schede, notifiche, dipendenze e schema DB.

---

## Indice

1. [Panoramica architetturale](#1-panoramica-architetturale)
2. [Struttura file e dipendenze](#2-struttura-file-e-dipendenze)
3. [Schema database](#3-schema-database)
4. [Interfaccia utente (UI)](#4-interfaccia-utente-ui)
5. [Campi fissi](#5-campi-fissi)
6. [Palette campi e tipi supportati](#6-palette-campi-e-tipi-supportati)
7. [Sistema Drag & Drop](#7-sistema-drag--drop)
8. [Schede (Tab)](#8-schede-tab)
9. [Anteprima](#9-anteprima)
10. [Stati personalizzati](#10-stati-personalizzati)
11. [Notifiche (email, in-app)](#11-notifiche-email-in-app)
12. [Datasources](#12-datasources)
13. [API e service router](#13-api-e-service-router)
14. [Flusso salvataggio e submit](#14-flusso-salvataggio-e-submit)

---

## 1. Panoramica architetturale

Il page_editor è un modulo WYSIWYG per creare/modificare **form dinamici** senza scrivere codice. Ogni form:

- Ha una **tabella dati** dedicata (`mod_<nome>`)
- Ha **campi fissi** sempre presenti (titolo, descrizione, deadline, priorità, assegnato_a)
- Supporta **campi dinamici** aggiunti dall’utente (testo, select, file, dbselect, ecc.)
- È organizzato in **schede/tab** configurabili
- Ha **stati personalizzati** per Kanban (Aperta, In corso, Chiusa, …)
- È collegato al **menu** laterale
- Può inviare **notifiche** (in-app, email) su eventi (submit, cambio stato, assegnazione)

**Flusso principale:**
1. Creazione/modifica modulo in page_editor
2. Voce menu → pagina listing (tabella)
3. Click "+" → view_form (compilazione)
4. Salvataggio → redirect a form_viewer

---

## 2. Struttura file e dipendenze

### File principali

| File | Ruolo |
|------|-------|
| `views/gestione_intranet/page_editor.php` | UI editor (HTML, toolbar, wizard, preview) |
| `assets/js/gestione_intranet/page_editor.js` | Logica JS (~7.7k righe) |
| `assets/css/form.css` | Stili form, pagina-foglio, bottom-bar |
| `services/PageEditorService.php` | Backend (ensureForm, saveFormStructure, ensureFixedFields, submitScheda, getForm, …) |

### Dipendenze JS

- `customFetch` (globale) – chiamate AJAX
- `showToast` – messaggi toast
- `toggleModal` – apertura/chiusura modale
- `window.escapeHtml` – escape HTML
- `datasource` service – per dbselect

### Dipendenze CSS

- `form.css` – `.pagina-foglio`, `.form-meta-block`, `.editor-group`, `.pe-slot-marker`, `.bottom-bar`
- `bottom-bar.css` – barra azioni fissa
- `legacy.css` – override per campi fissi (form-meta-block non modificabile)

### File correlati (consumatori)

- `views/includes/form/view_form.php` – form compilazione
- `views/includes/form/form_viewer.php` – visualizzazione record
- `views/includes/form/gestione_segnalazioni.php` – listing tabella
- `views/includes/form/generic_form_listing.php` – listing generico
- `assets/js/form_tabs_common.js` – workflow schede (visibility, edit_roles)
- `assets/js/function-bar.js` – bottone "+" per apertura view_form

---

## 3. Schema database

### Tabella `forms`

```sql
-- Metadati principali
id, name, description, table_name, responsabile, created_by, created_at,
color, protocollo, button_text, struttura_display_label, tabs_config (TEXT/JSON)
```

- `name`: nome form (es. `segnalazione_it`)
- `table_name`: tabella dati (es. `mod_segnalazione_it`)
- `tabs_config`: JSON con configurazione schede (submit_label, visibility_roles, edit_roles, visibility_condition, redirect_after_submit, is_main, isClosureTab, unlock_after_submit_prev)

### Tabella `form_fields`

```sql
id, form_id, field_name, field_type, field_placeholder, field_options (TEXT/JSON),
required, is_fixed, tab_label, tab_order, sort_order, parent_section_uid,
field_label, colspan
```

- `is_fixed = 1`: per campi fissi (titolo, descrizione, deadline, priority, assegnato_a)
- `tab_label`: nome scheda (es. "Struttura", "Dettagli", "Esito")
- `parent_section_uid`: per campi dentro sezioni (fieldset)

### Tabella dati `mod_<nome>`

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
    completed_at DATETIME NULL,
    codice_segnalazione VARCHAR(255) DEFAULT NULL,
    esito_stato VARCHAR(50) DEFAULT NULL,
    esito_note TEXT DEFAULT NULL,
    esito_data_prevista DATE DEFAULT NULL,
    esito_data DATETIME DEFAULT NULL
);
```

Colonne aggiunte dinamicamente per campi custom: `addColumnIfMissing` in PageEditorService.

### Tabella `form_states`

```sql
CREATE TABLE IF NOT EXISTS form_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#95A5A6',
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    base_group TINYINT(1) NULL,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (form_id)
);
```

### Tabella `form_schede_status` (opzionale)

```sql
CREATE TABLE IF NOT EXISTS form_schede_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    record_id INT NOT NULL,
    scheda_key VARCHAR(100) NOT NULL,
    status ENUM('not_started', 'draft', 'submitted') NOT NULL DEFAULT 'not_started',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    UNIQUE KEY uk_form_record_scheda (form_id, record_id, scheda_key)
);
```

### Tabella `notification_rules`

```sql
CREATE TABLE IF NOT EXISTS notification_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    events TEXT,
    channels TEXT,
    recipients TEXT,
    messages TEXT,
    created_at DATETIME,
    updated_at DATETIME,
    UNIQUE KEY uk_form_name (form_name)
);
```

- `events`: JSON array `["on_submit", "on_status_change", "on_assignment_change"]`
- `channels`: JSON `{"in_app": true, "email": true}`
- `recipients`: JSON `{"responsabile": true, "assegnatario": true, "autore": true, "custom_email_value": "..."}`
- `messages`: JSON `{"in_app_message": "...", "email_subject": "...", "email_body": "..."}`

### Tabella `form_values` (EAV per campi non fisici)

```sql
-- Per campi custom senza colonna fisica
form_id, record_id, field_name, field_value TEXT
```

### Tabella `menu_custom`

```sql
id, section, parent_title, title, link, attivo, ordinamento
```

Link tipico: `index.php?section=collaborazione&page=gestione_segnalazioni&form_name=NOME`

### Tabella `notification_logs` (opzionale, per audit)

```sql
form_name, event_type, recipient, channel, subject, body, status, error_message, created_at
```

Usata da `NotificationService::logNotification` per tracciare invii.

---

## 4. Interfaccia utente (UI)

### Layout

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ [Titolo: crea nuova pagina / modifica pagina: NOME]                         │
├─────────────────────────────────────────────────────────────────────────────┤
│ [Tab Bar] Struttura | Dettagli | Esito | ...                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│ [Form Meta Block] Titolo, Descrizione, Date, Assigned, Priorità, Stato      │
│ (disabled, solo per anteprima)                                               │
├─────────────────────────────────────────────────────────────────────────────┤
│ [Editor Stage]                                                               │
│ [form-fields-preview] - grid con campi trascinabili                          │
│ - .pe-grid (contenitore principale)                                          │
│ - .editor-group (ogni campo)                                                 │
│ - .editor-section (sezione/fieldset)                                         │
│ - .pe-slot-marker (segnaposto per drop)                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│ [pe-bottom-bar] Status | [Anteprima] [Salva]                                 │
└─────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────┐
│ TOOLBAR LATERALE DESTRA (rtb)    │
│ [Proprietà] [Campi] [Stati]      │
│ [Schede] [Notifiche]             │
│ ──────────────────────────────── │
│ Pannello espandibile verso sin.  │
│ - Palette campi (drag)            │
│ - Wizard proprietà               │
│ - Stati Kanban                    │
│ - Config schede                  │
│ - Config notifiche               │
└──────────────────────────────────┘
```

### Tab toolbar (rtb-tabs)

1. **Proprietà (wizard)** – Nome, descrizione, responsabili, sezione, menu padre, colore, voce attiva
2. **Campi** – Palette campi trascinabili (text, textarea, select, checkbox, radio, file, date, dbselect, sezione)
3. **Stati** – Stati Kanban (nome, colore, ordine)
4. **Schede** – Proprietà scheda attiva: isMain, isClosureTab, unlock_after_submit_prev, visibility_roles, edit_roles, visibility_condition, redirect_after_submit
5. **Notifiche** – Eventi, canali, destinatari, messaggi

### Form Meta Block (sempre visibile, disabled)

- Titolo (input text)
- Descrizione (textarea)
- Data apertura (readonly)
- Data scadenza (input date)
- Creato da (readonly)
- Assegnato a (readonly, con avatar)
- Priorità (select: Bassa, Media, Alta)
- Stato (select: Aperta, In corso, Chiusa)

### Bottom bar (pe-bottom-bar)

- `#pe-status-text` – testo stato
- `#pe-preview-btn` – Anteprima
- `#pe-unified-save` – Salva

**Nota:** Il page_editor usa una barra inline nel proprio HTML. Per view_form e form_viewer si usa il componente riutilizzabile `BottomBar` (`assets/js/components/bottom_bar.js`):
- `BottomBar.setConfig({ actions: [{ id, label, className? }], statusText? })`
- Evento `bottomBar:action` su document con `detail.actionId`

---

## 5. Campi fissi

**Campi sempre presenti**, creati automaticamente in `ensureFixedFields`:

| Campo | Tipo | DDL | Form | Opzioni |
|-------|------|-----|------|---------|
| titolo | text | VARCHAR(255) NULL | input | required |
| descrizione | textarea | TEXT NULL | textarea | required |
| deadline | date | DATE NULL | input date | required |
| priority | select | VARCHAR(255) NULL | select | Bassa, Media, Alta |
| assegnato_a | dbselect | INT NULL | select personale | valueCol=user_id, labelCol=Nominativo |

**Quando vengono creati:**
- `ensureForm` crea la tabella con colonne base
- `ensureFixedFields` aggiunge colonne mancanti e record in `form_fields` con `is_fixed=1`
- Chiamato in `on_attach` del modulo `gestione_richiesta` e in `before_save_structure`

**Nota:** L’utente non li aggiunge manualmente; sono sempre presenti e non modificabili nella struttura. Non vengono salvati in `saveFormStructure` (skip esplicito).

---

## 6. Palette campi e tipi supportati

### Palette (field-dashboard)

```html
<div class="field-card" draggable="true" data-type="text">campo testo</div>
<div class="field-card" draggable="true" data-type="textarea">area testo</div>
<div class="field-card" draggable="true" data-type="select">select</div>
<div class="field-card" draggable="true" data-type="checkbox">checkbox</div>
<div class="field-card" draggable="true" data-type="radio">radio</div>
<div class="field-card" draggable="true" data-type="file">file</div>
<div class="field-card" draggable="true" data-type="date">data</div>
<div class="field-card" draggable="true" data-type="dbselect">db select (dal DB)</div>
<div class="field-card" draggable="true" data-type="section">sezione (fieldset)</div>
```

### Vincoli

- **Un solo campo file** per scheda (controllo in `applyFieldTypeChange`)
- **dbselect** richiede datasource (table + valueCol + labelCol) o codice datasource whitelist

### Struttura campo in memoria

```javascript
{
  uid: 'uuid',           // ID univoco
  name: 'nome_campo',
  type: 'text|textarea|select|checkbox|radio|file|date|dbselect|section',
  label: 'Etichetta',
  placeholder: '',
  options: [],           // per select/checkbox/radio
  datasource: {},        // per dbselect
  multiple: false,       // per dbselect
  colspan: 1|2,
  children: [],          // per type=section
  is_fixed: false
}
```

---

## 7. Sistema Drag & Drop

### Origini

1. **Palette (fields)** – `e.dataTransfer.setData('field-type', type)`, `pe-origin: fields`
2. **Preview (root)** – `e.dataTransfer.setData('pe-dragged-uid', uid)`, `pe-internal: 1`
3. **Sezione** – `e.dataTransfer.setData('pe-dragged-child-uid', uid)`, `pe-internal-sec: 1`

### Destinazioni

- **Form-fields-preview** (root grid) – `.pe-grid`
- **Empty state** – quando non ci sono campi
- **Sezione** – `.section-grid` dentro `.editor-section`

### Segnaposto (drop slot)

- Classe `.pe-slot-marker`
- Posizionamento con `placeDropSlotAtPointer(clientX, clientY, isFull)`
- `isFull` per span a tutta larghezza (Alt durante drag)

### Logica riordino

- `computeFieldOrderFromDOM(draggedUid)` – legge ordine dal DOM
- `computeChildOrderFromDOM` – per sezioni
- UID usati per identificare campi in modo stabile

### Drag da palette

- `dragstart` → `setData('field-type', type)`, `setData('pe-origin', 'fields')`
- `drop` → crea nuovo campo con `claim()` per nome univoco, `uid` generato

### Drag interno (riordino)

- `dragstart` → `setData('pe-dragged-uid', uid)` o `pe-dragged-child-uid`
- `drop` → rimuove da sorgente, inserisce in ordine calcolato

### Handle trascinamento

- Classe `.drag-handle.icon-handle`

---

## 8. Schede (Tab)

### Struttura tab

- `DEFAULT_TAB_KEY = 'struttura'` – scheda principale
- `PROPERTIES_TAB_KEY` – scheda proprietà (non ha campi)
- Ogni scheda ha: `key`, `label`, `fields`, `isMain`, `isClosureTab`, `visibility_roles`, `edit_roles`, `visibility_condition`, `redirect_after_submit`, `unlock_after_submit_prev`

### Configurazione scheda (tab State)

```javascript
{
  fields: [],
  hasFixed: true,           // Struttura ha campi fissi
  submitLabel: 'Salva',
  submitAction: 'submit',    // submit | next_step
  isMain: true,
  isClosureTab: false,
  visibilityMode: 'all',
  unlockAfterSubmitPrev: false,
  visibilityRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
  editRoles: ['utente', 'responsabile', 'assegnatario', 'admin'],
  visibilityCondition: { type: 'always' },
  redirectAfterSubmit: false
}
```

### Scheda di chiusura (Esito)

- `isClosureTab: true` – aggiunge campi esito (Accettata/Rifiutata, note, data)
- `unlock_after_submit_prev` – sblocco dopo submit della scheda precedente
- Visibilità: responsabile/assegnatario/admin; utente dopo submit

### Persistenza

- `saveFormStructure` salva `tab_label` per ogni campo in `form_fields`
- `tabs_config` in `forms` (JSON) da `saveFormTabs` (deprecato, delegato a saveFormStructure)

---

## 9. Anteprima

### Trigger

- Click su `#pe-preview-btn` (Anteprima)

### Comportamento

1. Costruisce HTML completo della `.pagina-foglio` (con form-meta-block, tab bar, campi)
2. Inserisce in `#preview-modal`
3. Mostra modale con `toggleModal('preview-modal', 'open')`
4. Tab switch client-side (nessuna chiamata server)
5. Per dbselect: chiama `datasource.getOptions` per popolare le opzioni

### Struttura HTML anteprima

- `form-meta-block` – campi fissi
- `form-tabs-bar` – tab
- `form-tabs-content` – contenuti per tab
- `view-form-grid` – campi
- Sezioni con `fieldset`, `section-grid`

---

## 10. Stati personalizzati

### API

- `getFormStates(form_name)` – elenco stati
- `saveFormStates({ form_name, states })` – salvataggio

### Struttura stato

```javascript
{
  id: null,
  name: 'Aperta',
  color: '#3498DB',
  sort_order: 10,
  active: 1,
  base_group: 1,
  is_base: 1
}
```

### Default

- Aperta (#3498DB)
- In corso (#F1C40F)
- Chiusa (#2ECC71)

### UI

- Lista ordinabile con drag
- Colore per ogni stato
- Checkbox attivo

---

## 11. Notifiche (email, in-app)

### Configurazione (peNotifyConfig)

```javascript
{
  enabled: true,
  events: {
    on_submit: true,
    on_status_change: true,
    on_assignment_change: true
  },
  channels: {
    in_app: true,
    email: true
  },
  recipients: {
    responsabile: true,
    assegnatario: true,
    autore: true,
    custom_email_value: '',
    custom_email: false
  },
  messages: {
    in_app_message: '',
    email_subject: '',
    email_body: ''
  }
}
```

### Eventi trigger

- `on_submit` – submit form
- `on_status_change` – cambio status_id
- `on_assignment_change` – cambio assegnato_a

### Canali

- `in_app` – NotificationService::inviaNotifica (tabella notifiche)
- `email` – invio via PHPMailer (SMTP da .env)

### Destinatari

- `responsabile` – form.responsabile
- `assegnatario` – assegnato_a del record
- `autore` – submitted_by
- `custom_email_value` – email custom

### Placeholder messaggi

- `{titolo}`, `{descrizione}`, `{now}`, `{attore}`, `{autore}`, ecc.

### Flusso

1. `PageEditorService::submitScheda` / `FormSubmissionService::updateEsito` / cambio assegnazione
2. `NotificationService::processRules(formName, event, recordData)`
3. Legge `notification_rules` per form
4. Filtra per evento e canali
5. Risolve destinatari
6. Sostituisce placeholder
7. Invia in-app e/o email

---

## 12. Datasources

### Servizio

- `datasource` in service_router
- `getWhitelistedTables` – tabelle whitelist
- `getOptions` – opzioni per dbselect (table, valueCol, labelCol)
- `adminListTables`, `adminListColumns`, `adminToggleTable`, `adminUpdateColumns` – gestione whitelist

### Config dbselect

```javascript
{
  table: 'personale',
  valueCol: 'user_id',
  labelCol: 'Nominativo',
  multiple: 0,
  q: '',
  limit: 200
}
```

Oppure per codice datasource:

```javascript
{
  datasource: 'personale_disponibile',
  multiple: 0
}
```

### Tabella sys_datasources

- Whitelist tabelle per dbselect

---

## 13. API e service router

### Azioni page_editor

| Action | Input | Output |
|--------|-------|--------|
| getForm | form_name, record_id? | form, tabs, fields |
| ensureForm | form_name, description?, color?, button_text? | success, form_name, created |
| saveFormStructure | form_name, fields, tabs? | success |
| getFormFields | form_name | fields |
| deleteForm | form_id | success |
| getAllFormsForAdmin | - | forms |
| loadFormTabs | form_name | tabs |
| saveFormTabs | form_name, tabs_data | success |
| getFormStates | form_name | states |
| saveFormStates | form_name, states | success |
| getNotificationRules | form_name | rules |
| saveNotificationRules | form_name, config | success |
| getMenuPlacementForForm | form_name | placement |
| getFilledForms | form_name, filtri | forms |
| submitScheda | form_name, record_id?, data, files | success, id |
| beforeSaveStructure | form_name | success |

---

## 14. Flusso salvataggio e submit

### Salvataggio struttura (page_editor)

1. `save_structure()` in page_editor.js
2. Serializza `fields` da `tabState` (per ogni scheda)
3. Campi fissi: skip (`is_fixed` skip)
4. Sezioni: `field_name: '__section__'`, `field_type: 'section'`, `field_options: { label, uid }`
5. Chiama `page_editor.saveFormStructure` con payload flat
6. `PageEditorService::saveFormStructure`:
   - DELETE campi dinamici (is_fixed=0)
   - INSERT nuovi campi
   - `ensureFixedFields` prima del save

### Submit record (view_form / form_viewer)

1. `submitScheda` con FormData o JSON
2. `PageEditorService::submitScheda`:
   - Legge form e tabella
   - Verifica permessi
   - INSERT o UPDATE record
   - Salva campi dinamici (colonne fisiche o EAV)
   - Gestione file upload
   - Sync assegnato_a
   - Aggiorna form_schede_status
   - Chiama `NotificationService::processRules` per `on_submit`
3. Redirect a form_viewer con `id`

### Redirect dopo submit

- Default: `form_viewer?form_name=X&id=ID`
- Per tab: `redirect_after_submit: true` può passare alla scheda successiva

---

## Riepilogo checklist per riproduzione

- [ ] Creare tabella `forms` con `tabs_config` JSON
- [ ] Creare tabella `form_fields` con `parent_section_uid`, `tab_label`
- [ ] Creare tabella `form_states`
- [ ] Creare tabella `form_schede_status`
- [ ] Creare tabella `notification_rules`
- [ ] Creare tabella `form_values` (EAV)
- [ ] Implementare `ensureForm` + `ensureFixedFields` + `saveFormStructure`
- [ ] Implementare `submitScheda` con gestione file e EAV
- [ ] Implementare palette campi con drag (HTML5 DnD)
- [ ] Implementare preview con grid e drop slot
- [ ] Implementare toolbar (Proprietà, Campi, Stati, Schede, Notifiche)
- [ ] Implementare form-meta-block (disabled)
- [ ] Implementare bottom bar (Anteprima, Salva)
- [ ] Implementare `NotificationService::processRules`
- [ ] Implementare datasource service per dbselect
- [ ] Creare view_form.php e form_viewer.php
- [ ] Creare gestione_segnalazioni / generic_form_listing
- [ ] Integrare con menu_custom e function-bar

---

*Documento generato per supportare la riproduzione completa del page_editor in un altro progetto.*
