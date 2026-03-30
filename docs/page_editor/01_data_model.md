# Page Editor - Data Model & Schema DB

Documento tecnico: struttura dati, tabelle, relazioni, formati JSON.
**Nessun riferimento a UI/design** - solo modello dati puro.

---

## Panoramica Entita

Il sistema page_editor gestisce **form dinamici** (moduli). Ogni modulo ha:
- Metadati (nome, colore, responsabile)
- Campi configurabili (fissi + dinamici)
- Schede/tab con workflow
- Stati personalizzati (Kanban)
- Regole di notifica
- Record compilati dagli utenti

---

## 1. Tabella `forms` (Metadati Modulo)

Contiene la definizione di ogni modulo creato.

```sql
CREATE TABLE forms (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,        -- identificativo univoco (lowercase, snake_case)
    display_name     VARCHAR(255) NULL,             -- nome leggibile (con spazi e accenti)
    description      TEXT NULL,                     -- descrizione del modulo
    table_name       VARCHAR(255) NOT NULL,         -- nome tabella dati: "mod_{name}"
    responsabile     VARCHAR(255) NULL,             -- ID utenti responsabili (comma-separated: "3,7,12")
    created_by       INT NULL,                      -- user_id del creatore
    created_at       DATETIME NULL,
    color            VARCHAR(7) DEFAULT '#CCCCCC',  -- colore hex (#RRGGBB)
    protocollo       VARCHAR(255) DEFAULT NULL,     -- codice protocollo univoco (es. "RS_IT", "RS_IT_2")
    button_text      VARCHAR(255) NULL,             -- testo personalizzato pulsante submit
    struttura_display_label VARCHAR(255) NULL,      -- label custom per tab "Struttura"
    tabs_config      TEXT NULL,                     -- JSON: configurazione completa schede (vedi sezione 7)
    UNIQUE KEY (name)
);
```

### Campi chiave:
- **`name`**: Identificativo normalizzato. Generato da input utente: lowercase, rimozione caratteri speciali (accenti preservati), spazi ammessi poi convertiti in underscore per table_name
- **`table_name`**: Sempre `mod_{name}` con underscore al posto degli spazi
- **`responsabile`**: Stringa comma-separated di user_id dal personale. Es: `"3,7"` = due responsabili
- **`protocollo`**: Generato automaticamente: iniziali del nome + suffisso numerico per unicita. Es: `RS_IT`, `RS_IT_2`
- **`tabs_config`**: JSON completo della configurazione schede (vedi sezione 7)

---

## 2. Tabella `form_fields` (Definizione Campi)

Ogni riga = un campo del modulo. Include campi fissi e dinamici.

```sql
CREATE TABLE form_fields (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    form_id             INT NOT NULL,                    -- FK -> forms.id
    field_name          VARCHAR(100) NOT NULL,           -- nome colonna/campo (snake_case)
    field_type          VARCHAR(50),                     -- tipo: text, textarea, select, checkbox, radio, file, date, dbselect, section
    field_label         VARCHAR(100) NULL,               -- etichetta leggibile
    field_placeholder   TEXT NULL,                        -- placeholder/hint
    field_options       TEXT NULL,                        -- JSON: opzioni per select/radio/checkbox, config per dbselect, metadata per section
    required            TINYINT(1) DEFAULT 0,            -- campo obbligatorio
    is_fixed            TINYINT(1) DEFAULT 0,            -- 1 = campo fisso (non rimovibile)
    sort_order          INT UNSIGNED DEFAULT 0,          -- ordine nel form (10, 20, 30...)
    colspan             TINYINT UNSIGNED DEFAULT 1,      -- 1 = meta larghezza, 2 = larghezza piena
    parent_section_uid  VARCHAR(64) NULL,                -- se annidato in una sezione: UID della sezione padre
    tab_label           VARCHAR(100) NULL DEFAULT 'Struttura', -- nome della scheda di appartenenza
    tab_order           INT DEFAULT 0,                   -- ordine della scheda

    INDEX (form_id),
    INDEX (tab_label),
    UNIQUE KEY uk_form_field (form_id, field_name, parent_section_uid)
);
```

### Campi chiave:
- **`field_name`**: Per campi normali: slug generato dall'etichetta. Per sezioni: `__section__{uid}`
- **`field_options`**: JSON il cui formato dipende dal `field_type` (vedi sezione 5)
- **`is_fixed`**: I 5 campi fissi hanno `is_fixed=1` e non vengono mai cancellati da saveFormStructure
- **`parent_section_uid`**: Collega un campo alla sezione contenitore. NULL = campo al livello root
- **`tab_label`**: Associa il campo a una scheda specifica. Default: "Struttura"

