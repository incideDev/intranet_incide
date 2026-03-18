# Elenco Documenti — Design Spec: Completamento Pagina

**Data:** 2026-03-16
**Stato:** Approvato
**Approccio:** A — Riscrittura progressiva del rendering, riuso componenti esistenti

---

## Contesto

La pagina Elenco Documenti (`?section=commesse&page=commessa&tabella=XXX&view=elaborati`) ha backend quasi completo (schema v2 con 5 tabelle, 18 actions nel service) e frontend parziale. Il frontend va completato per allinearsi al reference del capo (`document_list_preview.html`).

**Reference:** `.agent/exports/elenco_documenti_example/document_list_preview.html`

---

## 1. Tabella Documenti con Colonne Split

### Stato attuale
Tabella semplice con 8 colonne: Codice (unico) | Titolo | Stato | Rev | % | Emissione | Resp | Azioni

### Stato target
Tabella `table table-filterable` con 19 colonne organizzate in gruppi colorati, una tabella per sezione (ogni sezione collassabile, coerente con il layout attuale e il reference).

### Struttura colonne

**CODICE DOCUMENTO** (7 colonne, header indigo `#eef0f8`):
| Colonna | Larghezza | Editabile | Meccanismo |
|---------|-----------|-----------|------------|
| Fase | ~52px | Si | Dropdown da lookup template (solo codice, no descrizione) |
| Zona | ~42px | Si | Dropdown da lookup template (solo codice) |
| Disc | ~42px | Si | Dropdown da lookup template (solo codice) |
| Tipo | ~42px | Si | Dropdown da lookup template (codice + descrizione) |
| Numero | ~58px | Si | Input inline numerico |
| Rev | ~36px | No | Gestita da logica revisioni |
| Codice completo | ~180px | No | Calcolato: `PRJ-FASE-ZONA-DISC-TIPO-NNNN-REV` |

**INFORMAZIONI** (4 colonne, header verde `#f0fdf4`):
| Colonna | Larghezza | Editabile | Meccanismo |
|---------|-----------|-----------|------------|
| Titolo | flex | Si | Click → apre props panel |
| Tipo documento | ~90px | No | Label dalla lookup tipo |
| Resp. | ~70px | Si | Dropdown da lookup personale |
| Output | ~70px | Si | Dropdown da lookup output |

**STATO / AVANZAMENTO** (2 colonne, header giallo `#fef3c7`):
| Colonna | Larghezza | Editabile | Meccanismo |
|---------|-----------|-----------|------------|
| Stato | ~100px | Si | Dropdown con badge colorati |
| Avanzamento | ~80px | Si | Slider popup |

**PIANIFICAZIONE** (4 colonne, header arancio `#fff7ed`):
| Colonna | Larghezza | Editabile | Meccanismo |
|---------|-----------|-----------|------------|
| Inizio | ~85px | Si | Date picker popup |
| Fine prev. | ~85px | Si | Date picker popup |
| Emissione | ~85px | Si | Date picker popup |
| Submittal | ~100px | No | Chip cliccabile se associato |

**FILE + AZIONI** (2 colonne):
| Colonna | Larghezza | Editabile | Meccanismo |
|---------|-----------|-----------|------------|
| File | ~60px | No | Icona/conteggio file Nextcloud collegati, click apre browser NC |
| Azioni | ~60px | No | Duplica revisione (solo se EMESSO) + Elimina |

**Totale: 19 colonne**

### Header a doppio livello
```html
<thead>
    <tr class="th-groups"> <!-- riga decorativa, ignorata da table-filterable -->
        <th colspan="7" class="grp-code">CODICE DOCUMENTO</th>
        <th colspan="4" class="grp-info">INFORMAZIONI</th>
        <th colspan="2" class="grp-state">STATO / AVANZAMENTO</th>
        <th colspan="4" class="grp-plan">PIANIFICAZIONE</th>
        <th colspan="2"></th>
    </tr>
    <tr> <!-- riga <th> standard — table-filterable aggancia filtri qui -->
        <th>Fase</th>
        <th>Zona</th>
        <!-- ... 17 colonne restanti -->
    </tr>
</thead>
```

