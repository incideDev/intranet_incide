# Page Editor - API Contract & Service Actions

Documento tecnico: tutti gli endpoint AJAX, contratti input/output, routing.
**Nessun riferimento a UI/design** - solo contratti API puri.

---

## Architettura delle Chiamate

Tutte le chiamate passano per:
```
Frontend (customFetch / fetch)
  -> POST /ajax.php
    -> service_router.php (mappa section -> Service)
      -> PageEditorService::handleAction($action, $input)
```

**Headers richiesti**: `X-Csrf-Token`, `Content-Type: application/json`
**Payload**: `{ section: string, action: string, ...params }`
**Risposta**: `{ success: boolean, data|message: mixed }`

---

## 1. Azioni PageEditorService

### 1.1 `ensureForm` - Crea o aggiorna modulo

**Scopo**: Crea un nuovo modulo con tabella dati e campi fissi, o aggiorna metadati se esiste.

```
Input:
{
  section: "page_editor",
  action: "ensureForm",
  name: string,              // RICHIESTO - nome modulo
  description: string|null,  // opzionale
  color: string|null,        // opzionale - hex (#RGB o #RRGGBB)
  button_text: string|null   // opzionale - testo pulsante submit
}

Output (successo, nuovo):
{
  success: true,
  created: true,
  form_name: "segnalazione_it",
  table_name: "mod_segnalazione_it",
  message: "Creato form segnalazione_it"
}

Output (successo, esistente):
{
  success: true,
  created: false,
  form_name: "segnalazione_it",
  table_name: "mod_segnalazione_it",
  message: "Form gia esistente, metadati aggiornati"
}
```

**Effetti collaterali**:
- Crea tabella `mod_{name}` con colonne base
- Inserisce 5 campi fissi in `form_fields`
- Genera codice `protocollo` univoco
- Normalizza colore a formato #RRGGBB

---

### 1.2 `getForm` - Carica modulo completo

**Scopo**: Restituisce metadati, schede con campi, e opzionalmente dati di un record.

```
Input:
{
  section: "page_editor",
  action: "getForm",
  form_name: string,         // RICHIESTO
  record_id: number|null     // opzionale - se presente carica anche i dati del record
}

Output:
{
  success: true,
  form: {
    id: number,
    name: string,
    display_name: string|null,
    description: string|null,
    color: string,              // "#RRGGBB"
    table_name: string,
    responsabile: string|null,  // "3,7" (comma-separated IDs)
    protocollo: boolean,
    button_text: string|null
  },
  tabs: {
    "struttura": {
      fields: [
        {
          id: number,
          field_name: string,
          field_type: string,
          field_label: string|null,
          field_placeholder: string|null,
          field_options: mixed,     // array per select, object per dbselect, null per altri
          required: boolean,
          is_fixed: boolean,
          sort_order: number,
          colspan: number,
          parent_section_uid: string|null
        }
      ],
      submit_label: string,
      submit_action: "submit"|"next_step",
      scheda_type: "utente"|"responsabile"|"chiusura",
      visibility_roles: ["utente", "responsabile", "assegnatario", "admin"],
      edit_roles: ["utente"],
      visibility_condition: { type: string, depends_on?: string },
      isClosureTab: boolean,
      unlock_after_submit_prev: boolean,
      is_main: boolean,
      redirect_after_submit: boolean
    },
    "esito": { ... }
  },
  fields: [...],               // array piatto di tutti i campi (backward compat)
  struttura_display_label: string|null,
  entry: {...}|null            // dati record se record_id fornito
}
```

**Logica speciale**:
- Se non esiste tab "Esito" con `isClosureTab=true`, viene creata automaticamente
- I campi fissi senza `tab_label` vengono migrati a "Struttura"
- `field_options` per dbselect viene decodificato da JSON a object
- Applica `fixMojibake()` per correzione encoding

---

### 1.3 `saveFormStructure` - Salva struttura campi

**Scopo**: Salva l'intera struttura dei campi dinamici e la configurazione delle schede.

```
Input:
{
  section: "page_editor",
  action: "saveFormStructure",
  form_name: string,                  // RICHIESTO
  fields: [                           // RICHIESTO - array piatto di tutti i campi
    {
      field_name: string,             // nome colonna (generato da label)
      field_label: string,            // etichetta leggibile
      field_type: string,             // text, textarea, select, checkbox, radio, file, date, dbselect, section
      field_options: mixed,           // array per select, object per dbselect
      is_fixed: 0|1,                  // campi fissi vengono IGNORATI (skip)
      sort_order: number,             // ordine (10, 20, 30...)
      colspan: 1|2,
      parent_section_uid: string|null,
      tab_label: string               // nome scheda: "Struttura", "Dettagli", ecc.
    }
  ],
  tabs_config: string,                // opzionale - JSON stringificato della config schede
  replace_all_tabs: boolean           // opzionale (default false) - se true cancella schede non nel payload
}

Output:
{
  success: true,
  saved: true,
  form_name: string,
  saved_dynamic: number,      // numero campi dinamici salvati
  message: string
}

Output (errore):
{
  success: false,
  message: string,
  code: "VALIDATION_ERROR"|"JSON_INVALID"|"DB_ERROR",
  details: string             // solo se APP_DEBUG attivo
}
```

