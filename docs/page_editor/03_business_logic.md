# Page Editor - Business Logic & Workflows

Documento tecnico: regole di business, macchina a stati, workflow multi-scheda, permessi, notifiche.
**Nessun riferimento a UI/design** - solo logica pura.

---

## 1. Ciclo di Vita di un Modulo

```
CREAZIONE -> CONFIGURAZIONE -> PUBBLICAZIONE -> USO -> (ELIMINAZIONE)
```

### 1.1 Creazione
- Trigger: azione `ensureForm` con nome modulo
- Risultato:
  - Record in `forms` con metadati
  - Tabella `mod_{nome}` creata con colonne base
  - 5 campi fissi inseriti in `form_fields`
  - Codice protocollo generato (univoco)

### 1.2 Configurazione
- Aggiunta/rimozione campi dinamici
- Configurazione schede (tab) con regole di visibilita/editing
- Configurazione stati Kanban personalizzati
- Configurazione regole notifiche
- Assegnazione responsabili

### 1.3 Pubblicazione
- Creazione voce nel menu laterale via `menu_custom.upsert`
- Il modulo diventa accessibile dagli utenti con permesso `page_editor_form_view:{form_id}`

### 1.4 Uso
- Utenti compilano nuovi record (view_form)
- Record visibili in tabella listing
- Workflow multi-scheda con submit progressivo
- Chiusura tramite scheda Esito

---

## 2. Ciclo di Vita di un Record

```
NUOVO -> COMPILAZIONE -> SUBMITTED -> WORKFLOW SCHEDE -> ESITO -> CHIUSO
```

### 2.1 Creazione Record
1. Utente accede a `view_form` (pagina intera, non modale)
2. Compila campi fissi + dinamici della scheda "Struttura"
3. Click "Salva" -> `submitScheda` con `scheda_key='struttura'`
4. Backend: INSERT in `mod_{nome}`, genera `codice_segnalazione`
5. Redirect a `form_viewer` con `id` del nuovo record

### 2.2 Workflow Multi-Scheda
Dopo la creazione, il record attraversa le schede configurate:

```
Struttura (utente) -> Dettagli (responsabile) -> Esito (chiusura)
         |                    |                      |
    submitted           submitted               submitted
         |                    |                      |
   [readonly]          [readonly]              [readonly]
```

Ogni scheda:
- Ha un tipo (`scheda_type`): determina CHI puo compilarla
- Ha condizioni di visibilita: determina QUANDO appare
- Una volta submitted: diventa readonly per l'autore

### 2.3 Chiusura (Esito)
- Scheda con `isClosureTab=true`
- Campi speciali: esito_stato, esito_note, esito_data
- Valori esito: `accettata`, `in_valutazione`, `rifiutata`
- Dopo submit: record considerato "chiuso"

---

## 3. Sistema Permessi

### 3.1 Permessi di Accesso

| Livello | Permesso | Chi |
|---------|----------|-----|
| Creazione moduli | `view_moduli` | Admin e gestori intranet |
| Accesso a un modulo | `page_editor_form_view:{form_id}` | Per modulo specifico |
| Admin bypass | `role_id = 1` | Accesso completo a tutto |

### 3.2 Ruoli nel Contesto di un Record

Per ogni record, un utente ha UNO di questi ruoli (determinato a runtime):

| Ruolo | Come si determina |
|-------|-------------------|
| `admin` | `role_id = 1` (ruolo di sistema) |
| `responsabile` | `user_id` presente in `forms.responsabile` (comma-separated) |
| `assegnatario` | `user_id` presente in `mod_{nome}.assegnato_a` del record |
| `utente` | Tutti gli altri (incluso il creatore del record) |

**Priorita**: admin > responsabile > assegnatario > utente

### 3.3 Matrice Permessi per Scheda

La scheda ha un `scheda_type` che definisce le regole:

**Scheda tipo `utente`** (es. "Struttura"):
| Ruolo | Visibilita | Editing |
|-------|-----------|---------|
| admin | Sempre | Sempre |
| responsabile | Sempre | Mai (sola lettura) |
| assegnatario | Sempre | Mai (sola lettura) |
| utente (creatore) | Sempre | Solo se non ancora submitted |

**Scheda tipo `responsabile`** (es. "Dettagli"):
| Ruolo | Visibilita | Editing |
|-------|-----------|---------|
| admin | Sempre | Sempre |
| responsabile | Se in visibility_roles | Solo se non ancora submitted |
| assegnatario | Se in visibility_roles | Solo se non ancora submitted |
| utente | Se in visibility_roles (tipicamente no) | Mai |