### Vincolo di unicita:
La combinazione `(form_id, field_name, parent_section_uid)` e unica. Questo permette campi con lo stesso nome in sezioni diverse.

---

## 3. Tabella `mod_{nome}` (Dati dei Record)

Creata dinamicamente per ogni modulo. Contiene i record compilati dagli utenti.

### Struttura base (sempre presente):

```sql
CREATE TABLE mod_{nome_modulo} (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by          INT NOT NULL,                   -- user_id di chi ha compilato
    submitted_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deadline              DATE NULL,
    titolo                VARCHAR(255) NOT NULL,
    descrizione           TEXT NOT NULL,
    priority              VARCHAR(255) DEFAULT 'Media',   -- Bassa, Media, Alta
    assegnato_a           VARCHAR(255) NULL,               -- ID utente/i (comma-separated)
    status_id             INT DEFAULT 1,                   -- FK logica -> form_states.id
    completed_at          DATETIME NULL,
    codice_segnalazione   VARCHAR(255) DEFAULT NULL,       -- codice protocollo del record
    esito_stato           VARCHAR(50) DEFAULT NULL,        -- accettata, in_valutazione, rifiutata
    esito_note            TEXT DEFAULT NULL,
    esito_data_prevista   DATE DEFAULT NULL,
    esito_data            DATETIME DEFAULT NULL
);
```

### Colonne dinamiche:
Per ogni campo custom di tipo `text`, `textarea`, `select`, `date`, `checkbox`, `radio`, `dbselect` viene aggiunta una colonna fisica via `ALTER TABLE ... ADD COLUMN`. Il tipo DDL dipende dal field_type:
- text -> `VARCHAR(255) NULL`
- textarea -> `TEXT NULL`
- select -> `VARCHAR(255) NULL`
- checkbox -> `TEXT NULL` (valori multipli JSON)
- radio -> `VARCHAR(255) NULL`
- date -> `DATE NULL`
- dbselect -> `VARCHAR(255) NULL` (ID o ID comma-separated se multiple)
- file -> `VARCHAR(512) NULL` (path del file)

### Storage ibrido:
Se la colonna fisica esiste -> valore salvato direttamente nella tabella.
Se la colonna NON esiste -> valore salvato in tabella EAV `form_values`.

---

## 4. Tabella `form_states` (Stati Workflow/Kanban)

Stati personalizzabili per ogni modulo (es. Aperta, In corso, Chiusa).

```sql
CREATE TABLE form_states (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,                  -- FK -> forms.id
    name        VARCHAR(100) NOT NULL,         -- nome dello stato
    color       VARCHAR(7) DEFAULT '#95A5A6',  -- colore hex
    sort_order  INT NOT NULL DEFAULT 0,        -- ordine (10, 20, 30...)
    active      TINYINT(1) NOT NULL DEFAULT 1, -- stato attivo/disattivo
    base_group  TINYINT(1) NULL,               -- gruppo: 1=Aperti, 2=In corso, 3=Chiusi
    is_base     TINYINT(1) NOT NULL DEFAULT 0, -- se e lo stato "principale" del gruppo
    INDEX (form_id)
);
```

### Stati default (creati se tabella vuota):
| name | color | base_group | is_base |
|------|-------|------------|---------|
| Aperta | #3498DB | 1 | 1 |
| In corso | #F1C40F | 2 | 1 |
| Chiusa | #2ECC71 | 3 | 1 |

### Logica base_group:
Raggruppa gli stati in 3 categorie logiche (Aperti, In corso, Chiusi). Ogni gruppo ha uno stato `is_base=1` che funge da stato principale. Stati aggiuntivi possono essere aggiunti a ciascun gruppo.

---

## 5. Tabella `form_schede_status` (Stato Compilazione Schede)

Traccia lo stato di compilazione di ogni scheda per ogni record.

```sql
CREATE TABLE form_schede_status (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,                   -- FK -> forms.id
    record_id   INT NOT NULL,                   -- FK -> mod_{nome}.id
    scheda_key  VARCHAR(100) NOT NULL,          -- chiave scheda (es. 'struttura', 'dettagli', 'esito')
    status      ENUM('not_started', 'draft', 'submitted') DEFAULT 'not_started',
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by  INT NULL,                       -- user_id di chi ha aggiornato
    UNIQUE KEY uk_form_record_scheda (form_id, record_id, scheda_key)
);
```

### Transizioni di stato:
```
not_started -> draft -> submitted
```
- **not_started**: La scheda non e mai stata toccata
- **draft**: Salvata ma non inviata (riservato per uso futuro, attualmente si passa diretto a submitted)
- **submitted**: Inviata/confermata - diventa readonly per l'autore