### Compatibilita table-filterable con doppio header
`initTableFilters()` in `main_core.js` assume che `thead.rows[0]` sia la riga header dati. Con la riga `th-groups` sopra, si rompe. **Soluzione:** modificare `initTableFilters()` per rilevare e skippare righe con classe `th-groups`, usando l'ultima `<tr>` senza quella classe come riga header. Modifica minimale a `main_core.js` (~5 righe), retrocompatibile con tutte le altre tabelle.

### Layout sezioni
Una tabella `table-filterable` per ogni sezione, dentro un contenitore collassabile:
```
[Sezione Header: nome | range badge | conteggio | azioni]
  └─ [table.table.table-filterable con 19 colonne]
  └─ [bottone "+ Aggiungi documento"]
```

### Inline editing — Dropdown segmenti
Click su cella Fase/Zona/Disc/Tipo → dropdown posizionato sotto la cella con:
- Lista valori dalla lookup del template attivo
- Fase/Zona/Disc: mostrano solo il codice (nel template sono stringhe piatte)
- Tipo: mostra codice + descrizione (nel template e un oggetto `{cod, desc}`)
- Selezione → aggiorna cella + ricalcola codice completo + auto-save via `saveDocumento`

### Inline editing — Numero
Click su cella Numero → input inline, blur/enter → salva

### Inline editing — Resp/Output
Click → dropdown con valori da lookup personale/output

### Inline editing — Permessi
Tutte le celle editabili sono interattive SOLO se `window.userHasPermission('edit_commessa')`. Per utenti read-only le celle mostrano i valori senza interattivita.

### Auto-save
Ogni modifica inline chiama `saveDocumento()` immediatamente. Per evitare race condition con click rapidi su piu campi, il salvataggio usa un meccanismo di debounce/queue: accumula le modifiche per 300ms prima di inviare, cosi modifiche rapide consecutive vengono inviate in un'unica chiamata. Flash "Salvato" in basso a destra.

---

## 2. Stat Cards

### Stato attuale
3 stat inline nell'header (Documenti, Avanzamento %, Emessi)

### Stato target
3 cards con layout a griglia, bordo colorato a sinistra.

| Card | Valore | Sottotesto | Colore bordo |
|------|--------|------------|--------------|
| Documenti totali | N | "X sezioni · Y discipline" | Rosso |
| Avanzamento medio | N% | "X emessi su Y" | Verde |
| Submittal | N programmati | "Prossimo: dd/mm/yy" | Giallo |

Le stat cards si aggiornano sia al caricamento iniziale che dopo ogni operazione CRUD (salvataggio doc, creazione/modifica submittal).

---

## 3. Template Configuration Panel

### Pannello laterale slide-in da destra

