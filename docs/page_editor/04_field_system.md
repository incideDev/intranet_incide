# Page Editor - Field System & Type Specifications

Documento tecnico: tipi di campo, validazione, comportamento, datasource, sezioni.
**Nessun riferimento a UI/design** - solo specifica funzionale dei campi.

---

## 1. Struttura Dati di un Campo

Ogni campo in memoria (frontend) ha questa struttura:

```javascript
{
  uid: string,                // ID univoco interno: "field_" + timestamp + "_" + random
  name: string,               // Nome colonna DB (snake_case, auto-generato da label)
  label: string,              // Etichetta leggibile (preserva accenti e spazi)
  type: string,               // Uno dei 9 tipi supportati
  options: array,             // Per select/checkbox/radio: lista opzioni
  datasource: object|null,    // Per dbselect: configurazione sorgente dati
  multiple: boolean,          // Per dbselect: selezione multipla
  is_fixed: boolean,          // true = campo fisso non rimovibile
  colspan: 1|2,               // 1 = meta larghezza, 2 = larghezza piena
  allow_custom: boolean,      // Per select: permetti valore "Altro"
  required: boolean,          // Campo obbligatorio
  placeholder: string,        // Testo placeholder
  children: array,            // Per section: campi annidati
  parent_section_uid: string|null  // Se dentro una sezione: UID del padre
}
```

### Persistenza (form_fields):
```
uid            -> non persistito (rigenerato al caricamento)
name           -> field_name
label          -> field_label
type           -> field_type
options        -> field_options (JSON)
datasource     -> field_options (JSON, per dbselect)
required       -> required
is_fixed       -> is_fixed
colspan        -> colspan
placeholder    -> field_placeholder
children       -> non persistiti direttamente (ricostruiti da parent_section_uid)
parent_section_uid -> parent_section_uid
```

---

## 2. Tipi di Campo Supportati

### 2.1 `text` - Campo Testo

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `VARCHAR(255) NULL` |
| field_options | `null` |
| Validazione | Nessuna specifica |
| Valore salvato | Stringa |

**Comportamento**: Input testo a riga singola. Accetta qualsiasi stringa fino a 255 caratteri.

---

### 2.2 `textarea` - Area Testo

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `TEXT NULL` |
| field_options | `null` |
| Validazione | Nessuna specifica |
| Valore salvato | Stringa (testo lungo) |

**Comportamento**: Input testo multiriga. Nessun limite pratico di lunghezza (TEXT MySQL).

---

### 2.3 `select` - Selezione Singola

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `VARCHAR(255) NULL` |
| field_options | `["Opzione 1", "Opzione 2", "Opzione 3"]` |
| Validazione | Valore deve essere tra le opzioni (o custom se allow_custom) |
| Valore salvato | Stringa (opzione selezionata) |

**Comportamento**:
- L'utente sceglie UNA opzione da una lista predefinita
- Se `allow_custom = true`: l'utente puo inserire un valore libero ("Altro")
- Opzioni memorizzate come array JSON in field_options
- Se nessuna opzione configurata, viene creata una default: `["opzione 1"]`

**Cambio tipo**:
- Se si cambia DA un altro tipo A select: viene creato `options = []` (array vuoto)
- Eventuale `datasource` viene rimosso

---

### 2.4 `checkbox` - Checkbox (Selezione Multipla)

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `TEXT NULL` |
| field_options | `["Scelta A", "Scelta B", "Scelta C"]` |
| Validazione | Nessuna specifica |
| Valore salvato | JSON array delle scelte selezionate: `["Scelta A", "Scelta C"]` |

**Comportamento**:
- L'utente puo selezionare ZERO o PIU opzioni
- Opzioni memorizzate come array JSON in field_options
- Valore salvato come JSON array nel record

---

### 2.5 `radio` - Radio Button (Selezione Esclusiva)

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `VARCHAR(255) NULL` |
| field_options | `["Opzione A", "Opzione B"]` |
| Validazione | Valore deve essere tra le opzioni |
| Valore salvato | Stringa (opzione selezionata) |