**Strategia di salvataggio**: DELETE + INSERT per-tab (transazionale)
1. Per ogni scheda nel payload: cancella tutti i campi `is_fixed=0` di quella scheda
2. Inserisce i nuovi campi dal payload
3. Se `replace_all_tabs=true`: cancella anche schede nel DB non presenti nel payload
4. Merge `tabs_config` con config esistente (nuova sovrascrive vecchia)
5. ALTER TABLE per aggiungere colonne mancanti

---

### 1.4 `submitScheda` - Invio dati di una scheda

**Scopo**: Salva i dati compilati dall'utente per una specifica scheda di un record.

```
Input (JSON):
{
  section: "page_editor",
  action: "submitScheda",
  form_name: string,          // RICHIESTO
  record_id: number,          // RICHIESTO (> 0)
  scheda_key: string,         // RICHIESTO (es. "dettagli", "esito")
  values: {                   // RICHIESTO
    campo1: "valore1",
    campo2: "valore2"
  }
}

Input (con file - FormData):
  FormData con:
    section: "page_editor"
    action: "submitScheda"
    form_name: string
    record_id: number
    scheda_key: string
    values[campo1]: "valore1"
    campo_file: File

Output:
{
  success: true,
  message: string,
  updated_fields: number,     // colonne fisiche aggiornate
  eav_fields: number          // campi salvati in EAV
}
```

**Vincoli**:
- `scheda_key === 'struttura'` -> **RIFIUTATO** (struttura e readonly)
- Solo admin, responsabile, o assegnatario possono fare submit
- Schede gia `submitted` -> bloccate per non-manager

**Effetti collaterali**:
- Aggiorna colonne fisiche nella tabella mod_*
- Salva campi senza colonna in form_values (EAV)
- Gestisce upload file
- Sincronizza assegnato_a se presente assegnato_a_esito
- Aggiorna form_schede_status a "submitted"
- Salva subtask per audit trail

---

### 1.5 `getFormStates` - Carica stati workflow

```
Input:
{
  section: "page_editor",
  action: "getFormStates",
  form_name: string
}

Output:
{
  success: true,
  states: [
    {
      id: number,
      name: string,           // "Aperta", "In corso", "Chiusa"
      color: string,          // "#3498DB"
      sort_order: number,     // 10, 20, 30
      active: 0|1,
      base_group: 1|2|3,     // 1=Aperti, 2=In corso, 3=Chiusi
      is_base: 0|1
    }
  ]
}
```

Se nessuno stato configurato, restituisce i 3 default: Aperta, In corso, Chiusa.

---

### 1.6 `saveFormStates` - Salva stati workflow

```
Input:
{
  section: "page_editor",
  action: "saveFormStates",
  form_name: string,
  states: [
    {
      name: string,
      color: string,
      active: 0|1,
      base_group: 1|2|3,
      is_base: 0|1
    }
  ]
}

Output:
{ success: true, message: string }
```

**Strategia**: DELETE ALL + INSERT (transazionale). Sort_order rigenerato: 10, 20, 30...

---

### 1.7 `getSchedeStatus` - Stato compilazione schede

```
Input:
{
  section: "page_editor",
  action: "getSchedeStatus",
  form_name: string,
  record_id: number
}

Output:
{
  success: true,
  schede_status: {
    "struttura": {
      status: "submitted",
      updated_at: "2026-03-20 14:30:00",
      updated_by: 5
    },
    "dettagli": {
      status: "not_started",
      updated_at: null,
      updated_by: null
    }
  }
}
```

---

### 1.8 `updateSchedaStatus` - Aggiorna stato scheda manualmente

```
Input:
{
  section: "page_editor",
  action: "updateSchedaStatus",
  form_name: string,
  record_id: number,
  scheda_key: string,
  status: "not_started"|"draft"|"submitted"
}

Output:
{ success: true, message: string }
```

---

### 1.9 `calculateNextScheda` - Calcola prossima scheda

```
Input:
{
  section: "page_editor",
  action: "calculateNextScheda",
  form_name: string,
  record_id: number,
  current_scheda_key: string,
  user_id: number,
  role_id: number
}

Output:
{
  success: true,
  next_scheda_key: string|null,   // null se e l'ultima
  is_last: boolean,
  message: string
}
```