---

## 6. Tabella `form_values` (EAV per campi senza colonna fisica)

Storage Entity-Attribute-Value per campi custom che non hanno una colonna fisica nella tabella mod_*.

```sql
CREATE TABLE form_values (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    record_id   INT NOT NULL,
    field_name  VARCHAR(255) NOT NULL,
    field_value TEXT NULL,
    UNIQUE KEY uk_fv (form_id, record_id, field_name)
);
```

### Quando viene usato:
- Se `addColumnIfMissing` fallisce o la colonna non e stata creata
- Per campi aggiunti dopo che alcuni record esistono gia
- Come fallback di sicurezza

---

## 7. Struttura JSON `tabs_config`

Salvato in `forms.tabs_config`. Definisce il workflow multi-scheda.

```json
{
  "struttura": {
    "label": "Struttura",
    "submit_label": "Avanti",
    "submit_action": "submit",
    "is_main": 1,
    "scheda_type": "utente",
    "visibility_roles": ["utente", "responsabile", "assegnatario", "admin"],
    "edit_roles": ["utente"],
    "visibility_condition": {
      "type": "always"
    },
    "isClosureTab": false,
    "unlock_after_submit_prev": 0,
    "redirect_after_submit": false
  },
  "dettagli": {
    "label": "Dettagli",
    "submit_label": "Salva",
    "submit_action": "submit",
    "is_main": 0,
    "scheda_type": "responsabile",
    "visibility_roles": ["responsabile", "assegnatario", "admin"],
    "edit_roles": ["responsabile", "assegnatario", "admin"],
    "visibility_condition": {
      "type": "after_step_submitted",
      "depends_on": "struttura"
    },
    "isClosureTab": false,
    "unlock_after_submit_prev": 1,
    "redirect_after_submit": false
  },
  "esito": {
    "label": "Esito",
    "scheda_type": "chiusura",
    "edit_roles": ["responsabile", "assegnatario", "admin"],
    "visibility_roles": ["responsabile", "assegnatario", "admin"],
    "isClosureTab": true,
    "visibility_condition": {
      "type": "after_all_previous_submitted"
    },
    "unlock_after_submit_prev": 1
  }
}
```

### Proprieta di ogni scheda:

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `label` | string | Nome leggibile della scheda |
| `submit_label` | string | Testo del pulsante di invio |
| `submit_action` | `"submit"` o `"next_step"` | Azione post-invio |
| `is_main` | 0/1 | Scheda principale (solo una) |
| `scheda_type` | `"utente"`, `"responsabile"`, `"chiusura"` | Tipo di scheda (determina regole workflow) |
| `visibility_roles` | array | Ruoli che vedono la scheda |
| `edit_roles` | array | Ruoli che possono modificare |
| `visibility_condition` | object | Condizione di visibilita |
| `isClosureTab` | bool | Se true, scheda di chiusura con campi esito |
| `unlock_after_submit_prev` | 0/1 | Si sblocca solo dopo submit della scheda precedente |
| `redirect_after_submit` | bool | Redirige alla scheda successiva dopo invio |

### Tipi di visibility_condition:

| type | depends_on | Significato |
|------|------------|-------------|
| `always` | - | Sempre visibile |
| `after_step_saved` | scheda_key | Visibile dopo che la scheda indicata e in stato draft o submitted |
| `after_step_submitted` | scheda_key | Visibile dopo che la scheda indicata e in stato submitted |
| `after_all_previous_submitted` | - | Visibile solo dopo che TUTTE le schede precedenti sono submitted |

---

## 8. Tabella `notification_rules` (Regole Notifiche)

```sql
CREATE TABLE notification_rules (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_name   VARCHAR(255) NOT NULL,
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    events      TEXT,       -- JSON array di eventi trigger
    channels    TEXT,       -- JSON canali abilitati
    recipients  TEXT,       -- JSON destinatari
    messages    TEXT,       -- JSON template messaggi
    created_at  DATETIME,
    updated_at  DATETIME,
    UNIQUE KEY uk_form_name (form_name)
);
```

### Struttura JSON dei campi:

**events** (array):
```json
["on_submit", "on_status_change", "on_assignment_change"]
```

**channels** (object):
```json
{"in_app": true, "email": true}
```

**recipients** (object):
```json
{
  "responsabile": true,
  "assegnatario": true,
  "autore": true,
  "custom_email": false,
  "custom_email_value": "esempio@dominio.it"
}
```