**Comportamento**:
- L'utente sceglie UNA opzione tra quelle disponibili
- Simile a select ma con presentazione diversa (logicamente identico)
- Opzioni memorizzate come array JSON

---

### 2.6 `file` - Upload File

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `VARCHAR(512) NULL` |
| field_options | `null` |
| Validazione | Max 1 campo file per scheda |
| Valore salvato | Path relativo del file: `"uploads/{section}/{filename}"` |

**Comportamento**:
- Upload singolo file per campo
- **Vincolo: massimo 1 campo file per scheda** (validato in `applyFieldTypeChange`)
- File inviato via FormData (non JSON)
- Backend salva il file su disco e memorizza il path
- Path relativo salvato nella colonna o in EAV

**Vincolo cambio tipo**:
- Se si cerca di cambiare un campo a `file` e la scheda ha gia un campo file -> **RIFIUTATO**

---

### 2.7 `date` - Data

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `DATE NULL` |
| field_options | `null` |
| Validazione | Formato data valido |
| Valore salvato | Stringa ISO: `"2026-03-24"` |

**Comportamento**: Selezione data. Formato di salvataggio: `YYYY-MM-DD`.

---

### 2.8 `dbselect` - Selezione da Database

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | `VARCHAR(255) NULL` |
| field_options | `{"table": "...", "valueCol": "...", "labelCol": "...", "multiple": 0, "q": "", "limit": 200}` |
| Validazione | Tabella deve essere in whitelist |
| Valore salvato | ID singolo (`"5"`) o comma-separated (`"3,7,12"`) se multiple |

**Comportamento**:
- Le opzioni vengono caricate dinamicamente da una tabella del database
- La tabella sorgente deve essere registrata in `sys_db_whitelist` con `is_active=1`
- L'utente seleziona un record dalla tabella sorgente

**Configurazione datasource**:
```javascript
{
  table: string,       // Nome tabella sorgente (es. "personale")
  valueCol: string,    // Colonna per il valore (es. "user_id")
  labelCol: string,    // Colonna per l'etichetta (es. "Nominativo")
  multiple: 0|1,       // 0 = singola selezione, 1 = selezione multipla
  q: string,           // Query personalizzata (opzionale, raramente usato)
  limit: number        // Max righe da caricare (default: 200)
}
```

**Caricamento opzioni (runtime)**:
```
Frontend -> customFetch('datasource', 'getOptions', {table, valueCol, labelCol})
Backend  -> DatasourceService::getOptions()
           -> Verifica whitelist
           -> SELECT DISTINCT valueCol, labelCol FROM table ORDER BY labelCol LIMIT 200
           -> Ritorna [{v: "1", l: "Mario Rossi"}, ...]
```

**Risoluzione valori salvati**:
```
Frontend -> customFetch('datasource', 'resolveDbselectValues', {
             fields: [{table, valueCol, labelCol, value: "3,7"}]
           })
Backend  -> Ritorna: {resolved: {"0": "Mario Rossi, Luca Bianchi"}}
```

**Cambio tipo**:
- Se si cambia DA un altro tipo A dbselect: viene creato `datasource = {table:'', valueCol:'', labelCol:''}` (vuoto)
- Eventuale `options` array viene rimosso

**Sicurezza whitelist**:
- Solo tabelle registrate in `sys_db_whitelist` sono accessibili
- Se `visible_columns` e configurato, solo quelle colonne sono usabili come valueCol/labelCol

---

### 2.9 `section` - Sezione (Container)

| Proprieta | Valore |
|-----------|--------|
| DDL colonna | Nessuna (non e un campo dati) |
| field_name | `__section__{uid}` |
| field_options | `{"label": "Nome Sezione", "uid": "sec_nome_sezione_0"}` |
| Validazione | UID unico |
| Valore salvato | Nessuno (container logico) |

**Comportamento**:
- NON e un campo dati: non crea colonne, non salva valori
- E un **container** che raggruppa altri campi visualmente
- Ha `children[]` in memoria che contengono i campi annidati
- I campi figli hanno `parent_section_uid = sezione.uid`

**UID Sezione**:
- Formato: `sec_{label_slugificata}_{indice}`
- Es: `sec_informazioni_generali_0`, `sec_dati_tecnici_1`
- Deve essere unico nell'intero modulo