**Scheda tipo `chiusura`** (es. "Esito"):
| Ruolo | Visibilita | Editing |
|-------|-----------|---------|
| admin | Sempre | Sempre |
| responsabile | Dopo unlock (schede precedenti submitted) | Solo se non ancora submitted |
| assegnatario | Dopo unlock | Solo se non ancora submitted |
| utente | Dopo che il responsabile ha submitted | Mai (solo visualizzazione) |

### 3.4 Regola Immutabilita

**Principio fondamentale**: una volta che una scheda e `submitted`, diventa readonly per chi l'ha compilata.
- Scheda utente submitted -> utente non puo piu modificare
- Scheda responsabile submitted -> responsabile non puo piu modificare
- Admin: eccezione, puo sempre modificare tutto

---

## 4. Macchina a Stati delle Schede

### 4.1 Stati di una Scheda (per record)

```
                    submitScheda()
not_started -----> draft -----> submitted
                     |              |
                     |              v
                     +--------> READONLY
```

| Stato | Significato |
|-------|-------------|
| `not_started` | Nessuna interazione con questa scheda |
| `draft` | Salvata ma non confermata (riservato, attualmente non usato) |
| `submitted` | Confermata - diventa readonly per l'autore |

### 4.2 Condizioni di Visibilita

Le condizioni controllano QUANDO una scheda diventa visibile:

**`always`**: Sempre visibile (nessuna dipendenza)

**`after_step_saved`**: Visibile dopo che la scheda `depends_on` e almeno `draft`
```
if schedeStatus[depends_on].status IN ('draft', 'submitted') -> visibile
```

**`after_step_submitted`**: Visibile dopo che la scheda `depends_on` e `submitted`
```
if schedeStatus[depends_on].status === 'submitted' -> visibile
```

**`after_all_previous_submitted`**: Visibile solo dopo che TUTTE le schede precedenti (in ordine) sono `submitted`
```
for each previousTab in orderedTabs:
  if schedeStatus[previousTab].status !== 'submitted' -> nascosta
```

### 4.3 Logica Unlock