---

### 1.10 `getNotificationRules` - Carica regole notifiche

```
Input:
{
  section: "page_editor",
  action: "getNotificationRules",
  form_name: string
}

Output:
{
  success: true,
  rules: {
    form_name: string,
    enabled: boolean,
    events: ["on_submit", "on_status_change", "on_assignment_change"],
    channels: { in_app: boolean, email: boolean },
    recipients: {
      responsabile: boolean,
      assegnatario: boolean,
      autore: boolean,
      custom_email: boolean,
      custom_email_value: string
    },
    messages: {
      in_app_message: string,
      email_subject: string,
      email_body: string,
      email_template: "base_template"|"minimal_template"|"none"
    }
  } | null
}
```

---

### 1.11 `saveNotificationRules` - Salva regole notifiche

```
Input:
{
  section: "page_editor",
  action: "saveNotificationRules",
  form_name: string,
  config: {
    enabled: boolean,
    events: { on_submit: boolean, on_status_change: boolean, on_assignment_change: boolean },
    channels: { in_app: boolean, email: boolean },
    recipients: { responsabile: boolean, assegnatario: boolean, autore: boolean, custom_email: boolean, custom_email_value: string },
    messages: { in_app_message: string, email_subject: string, email_body: string, email_template: string }
  }
}

Output:
{ success: true, message: string }
```

---

### 1.12 `setFormResponsabile` - Assegna responsabili

```
Input:
{
  section: "page_editor",
  action: "setFormResponsabile",
  form_name: string,
  user_ids: string            // comma-separated: "3,7,12"
}

Output:
{ success: true, message: string }
```

Valida che ogni user_id esista in tabella `personale`. Notifica nuovi responsabili.

---

### 1.13 `getFormFields` - Campi piatti (backward compat)

```
Input:
{ section: "page_editor", action: "getFormFields", form_name: string }

Output:
{
  success: true,
  fields: [
    { field_name, field_type, field_placeholder, field_options, required, is_fixed, sort_order, colspan, parent_section_uid }
  ]
}
```

---

### 1.14 `getAllFormsForAdmin` - Lista tutti i moduli

```
Input:
{ section: "page_editor", action: "getAllFormsForAdmin" }

Output:
{
  success: true,
  forms: [
    { id, name, table_name, description, color, responsabile, protocollo, created_at }
  ]
}
```

---

### 1.15 `deleteForm` - Elimina modulo

```
Input:
{ section: "page_editor", action: "deleteForm", form_name: string }

Output:
{ success: true, message: string }
```

**Effetti**: Cancella record da forms, form_fields, form_schede_status. DROP TABLE mod_*.

---

### 1.16 `beforeSaveStructure` - Pre-validazione

```
Input:
{ section: "page_editor", action: "beforeSaveStructure", form_name: string }

Output:
{ success: true }
```

Hook pre-salvataggio. Esegue validazione/trasformazione preparatoria.

---

### 1.17 `updateFormMeta` - Aggiorna metadati

```
Input:
{
  section: "page_editor",
  action: "updateFormMeta",
  form_name: string,
  description: string,      // opzionale
  color: string,            // opzionale
  display_name: string      // opzionale
}

Output:
{ success: true, message: string }
```

---

### 1.18 `listResponsabili` - Lista personale disponibile

```
Input:
{ section: "page_editor", action: "listResponsabili" }

Output:
{
  success: true,
  options: [
    { id: number, label: string, img: string }
  ]
}
```

---

## 2. Azioni DatasourceService (per campi dbselect)

### 2.1 `getOptions` - Opzioni da tabella DB

```
Input:
{
  section: "datasource",
  action: "getOptions",
  table: string,             // RICHIESTO - nome tabella
  valueCol: string,          // default "id"
  labelCol: string           // default "descrizione"
}

Output:
{
  success: true,
  options: [
    { v: "1", l: "Nome Cognome" },
    { v: "2", l: "Altro Nome" }
  ]
}
```

**Sicurezza**: La tabella deve essere nella whitelist `sys_db_whitelist` con `is_active=1`.

### 2.2 `resolveDbselectValues` - Risolvi ID in label

```
Input:
{
  section: "datasource",
  action: "resolveDbselectValues",
  fields: [
    { table: string, valueCol: string, labelCol: string, value: "1,3,5" }
  ]
}

Output:
{
  success: true,
  resolved: {
    "0": "Mario Rossi, Luca Bianchi, Anna Verdi"
  }
}
```

---

## 3. Azioni MenuCustomService (per posizionamento menu)

### 3.1 `getSectionsAndParents` - Struttura menu

