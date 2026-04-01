# Repository Docs — Design Spec

**Data:** 2026-03-16
**Stato:** Approvato

---

## Contesto

La tab "Repository Docs" (`?view=repository`) nella commessa e attualmente un placeholder con dati finti. Va collegata a Nextcloud per mostrare la struttura cartelle creata dall'elenco elaborati (`/INTRANET/ELABORATI/{idProject}/`) con i file reali dentro.

---

## 1. Struttura UI

Layout a due colonne dentro una card commessa.

### Sidebar sinistra — Cartelle
- Lista cartelle dalla struttura Nextcloud `/INTRANET/ELABORATI/{idProject}/`
- Ogni cartella mostra:
  - **Titolo documento** come label principale (es. "Relazione architettonica")
  - **Codice breve** come sottotitolo (es. `AR-RE-0001-RA`)
  - Il nome cartella Nextcloud e `{CODICE_COMPLETO} - {Titolo}`, da cui si estraggono le due parti
- Click su cartella → carica i file nel pannello destro
- Cartella selezionata evidenziata con stile `.active`
- Se nessuna cartella esiste: messaggio "Nessuna cartella. Definisci i documenti nell'elenco elaborati."

### Pannello destro — File
- Header: nome cartella selezionata + conteggio file + bottone upload piccolo (permesso `edit_commessa`)
- Lista file con: icona tipo (PDF/Word/Excel/CAD/immagine), nome, dimensione, data modifica
- Click su file → modale anteprima via `window.showMediaViewer()` (PDF/immagini inline, altri tipi download diretto)
- Azioni per file (visibili solo con permesso `edit_commessa`):
  - Download diretto
  - Sposta in altra cartella (dropdown con lista cartelle)
  - Elimina (con conferma)
- Se cartella vuota: messaggio "Nessun file. Carica file o aggiungili da Nextcloud."
- Upload: bottone piccolo "Carica" → input file → upload via AJAX a Nextcloud nella cartella corrente

---

## 2. Colonna "File" nell'Elenco Elaborati

Click sul conteggio/icona file di un documento → popover inline con:
- Lista file collegati (icona tipo + nome)
- Per ogni file: bottone preview (apre showMediaViewer) + download + detach
- Bottone "Carica file" in fondo al popover
- Il popover si chiude cliccando fuori (come gli altri popup gia esistenti)

---

## 3. Backend

### Metodi esistenti riusati
- `ElencoDocumentiService::listNcFolder($idProject)` — lista file nella root del progetto
- `ElencoDocumentiService::uploadNcFile($input)` — carica file
- `ElencoDocumentiService::attachNcFile($input)` / `detachNcFile($input)` — collegamento file-documento
- `NextcloudService::listFolder($path)` — WebDAV PROPFIND
- `NextcloudService::movePath($from, $to)` — WebDAV MOVE
- `NextcloudService::deletePath($path)` — WebDAV DELETE
- `NextcloudService::streamFile($path)` — proxy download
- Proxy URL: `ajax.php?section=nextcloud&action=file&path=...`

### Nuove actions in ElencoDocumentiService
| Action | Metodo | Scopo |
|--------|--------|-------|
| `listRepoFolders` | `listRepoFolders($idProject)` | Lista sottocartelle in `/INTRANET/ELABORATI/{idProject}/` con parsing nome→codice+titolo |
| `listRepoFiles` | `listRepoFiles($idProject, $folder)` | Lista file dentro una specifica sottocartella |
| `moveRepoFile` | `moveRepoFile($input)` | Sposta file da una cartella a un'altra via `movePath()` |
| `deleteRepoFile` | `deleteRepoFile($input)` | Elimina file da Nextcloud via `deletePath()` |
| `uploadRepoFile` | `uploadRepoFile($input)` | Upload file in una specifica sottocartella |

### Parsing nome cartella
Il nome cartella Nextcloud e nel formato: `{CODICE} - {TITOLO}`
```
3DY01-PD-00-AR-RE-0001-RA - Relazione architettonica
```
Split su ` - ` (primo occorrenza): parte sinistra = codice, parte destra = titolo.
Se il codice contiene piu segmenti (`PRJ-FASE-ZONA-DISC-TIPO-NUM-REV`), per il sottotitolo in sidebar mostriamo solo la parte dopo il codice progetto (es. `AR-RE-0001-RA`).

---

## 4. Anteprima File

Riuso di `window.showMediaViewer()` gia esistente (usato in document_manager.js per archivio/qualita).
- URL file: `ajax.php?section=nextcloud&action=file&path={encoded_path}`
- Supporta: PDF (iframe), immagini (img), altri tipi (download diretto)
- Nessun componente nuovo da creare

---

## 5. Sicurezza

- Tutti i path validati via `NextcloudService::validatePath()` (whitelist `/INTRANET/`)
- Permessi: `view_commesse` per visualizzare, `edit_commessa` per upload/sposta/elimina
- CSRF via ajax.php (gia gestito)
- Nomi file sanitizzati prima dell'upload

---

## File coinvolti

| File | Modifica |
|------|----------|
| `views/includes/commesse/commessa_repository.php` | Riscrittura completa: da placeholder a UI reale |
| `assets/js/commesse/commessa_repository.js` | Nuovo: logica caricamento cartelle/file, upload, sposta, elimina, preview |
| `assets/css/commessa_repository.css` | Nuovo: stili specifici per layout repository (o estensione di commesse_detail_overview.css) |
| `services/ElencoDocumentiService.php` | Aggiunta 5 nuove actions (listRepoFolders, listRepoFiles, moveRepoFile, deleteRepoFile, uploadRepoFile) |
| `assets/js/elenco_documenti.js` | Aggiunta popover file nella colonna File (click conteggio → lista file con azioni) |

## Cosa NON si tocca
- NextcloudService.php (si usano i metodi esistenti)
- DocumentManagerService.php (pattern diverso, non coinvolto)
- window.showMediaViewer (si riusa as-is)
- Struttura cartelle Nextcloud (creata dall'elenco elaborati)

## Principi
- **Riuso**: showMediaViewer, NextcloudService, proxy URLs, pattern archivio/qualita
- **Zero duplicati**: nessun nuovo viewer, nessun nuovo servizio Nextcloud
- **Coerenza**: stessi pattern UI della intranet (card commessa, sidebar+content layout)