**Persistenza**:
- La sezione stessa e salvata come riga in form_fields con `field_name = '__section__{uid}'`
- I campi figli sono salvati come righe separate con `parent_section_uid = uid`
- Al caricamento, `buildSectionHierarchy()` ricostruisce la struttura annidata

**Logica posizionamento**:
- La sezione viene automaticamente posizionata prima del suo primo figlio (in sort_order)

**`colspan` sezione**: Sempre 2 (larghezza piena, il container occupa tutta la riga)

---

## 3. Campi Fissi

5 campi sempre presenti, creati automaticamente da `ensureFixedFields`:

### 3.1 `titolo`
- **type**: text
- **DDL**: VARCHAR(255) NOT NULL
- **required**: true
- **tab_label**: Struttura
- **Scopo**: Titolo/oggetto del record

### 3.2 `descrizione`
- **type**: textarea
- **DDL**: TEXT NOT NULL
- **required**: true
- **tab_label**: Struttura
- **Scopo**: Descrizione dettagliata

### 3.3 `deadline`
- **type**: date
- **DDL**: DATE NULL
- **required**: true
- **tab_label**: Struttura
- **Scopo**: Data di scadenza

### 3.4 `priority`
- **type**: select
- **DDL**: VARCHAR(255) DEFAULT 'Media'
- **options**: `["Bassa", "Media", "Alta"]`
- **required**: false
- **tab_label**: Struttura
- **Scopo**: Livello di priorita

### 3.5 `assegnato_a`
- **type**: dbselect
- **DDL**: VARCHAR(255) NULL
- **datasource**: `{"table": "personale", "valueCol": "user_id", "labelCol": "Nominativo"}`
- **required**: false
- **tab_label**: Struttura
- **Scopo**: Utente/i assegnati al record (comma-separated IDs)

### Regole campi fissi:
- `is_fixed = 1` in form_fields
- Non possono essere cancellati da `saveFormStructure` (filtrati con skip)
- Non possono essere spostati su altre schede (sempre su "Struttura")
- L'utente non li aggiunge manualmente: sono sempre presenti
- Non appaiono nella palette dei campi trascinabili

---

## 4. Campi Esito (Scheda Chiusura)

Campi speciali presenti nelle schede con `isClosureTab=true`:

### 4.1 Campi colonna nella tabella mod_*:

| Campo | Tipo DB | Valori Ammessi |
|-------|---------|----------------|
| `esito_stato` | VARCHAR(50) | `"accettata"`, `"in_valutazione"`, `"rifiutata"` |
| `esito_note` | TEXT | Testo libero |
| `esito_data_prevista` | DATE | Data prevista chiusura |
| `esito_data` | DATETIME | Data effettiva chiusura (auto-settata a NOW) |

### 4.2 Campi aggiuntivi (da meta_esito):

| Campo | Descrizione |
|-------|-------------|
| `assegnato_a_esito` | Riassegnazione durante chiusura (sincronizzato con assegnato_a) |
| `priorita_esito` | Nuova priorita |
| `stato_esito` | Nuovo stato |

---

## 5. Logica Cambio Tipo Campo

Quando l'utente cambia il tipo di un campo (`applyFieldTypeChange`):

| Da \ A | text | textarea | select | checkbox | radio | file | date | dbselect | section |
|--------|------|----------|--------|----------|-------|------|------|----------|---------|
| **text** | - | OK | +opts | +opts | +opts | check1 | OK | +ds | NO |
| **textarea** | OK | - | +opts | +opts | +opts | check1 | OK | +ds | NO |
| **select** | -opts | -opts | - | OK | OK | check1 | -opts | +ds-opts | NO |
| **checkbox** | -opts | -opts | OK | - | OK | check1 | -opts | +ds-opts | NO |
| **radio** | -opts | -opts | OK | OK | - | check1 | -opts | +ds-opts | NO |
| **file** | OK | OK | +opts | +opts | +opts | - | OK | +ds | NO |
| **date** | OK | OK | +opts | +opts | +opts | check1 | - | +ds | NO |
| **dbselect** | -ds | -ds | +opts-ds | +opts-ds | +opts-ds | check1 | -ds | - | NO |
| **section** | NO | NO | NO | NO | NO | NO | NO | NO | - |