`unlock_after_submit_prev = true` aggiunge un ulteriore vincolo:
- La scheda precedente (nell'ordine dei tab) deve essere `submitted`
- Se non lo e, la scheda e visibile ma bloccata (locked)

### 4.4 Algoritmo Completo di Visibilita

```
calculateSchedaVisibility(tabConfig, context):

  1. Se admin -> { visible: true, editable: true }

  2. Determina ruolo utente:
     - userId in responsabili_ids -> "responsabile"
     - userId in assegnatari_ids -> "assegnatario"
     - else -> "utente"

  3. Controlla canViewTab(tabConfig, ruolo):
     - Se ruolo non in visibility_roles -> { visible: false }
     - Se scheda_type == 'chiusura' e non submitted e utente -> { visible: false }

  4. Controlla visibility_condition:
     - ALWAYS -> passa
     - AFTER_STEP_SAVED -> controlla depends_on in (draft, submitted)
     - AFTER_STEP_SUBMITTED -> controlla depends_on == submitted
     - AFTER_ALL_PREVIOUS -> controlla tutte le precedenti == submitted

  5. Controlla canEditTab:
     - Se scheda gia submitted dall'utente -> editable: false
     - Se scheda_type non compatibile con ruolo -> editable: false
     - (vedi matrice sezione 3.3)

  6. Ritorna { visible, editable, locked, reason }
```

---

## 5. Workflow di Creazione/Modifica Struttura

### 5.1 Flusso Salvataggio Struttura

```
[Frontend: serializza tabState]
     |
     v
[Validazione client-side]
  - Nomi campo univoci
  - Max 1 file per scheda
  - Tipi validi
     |
     v
[AJAX: saveFormStructure]
     |
     v
[Backend: transazione]
  1. ensureFixedFields (idempotente)
  2. Per ogni tab nel payload:
     a. DELETE form_fields WHERE tab_label=X AND is_fixed=0
     b. INSERT nuovi campi
  3. ALTER TABLE per colonne mancanti
  4. Merge tabs_config
  5. COMMIT
```

### 5.2 Generazione Nomi Campo

La funzione `claim(desiderato, nomeCorrente)`:
1. Se il campo ha gia un nome valido -> lo mantiene
2. Altrimenti genera dal label: `label.toLowerCase().replace(/[^a-z0-9_]/g, '_')`
3. Se duplicato: appende `_2`, `_3`, etc.
4. Tracker globale `usedNames` previene collisioni

### 5.3 Gestione Sezioni

Le sezioni sono campi speciali (`type='section'`):
- `field_name = '__section__{uid}'`
- Hanno `children[]` che contengono campi annidati
- I campi figli hanno `parent_section_uid = sezione.uid`
- Al salvataggio: la sezione viene posizionata automaticamente prima del suo primo figlio

### 5.4 Deduplicazione

Durante il salvataggio, i campi vengono deduplicati per-tab sulla chiave naturale:
- `(field_name, parent_section_uid)` -> se duplicato, il primo vince
- Sezioni deduplicate per UID

---

## 6. Workflow di Compilazione Record

### 6.1 Nuovo Record

```
1. Utente naviga a: index.php?section=X&page=view_form&form_name=NOME
2. Frontend carica: customFetch('page_editor', 'getForm', {form_name})
3. Riceve: form metadata + tabs con campi
4. Utente compila scheda "Struttura"
5. Submit: customFetch('page_editor', 'submitScheda', {
     form_name, record_id: 0, scheda_key: 'struttura', values: {...}
   })
6. Backend: INSERT in mod_{nome}, restituisce id
7. Redirect a: form_viewer?form_name=NOME&id=ID
```

### 6.2 Record Esistente (Workflow)

```
1. Utente accede a form_viewer con id
2. Frontend carica: customFetch('page_editor', 'getForm', {form_name, record_id})
3. Riceve: form + tabs + entry (dati salvati)
4. Per ogni scheda: calcola visibilita/editabilita
5. Schede visibili ed editabili -> l'utente puo fare submit
6. Submit: customFetch('page_editor', 'submitScheda', {
     form_name, record_id, scheda_key, values
   })
7. Backend: UPDATE colonne, aggiorna form_schede_status
8. Se redirect_after_submit -> naviga alla scheda successiva
```

### 6.3 Navigazione Schede

Dopo submit di una scheda con `redirect_after_submit=true`:
1. Chiama `calculateNextScheda` con contesto utente
2. Backend scorre le schede in ordine cercando la prima visibile+editabile
3. Se trovata -> redirect client-side alla scheda successiva
4. Se ultima -> rimane sulla scheda corrente

---

## 7. Sistema Notifiche

### 7.1 Trigger Events

| Evento | Quando scatta | Dati disponibili |
|--------|---------------|-----------------|
| `on_submit` | submitScheda completato | record completo, autore, attore |
| `on_status_change` | updateEsito o cambio status_id | vecchio/nuovo stato, record |
| `on_assignment_change` | cambio assegnato_a | vecchio/nuovo assegnatario |

### 7.2 Flusso di Elaborazione

```
1. Evento trigger (es. submitScheda)
2. NotificationService::processRules(formName, eventType, recordData)
3. Legge notification_rules per il form
4. Se enabled=false -> stop
5. Filtra per evento attivo
6. Per ogni canale attivo (in_app, email):
   a. Risolve destinatari:
      - responsabile -> cerca user_id in forms.responsabile
      - assegnatario -> cerca user_id in record.assegnato_a
      - autore -> cerca user_id in record.submitted_by
      - custom_email -> usa email diretta
   b. Sostituisce placeholder nel template:
      - {id}, {titolo}, {descrizione}, {now}, {autore}, {attore}, {link}, {protocollo}
      - {qualsiasi_campo}: valore dal record
   c. Invia:
      - in_app: NotificationService::inviaNotifica (tabella notifiche)
      - email: PHPMailer via SMTP (.env config)
   d. Logga in notification_logs
```

### 7.3 Risoluzione Destinatari

```
responsabile:
  forms.responsabile (comma-separated IDs)
  -> cerca email in personale WHERE user_id IN (...)

assegnatario:
  mod_{nome}.assegnato_a (comma-separated IDs)
  -> cerca email in personale WHERE user_id IN (...)

autore:
  mod_{nome}.submitted_by (single ID)
  -> cerca email in personale WHERE user_id = ...

custom_email:
  notification_rules.recipients.custom_email_value (email diretta)
```

---

## 8. Storage Ibrido (Fisico + EAV)

### 8.1 Regola di Priorita

Per ogni campo da salvare in `submitScheda`:

```
if (colonna_esiste_nella_tabella(campo)) {
  -> UPDATE mod_{nome} SET campo = valore WHERE id = record_id
  -> conteggio: updated_fields++
} else {
  -> INSERT/UPDATE form_values (form_id, record_id, field_name, field_value)
  -> conteggio: eav_fields++
}
```

### 8.2 Quando si crea una colonna fisica

`addColumnIfMissing` viene chiamato durante `saveFormStructure` per:
- Ogni campo dinamico del payload
- Tipo DDL mappato da field_type (VARCHAR, TEXT, DATE, etc.)

### 8.3 Quando si usa EAV

- Campo aggiunto dopo che la tabella aveva gia record
- ALTER TABLE fallito (silenzioso)
- Campo con nome non valido per colonna SQL

---

## 9. Gestione File

### 9.1 Vincoli
- **Massimo 1 campo file per scheda** (validato client-side in `applyFieldTypeChange`)
- File caricati via FormData (non JSON)

### 9.2 Flusso Upload

```
1. Frontend invia FormData con il file
2. Backend: FormsDataService::handleUploadPublic()
3. Salva file su disco: uploads/{section}/{filename}
4. Salva path nella colonna fisica o in EAV
5. Restituisce path relativo
```

---

## 10. Protocollo e Codice Segnalazione

### 10.1 Generazione Protocollo Form
- Calcolato da `ensureForm` alla creazione
- Formato: `RS_{INIZIALI}` (es. `RS_IT` per "Segnalazione IT")
- Se esiste gia: `RS_IT_2`, `RS_IT_3`, etc.
- Stored in `forms.protocollo`

### 10.2 Codice Segnalazione Record
- Generato alla creazione del record
- Stored in `mod_{nome}.codice_segnalazione`
- Usato come identificativo leggibile nei listing e nelle notifiche

---

## 11. Sincronizzazione Assegnatario

Logica speciale per il campo `assegnato_a_esito` nella scheda Esito:

```
if (values.assegnato_a_esito exists) {
  // Prende i primi 5 ID (limite)
  ids = values.assegnato_a_esito.split(',').slice(0, 5)
  // Sincronizza con la colonna principale
  UPDATE mod_{nome} SET assegnato_a = ids WHERE id = record_id
}
```

Questo permette al responsabile di riassegnare il record durante la chiusura.

---

## 12. Subtask e Audit Trail

Ogni `submitScheda` salva anche un "subtask":

```
FormsDataService::saveSubtask({
  form_name,
  parent_record_id: record_id,
  scheda_label: scheda_key,
  scheda_data: { ...tutti i valori + path file }
})
```

Questo crea un record figlio che traccia cosa e stato inserito, quando e da chi.
I subtask sono visibili nel listing (campo `subtasks` nella risposta `getFilledForms`).

---

## 13. Auto-Creazione Scheda Esito

Se un modulo non ha una scheda con `isClosureTab=true`, il backend ne crea una automaticamente al caricamento (`loadTabsWithFields`):

```
Scheda auto-generata "Esito":
{
  label: "Esito",
  scheda_type: "chiusura",
  isClosureTab: true,
  edit_roles: ["responsabile", "assegnatario", "admin"],
  visibility_roles: ["responsabile", "assegnatario", "admin"],
  visibility_condition: { type: "after_all_previous_submitted" },
  unlock_after_submit_prev: 1
}
```

Questo garantisce che ogni modulo abbia sempre un meccanismo di chiusura.

---

## 14. Redirect e Navigazione

### 14.1 Dopo Creazione Record
```
view_form -> submitScheda(struttura) -> redirect -> form_viewer?form_name=X&id=ID
```

### 14.2 Dopo Submit Scheda (con redirect_after_submit)
```
form_viewer -> submitScheda(scheda_key) -> calculateNextScheda -> switch to next tab
```

### 14.3 Dal Menu
```
menu_custom.link -> gestione_segnalazioni?form_name=X -> listing records
```

### 14.4 Dal Listing al Record
```
click su record -> form_viewer?form_name=X&id=ID
```

### 14.5 Dal Listing a Nuovo Record
```
click "+" (function-bar) -> view_form?form_name=X
```

### 14.6 Redirect Sezione
`view_form.php` valida che la sezione nell'URL corrisponda alla sezione reale del form (da `menu_custom`). Se diversa, fa 301 redirect all'URL corretto prima di renderizzare.