**Header:** "Configura Template" + nome template
**Tab bar:** 4 tab — Fasi | Zone | Discipline | Tipi
**Contenuto tab:** Tabella editabile per ogni lookup:
- Colonna Codice (monospace, 2-3 char, editabile)
- Colonna Descrizione (editabile — per Fasi/Zone/Disc la descrizione e opzionale dato che nel template sono stringhe piatte; per Tipi e il campo `desc` dell'oggetto)
- Bottone elimina per riga (con guard: non eliminabile se usata da documenti esistenti)
- Bottone "+ Aggiungi" in fondo

**Footer:** Bottone "Salva"

**Backend:** `getTemplate()` e `saveTemplate()` gia pronti, nessuna modifica.

**Accesso:** Bottone "Configura Template" nella toolbar, visibile solo con permesso `edit_commessa`.

**Vincolo:** Salvare il template aggiorna i dropdown inline della tabella documenti.

---

## 4. Export Excel

### Bottone "Esporta" nella toolbar

Scarica `.xlsx` con tutti i documenti della commessa corrente.

**Colonne export:** Sezione | Fase | Zona | Disc | Tipo | Numero | Rev | Codice completo | Titolo | Tipo doc (label) | Resp | Output | Stato | Avanzamento % | Data inizio | Data fine prev. | Data emissione | Submittal

**Backend:** Nuovo metodo `exportExcel()` in `ElencoDocumentiService.php`. Dipendenza: verificare se `SimpleXLSXGen` (writer) e disponibile nello stack. Se non presente, installarlo (`shuchkin/simplexlsxgen` via Composer) oppure generare CSV come fallback.

**Frontend:** Richiesta AJAX → download file. Il metodo backend setta gli header HTTP per il download diretto del file.

---

## 5. Logica Nextcloud — Auto-creazione Cartelle

### Comportamento
Quando un documento viene salvato (nuovo o codice modificato):
1. Dentro la cartella Nextcloud della commessa (`/INTRANET/ELABORATI/{idProject}/`) si crea una sottocartella: `{CODICE_COMPLETO} - {Titolo}`
   Esempio: `/INTRANET/ELABORATI/3DY01/3DY01-PD-00-AR-RE-0001-RA - Relazione architettonica/`
2. Se codice o titolo cambiano → la cartella viene rinominata via WebDAV MOVE
3. I file fisici (PDF, DWG, ecc.) caricati in quella cartella Nextcloud si **associano** alla riga documento corrispondente — non creano nuove righe

### Relazione documento <-> file
- **Riga documento** = definizione/metadati (codice, titolo, stato, avanzamento...)
- **Cartella Nextcloud** = contenitore file fisici associati a quella riga
- La colonna "File" nella tabella mostra il conteggio file; click apre il browser Nextcloud
- Il collegamento e tramite il path Nextcloud che corrisponde univocamente al codice documento

### Implementazione
- Modifica a `saveDocumento()`: prima del salvataggio, fetcha il documento esistente dal DB per confrontare il vecchio codice_completo con il nuovo. Se diverso e la vecchia cartella esiste, esegue WebDAV MOVE. Se documento nuovo, esegue WebDAV MKCOL.
- `listNcFolder()` gia esiste e punta alla cartella corretta
- Se Nextcloud non raggiungibile → salvataggio DB procede, warning non bloccante restituito nel response (`['success' => true, 'data' => ..., 'nc_warning' => 'message']`)
- Se MOVE fallisce (es. target path gia esiste) → log dell'errore, warning non bloccante

---

## File coinvolti

| File | Tipo modifica |
|------|---------------|
| `assets/js/elenco_documenti.js` | Riscrittura rendering tabella + inline editing + template panel JS + export |
| `views/elenco_documenti.php` | Adattamento HTML: tabella, stat cards, template panel markup, export button |
| `assets/css/elenco_documenti.css` | Stili gruppi header, stat cards, inline editing dropdowns, template panel |
| `services/ElencoDocumentiService.php` | Aggiunta `exportExcel()` + logica Nextcloud auto-cartelle in `saveDocumento()` |
| `assets/js/modules/main_core.js` | Modifica minimale a `initTableFilters()` per supportare riga `th-groups` |

## Cosa NON si tocca
- Pannello proprieta laterale (funzionante)
- Logica revisioni backend (funzionante)
- Submittal manager + pannello nuovo submittal (funzionante)
- Lettera di trasmissione + PDF + mail (funzionante)
- Browser Nextcloud UI (funzionante)
- Routing backend service_router.php (funzionante)
- Schema DB v2 (gia eseguito)

## Principi
- **Riuso**: `table-filterable` per filtri/resize/paginazione, WebDAV gia integrato
- **Zero duplicati**: nessuna nuova logica tabella custom, si usa il componente centralizzato
- **Coerenza**: stessi pattern UI/CSS del resto della intranet
- **Auto-save inline**: ogni modifica salva immediatamente (con debounce 300ms per evitare race condition)
- **NC_ROOT**: `/INTRANET/ELABORATI/` (costante gia definita nel service)