Legenda:
- **OK**: Cambio diretto senza modifiche
- **+opts**: Crea array `options` (inizializzato a `[]`)
- **-opts**: Rimuove `options`
- **+ds**: Crea oggetto `datasource` con campi vuoti
- **-ds**: Rimuove `datasource`
- **check1**: Verifica che non esista gia un campo file nella scheda. Se esiste -> RIFIUTATO
- **NO**: Conversione non permessa (section e un tipo speciale)

---

## 6. Colspan e Layout Griglia

### 6.1 Regole Colspan

| Tipo campo | colspan default | colspan ammessi |
|------------|----------------|-----------------|
| text | 1 | 1, 2 |
| textarea | 2 | 1, 2 |
| select | 1 | 1, 2 |
| checkbox | 1 | 1, 2 |
| radio | 1 | 1, 2 |
| file | 1 | 1, 2 |
| date | 1 | 1, 2 |
| dbselect | 1 | 1, 2 |
| section | 2 (forzato) | Solo 2 |

### 6.2 Logica Griglia

La griglia e a **2 colonne**:
- `colspan=1`: il campo occupa meta riga (1 colonna)
- `colspan=2`: il campo occupa tutta la riga (2 colonne)
- La sezione occupa sempre tutta la riga

Il colspan puo essere cambiato durante il drag & drop:
- Tenere **Alt** durante il drop -> forza `colspan=2` (larghezza piena)

---

## 7. Drag & Drop - Modello Dati

Il sistema drag & drop opera su tre livelli di dati:

### 7.1 Da Palette a Preview (nuovo campo)

**Dati trasferiti**:
```
field-type: string        // tipo campo (es. "text", "select")
pe-origin: "fields"       // identifica origine palette
```

**Campo creato**:
```javascript
{
  uid: "field_" + Date.now() + "_" + Math.random()...,
  name: "",               // vuoto, verra generato dal label
  label: "",              // vuoto, l'utente lo inserira
  type: tipoScelto,
  options: tipoDiLista ? ["opzione 1"] : [],
  datasource: null,
  is_fixed: false,
  colspan: (altKeyPremuto ? 2 : 1)
}
```

### 7.2 Riordino Interno (campo esistente)

**Dati trasferiti**:
```
pe-dragged-uid: string    // UID del campo mosso
pe-internal: "1"          // flag riordino interno
```

**Effetto**: Il campo viene rimosso dalla posizione attuale e inserito nella nuova posizione. L'array `fields` viene riordinato.

### 7.3 Riordino dentro Sezione

**Dati trasferiti**:
```
pe-dragged-child-uid: string   // UID del campo figlio mosso
pe-internal-sec: "1"           // flag riordino sezione
```

**Effetto**: Il campo viene spostato dentro `section.children[]`. L'ordine dei figli viene ricalcolato dal DOM.

---

## 8. Serializzazione per Salvataggio

Al momento del salvataggio, i campi vengono serializzati in formato piatto:

### 8.1 Campo Normale

```javascript
// In memoria (frontend)
{ uid: "f1", name: "telefono", label: "Telefono", type: "text", colspan: 1 }

// Serializzato per API
{
  field_name: "telefono",
  field_label: "Telefono",
  field_type: "text",
  field_options: null,
  is_fixed: 0,
  sort_order: 10,
  colspan: 1,
  parent_section_uid: null,
  tab_label: "Struttura"
}
```

### 8.2 Campo Select con Opzioni

```javascript
// In memoria
{ uid: "f2", name: "tipo", label: "Tipo", type: "select", options: ["Bug", "Feature", "Altro"] }

// Serializzato
{
  field_name: "tipo",
  field_label: "Tipo",
  field_type: "select",
  field_options: ["Bug", "Feature", "Altro"],
  is_fixed: 0,
  sort_order: 20,
  colspan: 1,
  parent_section_uid: null,
  tab_label: "Struttura"
}
```