```
Input:
{ section: "menu_custom", action: "getSectionsAndParents" }

Output:
{
  success: true,
  sections: ["collaborazione", "gestione", "hr"],
  parents: {
    "collaborazione": ["Segnalazioni", "Richieste"],
    "gestione": ["Moduli", "Impostazioni"]
  }
}
```

### 3.2 `upsert` - Crea/aggiorna voce menu

```
Input:
{
  section: "menu_custom",
  action: "upsert",
  menu_section: string,      // sezione: "collaborazione"
  parent_title: string,      // menu padre: "Segnalazioni"
  title: string,             // titolo voce: "IT - Segnalazioni"
  link: string,              // URL: "index.php?section=collaborazione&page=gestione_segnalazioni&form_name=segnalazione_it"
  attivo: 1,
  ordinamento: number
}

Output:
{ success: true, message: string }
```

---

## 4. Azioni FormSubmissionService (per esito/chiusura)

### 4.1 `updateEsito` - Aggiorna esito record

```
Input:
{
  section: "form_submission",
  action: "updateEsito",
  form_name: string,
  record_id: number,
  esito_stato: "accettata"|"in_valutazione"|"rifiutata",
  esito_note: string,
  meta_esito: {
    data_apertura_esito: string|null,
    deadline_esito: string|null,
    assegnato_a_esito: string|null,
    priorita_esito: string|null,
    stato_esito: string|null
  }
}

Output:
{ success: true, message: string }
```

**Effetti collaterali**:
- Aggiorna colonne esito nel record
- Mappa stato -> status_id
- Imposta esito_data a NOW se null
- Sincronizza assegnato_a
- Chiama NotificationService per `on_status_change`
- Aggiorna form_schede_status a `submitted`
- Salva subtask per audit

---

## 5. Azioni FormsDataService (per listing)

### 5.1 `getFilledForms` - Lista record compilati

```
Input:
{
  section: "page_editor",
  action: "getFilledForms",
  form_name: string,         // opzionale - filtra per modulo specifico
  status_id: number,         // opzionale
  data_invio_min: string,    // opzionale (YYYY-MM-DD)
  responsabile: string,      // opzionale
  archivio: boolean          // opzionale - mostra archiviati
}

Output:
{
  success: true,
  forms: [
    {
      id: number,
      form_name: string,
      creato_da: string,
      creato_da_img: string,
      assegnato_a: string,           // "3,7" (IDs)
      assegnato_a_nome: string,      // "Mario Rossi, Luca Bianchi"
      responsabile: string,          // "5" (IDs)
      responsabile_nome: string,     // "Anna Verdi"
      responsabile_img: string,
      responsabili: [                // array completo con immagini
        { id: number, nome: string, img: string }
      ],
      submitted_by: number,
      data_invio: string,            // "20/03/2026"
      data_scadenza: string,
      priority: string,
      stato: number,                 // status_id
      table_name: string,
      color: string,
      titolo: string,
      descrizione: string,
      codice_segnalazione: string,
      subtasks: [...]
    }
  ]
}
```

**Logica di filtraggio**:
- Esclude subtask: `WHERE parent_record_id IS NULL OR parent_record_id = 0`
- Se `archivio=true`: filtra `archiviata=1`, altrimenti `archiviata IS NULL OR archiviata=0`

---

## 6. Riepilogo Endpoint per Contesto d'Uso

### Editor (creazione/modifica modulo):
| Azione | Quando |
|--------|--------|
| `ensureForm` | Creazione nuovo modulo |
| `getForm` | Caricamento modulo per editing |
| `saveFormStructure` | Salvataggio struttura campi + tab config |
| `saveFormStates` | Salvataggio stati Kanban |
| `saveNotificationRules` | Salvataggio regole notifiche |
| `setFormResponsabile` | Assegnazione responsabili |
| `menu_custom.upsert` | Creazione/aggiornamento voce menu |

### Listing (tabella record):
| Azione | Quando |
|--------|--------|
| `getFilledForms` | Caricamento lista record |
| `getFormStates` | Caricamento stati per filtri |

### Compilazione (view_form):
| Azione | Quando |
|--------|--------|
| `getForm` | Caricamento struttura form |
| `submitScheda` | Invio dati compilati |
| `datasource.getOptions` | Caricamento opzioni dbselect |

### Visualizzazione (form_viewer):
| Azione | Quando |
|--------|--------|
| `getForm` (con record_id) | Caricamento form + dati record |
| `getSchedeStatus` | Stato compilazione schede |
| `submitScheda` | Invio dati schede editabili |
| `updateEsito` | Aggiornamento esito/chiusura |
| `calculateNextScheda` | Navigazione alla scheda successiva |