**messages** (object):
```json
{
  "in_app_message": "Nuova segnalazione: {titolo}",
  "email_subject": "[{protocollo}] {titolo}",
  "email_body": "Ciao, e stata creata la segnalazione {titolo} da {autore}.",
  "email_template": "base_template"
}
```

### Placeholder disponibili:
`{id}`, `{titolo}`, `{descrizione}`, `{autore}`, `{attore}`, `{now}`, `{link}`, `{protocollo}`, `{record_table}`, `{qualsiasi_campo_del_form}`

---

## 9. Tabella `menu_custom` (Voci Menu)

Collega ogni modulo al menu di navigazione.

```sql
CREATE TABLE menu_custom (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    section       VARCHAR(255),         -- sezione menu (es. "collaborazione", "gestione")
    parent_title  VARCHAR(255),         -- titolo menu padre (es. "Segnalazioni")
    title         VARCHAR(255),         -- titolo voce (es. "IT - Segnalazioni")
    link          VARCHAR(500),         -- URL: "index.php?section=X&page=gestione_segnalazioni&form_name=NOME"
    attivo        TINYINT(1) DEFAULT 1, -- voce visibile
    ordinamento   INT DEFAULT 0         -- ordine nel menu
);
```

### Formato link standard:
```
index.php?section={section}&page=gestione_segnalazioni&form_name={form_name}
```

---

## 10. Tabella `notification_logs` (Log Notifiche)

Audit trail di tutte le notifiche inviate.

```sql
CREATE TABLE notification_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    form_name      VARCHAR(255),
    event_type     VARCHAR(100),    -- on_submit, on_status_change, on_assignment_change
    recipient      VARCHAR(255),    -- email o user_id
    channel        VARCHAR(50),     -- in_app, email
    subject        VARCHAR(500),
    body           TEXT,
    status         VARCHAR(50),     -- sent, failed
    error_message  TEXT NULL,
    created_at     DATETIME
);
```

---

## 11. Tabella `sys_db_whitelist` (Whitelist Datasource)

Controlla quali tabelle DB sono accessibili come sorgente dati per campi dbselect.

```sql
CREATE TABLE sys_db_whitelist (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    table_name       VARCHAR(255) NOT NULL,
    is_active        TINYINT(1) DEFAULT 1,
    visible_columns  TEXT NULL,    -- JSON array di colonne esposte: ["id", "nome", "email"]
    UNIQUE KEY (table_name)
);
```

---

## 12. Relazioni tra Entita

```
forms (1) ---- (*) form_fields         [un modulo ha molti campi]
forms (1) ---- (*) form_states         [un modulo ha molti stati]
forms (1) ---- (1) mod_{nome}          [un modulo ha una tabella dati]
forms (1) ---- (*) form_schede_status  [tracking per-scheda per-record]
forms (1) ---- (0..1) notification_rules [regola notifiche opzionale]
forms (1) ---- (0..1) menu_custom      [voce menu opzionale]

mod_{nome} (1) ---- (*) form_values    [EAV per campi senza colonna]
mod_{nome} (1) ---- (*) form_schede_status [stato compilazione schede]

form_fields ---- parent_section_uid --> form_fields  [annidamento sezione]
form_states.id ---- mod_{nome}.status_id             [stato corrente record]
```

---

## 13. Formato field_options per tipo

### `select`, `checkbox`, `radio`:
```json
["Opzione 1", "Opzione 2", "Opzione 3"]
```

### `dbselect`:
```json
{
  "table": "personale",
  "valueCol": "user_id",
  "labelCol": "Nominativo",
  "multiple": 0,
  "q": "",
  "limit": 200
}
```

### `section`:
```json
{
  "label": "Nome Sezione",
  "uid": "sec_nome_sezione_0"
}
```
I campi figli hanno `parent_section_uid = "sec_nome_sezione_0"`.

### `text`, `textarea`, `date`, `file`:
```json
null
```
(Nessuna opzione aggiuntiva)

---

## 14. Campi Fissi (Fixed Fields)

5 campi sempre presenti in ogni modulo, creati automaticamente:

| field_name | field_type | DDL | field_options | required | tab_label |
|------------|-----------|-----|---------------|----------|-----------|
| titolo | text | VARCHAR(255) | null | 1 | Struttura |
| descrizione | textarea | TEXT | null | 1 | Struttura |
| deadline | date | DATE | null | 1 | Struttura |
| priority | select | VARCHAR(255) | `["Bassa","Media","Alta"]` | 0 | Struttura |
| assegnato_a | dbselect | VARCHAR(255) | `{"table":"personale","valueCol":"user_id","labelCol":"Nominativo"}` | 0 | Struttura |

Questi campi hanno `is_fixed=1` e non vengono mai eliminati da `saveFormStructure`.