### 8.3 Campo DbSelect

```javascript
// In memoria
{ uid: "f3", name: "reparto", type: "dbselect", datasource: {table: "reparti", valueCol: "id", labelCol: "nome"}, multiple: false }

// Serializzato
{
  field_name: "reparto",
  field_label: "Reparto",
  field_type: "dbselect",
  field_options: {"table": "reparti", "valueCol": "id", "labelCol": "nome", "multiple": 0},
  is_fixed: 0,
  sort_order: 30,
  colspan: 1,
  parent_section_uid: null,
  tab_label: "Struttura"
}
```

### 8.4 Sezione con Figli

```javascript
// In memoria
{
  uid: "s1", name: "__section__sec_info_0", type: "section", label: "Informazioni",
  children: [
    { uid: "f4", name: "nome", type: "text", parent_section_uid: "sec_info_0" },
    { uid: "f5", name: "cognome", type: "text", parent_section_uid: "sec_info_0" }
  ]
}

// Serializzato (3 righe piatte)
[
  {
    field_name: "__section__sec_info_0",
    field_type: "section",
    field_options: {"label": "Informazioni", "uid": "sec_info_0"},
    sort_order: 30,
    colspan: 2,
    parent_section_uid: null,
    tab_label: "Struttura"
  },
  {
    field_name: "nome",
    field_type: "text",
    sort_order: 40,
    colspan: 1,
    parent_section_uid: "sec_info_0",
    tab_label: "Struttura"
  },
  {
    field_name: "cognome",
    field_type: "text",
    sort_order: 50,
    colspan: 1,
    parent_section_uid: "sec_info_0",
    tab_label: "Struttura"
  }
]
```

---

## 9. Ricostruzione Gerarchia (Caricamento)

Quando i campi vengono caricati dal backend (`buildSectionHierarchy`):

```
Input (piatto dal DB):
[
  { field_name: "__section__sec_info_0", field_type: "section", field_options: {"uid": "sec_info_0"} },
  { field_name: "nome", field_type: "text", parent_section_uid: "sec_info_0" },
  { field_name: "cognome", field_type: "text", parent_section_uid: "sec_info_0" },
  { field_name: "email", field_type: "text", parent_section_uid: null }
]

Output (gerarchico):
[
  {
    type: "section", uid: "sec_info_0", label: "Informazioni",
    children: [
      { name: "nome", type: "text" },
      { name: "cognome", type: "text" }
    ]
  },
  { name: "email", type: "text" }
]
```

Algoritmo:
1. Separa sezioni (field_name inizia con `__section__`) dai campi normali
2. Per ogni campo con `parent_section_uid` non null: aggiungilo ai `children[]` della sezione corrispondente
3. Campi senza `parent_section_uid`: restano al livello root
4. Ordina tutto per `sort_order`

---

## 10. Validazione Completa

### 10.1 Validazione Client-Side (pre-salvataggio)

| Controllo | Errore |
|-----------|--------|
| Nomi campo duplicati tra schede | "Campo X duplicato" |
| Max 1 file per scheda | "Solo un campo file per scheda" |
| Tipo campo valido | "Tipo non riconosciuto" |
| Label non vuota | (campo senza label ignorato/generato) |
| DbSelect senza datasource | (warning, non bloccante) |

### 10.2 Validazione Backend (saveFormStructure)

| Controllo | Codice Errore |
|-----------|---------------|
| form_name vuoto | VALIDATION_ERROR |
| fields non array/JSON invalido | JSON_INVALID |
| Sezione senza uid | VALIDATION_ERROR |
| UID sezione duplicato | (deduplicato silenziosamente) |
| form_name non esistente | DB_ERROR |

### 10.3 Validazione Backend (submitScheda)

| Controllo | Errore |
|-----------|--------|
| form_name mancante | "form_name richiesto" |
| record_id <= 0 | "record_id richiesto" |
| scheda_key mancante | "scheda_key richiesto" |
| scheda_key == 'struttura' | "Scheda struttura non modificabile" |
| Utente senza permesso | "Permesso negato" |
| Scheda gia submitted (per non-manager) | "Scheda gia inviata" |
