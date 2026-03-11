# 04 - First Module Candidates

Proposta dei moduli ideali per avviare la migrazione incrementale.

## Candidato 1: Contatti e Profili Staff (Quick Win)

**Modulo Legacy**: `views/contacts.php`, `Services\ContactService.php`.

- **Motivazione**: È un modulo quasi interamente di "visualizzazione". La business logic è semplice (lettura dati anagrafici) e i dati sono stabili.
- **Vantaggi Next.js**: Miglioramento della ricerca (Search-as-you-type), gestione immagini profilo e schede bio con layout fluido.
- **Isolamento Tecnico**: Elevato. Esistono già metodi in `ContactService` che restituiscono JSON pulito.
- **Sforzo Stimato**: Basso.
- **Rischio**: Quasi nullo. In caso di errore, il link nell'intranet può essere riportato alla versione PHP.

---

## Candidato 2: Verbali Riunione (MOM) (High Value)

**Modulo Legacy**: `views/mom.php`, `Services\MomService.php`.

- **Motivazione**: È uno dei moduli più utilizzati e "interattivi" (richiede drawer laterali, salvataggi in tempo reale, filtri complessi).
- **Vantaggi Next.js**: L'interfaccia React permetterebbe una gestione molto più fluida delle righe editabili e delle task collegate, eliminando i refresh forzati o l'uso pesante di jQuery per manipolare il DOM.
- **Isolamento Tecnico**: Medio. Richiede l'integrazione con il sistema di notifiche e la gestione allegati.
- **Sforzo Stimato**: Medio-Alto.
- **Rischio**: Medio. Essendo un modulo critico per il workflow, richiede test approfonditi sulla persistenza dei dati.

---

## Candidato 3: Task Board / Kanban (Modernization)

**Modulo Legacy**: `views/task_management.php`, `Services\TaskService.php`.

- **Motivazione**: La logica delle board (Kanban) è difficile da mantenere con jQuery (`jKanban.min.js`). Un componente React dedicato sarebbe molto più robusto.
- **Vantaggi Next.js**: Drag-and-drop nativo (dnd-kit), aggiornamenti ottimistici dell'interfaccia, gestione sub-tasks gerarchiche.
- **Isolamento Tecnico**: Molto Elevato. Il nuovo schema `sys_tasks` è stato pensato per essere agnostico rispetto al frontend PHP.
- **Sforzo Stimato**: Medio.

---

## Raccomandazione Migrazione

Si consiglia di procedere in questo ordine:

1. **Phase 1: Setup & Profile/Contacts**.
   Inizializzare il progetto Next.js e migrare i contatti. Questo permette di testare la connessione al DB legacy, il sistema di routing (proxying) e lo styling base (Tailwind/CSS Modules).
2. **Phase 2: Task Management**.
   Migrare le board. Questo stabilisce il pattern per le azioni di scrittura (mutazioni) e l'integrazione con i ruoli/permessi.

3. **Phase 3: MOM & Collaboration**.
   Migrare i verbali. Introduzione di logiche più pesanti di business e integrazione con il Document Manager (Nextcloud).

4. **Phase X: Core Business (Commesse/Gare)**.
   Da affrontare solo dopo aver stabilizzato i moduli satelliti, a causa dell'altissimo accoppiamento e della complessità della logica SQL.
