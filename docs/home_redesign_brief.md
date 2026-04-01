# Brief per Redesign Home Page - Intranet Aziendale

Documento per generare idee e mockup della home page di un'intranet aziendale.
Da usare come prompt/contesto per un tool di design AI (es. Google Stitch).

---

## Cos'e questa intranet

Intranet aziendale di una societa di ingegneria (~50-100 dipendenti) che gestisce **commesse** (progetti di ingegneria), **gare d'appalto**, **risorse umane**, **documenti**, **ore/costi**, **verbali riunione** e **segnalazioni interne**. Ogni utente ha uno o piu ruoli con permessi diversi. C'e un ruolo Admin con accesso completo.

---

## Home Attuale (cosa c'e ora)

La home attuale e basata su **news aziendali** ed e poco funzionale:

- **Hero banner** con notizia in evidenza (da WordPress aziendale)
- **3 news recenti** dal sito aziendale
- **Comunicazioni/Newsletter** con upload file
- **Sidebar destra** con:
  - Calendario con compleanni
  - Link rapidi interni (MOM, Archivio, Segnalazioni, Contatti, Mappa Ufficio, Protocollo Email)
  - Link esterni (Nextcloud, Akeron, sito aziendale)
  - Ultime notifiche (mini-lista)
  - Changelog intranet

**Problema**: la home non mostra nulla di operativo. L'utente non vede i SUOI progetti, i SUOI task, le SUE scadenze. E una pagina statica di news, non una dashboard di lavoro.

---

## Tutti i Moduli dell'Intranet (Dati Disponibili per Widget)

### 1. COMMESSE (Progetti)
Gestione completa di progetti di ingegneria con task, team, scadenze, sicurezza.

**Dati disponibili per widget**:
- Lista progetti attivi dell'utente
- Task assegnati all'utente (con scadenza, priorita, stato)
- Task in ritardo (deadline superata)
- Conteggio task per stato (da fare, in corso, completati)
- Scadenze imminenti dei progetti
- Percentuale completamento progetto
- Membri del team di ogni progetto
- Organigramma di progetto

**Ruoli coinvolti**: Project Manager, Responsabile, Membro team

---

### 2. GARE D'APPALTO (Tender Management + AI)
Gestione gare pubbliche con estrazione automatica dati da documenti tramite AI.

**Dati disponibili per widget**:
- Gare per stato: valutazione, in corso, consegnata, aggiudicata, non aggiudicata
- Gare assegnate all'utente
- Gare ad alta priorita
- Prossime scadenze gare
- Gare in attesa di decisione partecipazione
- Ultime estrazioni AI completate
- Quota utilizzo AI

**Ruoli coinvolti**: Responsabile Gare, Team gare

---

### 3. SEGNALAZIONI E MODULI DINAMICI
Sistema di form configurabili per segnalazioni IT, richieste, processi interni. Ogni modulo ha un workflow multi-scheda con stati personalizzati.

**Dati disponibili per widget**:
- Segnalazioni assegnate all'utente (come responsabile o assegnatario)
- Segnalazioni create dall'utente
- Segnalazioni in attesa di risposta/azione
- Conteggio per stato (aperta, in corso, chiusa)
- Segnalazioni scadute
- Ultime segnalazioni ricevute

**Ruoli coinvolti**: Tutti gli utenti (creano segnalazioni), Responsabili (rispondono)

---

### 4. TASK GLOBALI
Sistema di task trasversale a tutti i moduli (progetti, gare, riunioni, HR).

**Dati disponibili per widget**:
- Task assegnati all'utente (da tutti i contesti)
- Task scaduti
- Task per stato (da fare, in corso, completato)
- Task per contesto (progetto, gara, riunione)
- Attivita recente sui task
- Task creati di recente

**Ruoli coinvolti**: Tutti gli utenti

---

### 5. ORE E COSTI (Dashboard Economica)
Monitoraggio ore lavorate, budget, utilizzo risorse per progetto.

**Dati disponibili per widget**:
- Ore lavorate questo mese dall'utente
- Ore vs budget per progetto
- Progetti in overrun (superamento budget)
- Trend ore settimanale/mensile
- Utilizzo risorse (% delle ore totali)
- Anomalie (giornate senza ore registrate)
- Stato certificazione ore

**Ruoli coinvolti**: Project Manager, Direzione, Tutti (per le proprie ore)

---

### 6. VERBALI RIUNIONE (MOM)
Creazione e gestione verbali con azioni, checklist, partecipanti.

**Dati disponibili per widget**:
- Azioni assegnate all'utente da riunioni
- Azioni scadute/in ritardo
- Riunioni recenti a cui l'utente ha partecipato
- Prossime riunioni pianificate
- Checklist pendenti

**Ruoli coinvolti**: Tutti i partecipanti alle riunioni

---

### 7. HR E CONTATTI
Gestione personale, competenze, selezione candidati, organigramma.

**Dati disponibili per widget**:
- Compleanni del giorno/settimana
- Nuovi assunti recenti
- Candidati in pipeline di selezione (per fase)
- Posizioni aperte
- Distribuzione competenze team
- Headcount per dipartimento/area

**Ruoli coinvolti**: HR, Manager, Direzione

---

### 8. DOCUMENTI (Archivio, Qualita, Formazione)
Repository documenti con sincronizzazione Nextcloud, organizzato in 3 aree.

**Dati disponibili per widget**:
- Documenti modificati di recente
- Documenti caricati questa settimana
- Stato sincronizzazione Nextcloud
- Documenti per area (archivio, qualita, formazione)

**Ruoli coinvolti**: Tutti (lettura), Gestori area (scrittura)

---

### 9. PROTOCOLLO EMAIL
Registro email aziendali con numerazione progressiva per progetto.

**Dati disponibili per widget**:
- Email recenti protocollate
- Conteggio email per progetto
- Email inviate/ricevute questo mese

**Ruoli coinvolti**: Utenti con accesso protocollo

---

### 10. NOTIFICHE
Sistema notifiche in-app e email, trigger su eventi form/task/riunioni.

**Dati disponibili per widget**:
- Conteggio notifiche non lette
- Ultime N notifiche
- Notifiche fissate (pinned)

**Ruoli coinvolti**: Tutti gli utenti

---

### 11. CHANGELOG
Registro aggiornamenti dell'intranet.

**Dati disponibili per widget**:
- Ultimi aggiornamenti della piattaforma

---

## Caratteristiche degli Utenti

### Profili Tipo

**Project Manager / Responsabile**:
- Gestisce piu progetti contemporaneamente
- Ha bisogno di vedere: task in ritardo, scadenze, stato progetti, ore budget
- Priorita: overview operativa rapida

**Membro Team Tecnico**:
- Lavora su 1-3 progetti
- Ha bisogno di vedere: i suoi task, le sue scadenze, le segnalazioni assegnate
- Priorita: cosa devo fare oggi/questa settimana

**Responsabile Gare**:
- Segue le gare d'appalto
- Ha bisogno di vedere: gare in scadenza, stato estrazioni AI, gare da valutare
- Priorita: non perdere scadenze gare

**HR / Direzione**:
- Supervisione generale
- Ha bisogno di vedere: headcount, candidati, budget ore, KPI
- Priorita: metriche aggregate e trend

**Utente Base**:
- Usa segnalazioni, consulta documenti, registra ore
- Ha bisogno di vedere: le sue segnalazioni, notifiche, link rapidi
- Priorita: accesso veloce alle funzioni che usa

### Sistema Permessi
Ogni utente vede solo i widget per cui ha i permessi. Se non ha accesso a un modulo, il widget relativo non appare. L'Admin vede tutto.

---

## Idee Widget Possibili

### Widget Personali (specifici dell'utente loggato)

1. **I Miei Task** - Lista task assegnati a me, ordinati per scadenza, con stato e contesto (progetto/gara/riunione). Possibilita di cambiare stato direttamente.

2. **Le Mie Scadenze** - Timeline/lista delle prossime scadenze: task, progetti, gare, segnalazioni. Tutto in un posto.

3. **Le Mie Segnalazioni** - Segnalazioni che richiedono la mia azione (come responsabile) o che ho creato io (in attesa di risposta).

4. **Azioni da Riunioni** - Azioni assegnate a me dai verbali, con checklist pendenti.

5. **Le Mie Ore** - Ore registrate questa settimana/mese, confronto con target, alert se mancano giorni.

### Widget di Overview (aggregati)

6. **Progetti Attivi** - Card per ogni progetto attivo con: nome, % completamento, task aperti, prossima scadenza. Click per entrare.

7. **Stato Gare** - Distribuzione gare per stato (mini-grafico), lista gare prossime alla scadenza.

8. **Pipeline Segnalazioni** - Conteggi per stato (aperte/in corso/chiuse) con trend rispetto alla settimana precedente.

9. **KPI Ore/Budget** - Indicatori chiave: ore totali mese, budget utilizzato %, progetti in overrun.

10. **Attivita Recente** - Feed delle ultime azioni nell'intranet (nuovi task, segnalazioni, documenti caricati, riunioni create).

### Widget Informativi

11. **Compleanni** - Chi compie gli anni oggi/questa settimana, con foto e nome.

12. **Link Rapidi** - Accesso veloce alle app piu usate dall'utente (personalizzabile o basato su frequenza).

13. **Notifiche** - Ultime notifiche con badge conteggio non lette.

14. **News Aziendali** - 2-3 news dal sito aziendale (WordPress) con immagine e titolo.

15. **Changelog** - Ultimi aggiornamenti della piattaforma.

16. **Calendario** - Mini-calendario del mese con indicatori: scadenze, compleanni, riunioni.

### Widget Avanzati

17. **Heatmap Attivita** - Mappa di calore delle ore lavorate per giorno (stile GitHub contributions).

18. **Distribuzione Team** - Chi sta lavorando su cosa: mappa visuale risorse-progetti.

19. **Candidati HR** - Pipeline selezione con conteggi per fase (screening, colloquio, offerta).

20. **Documenti Recenti** - Ultimi documenti caricati/modificati nelle aree a cui ho accesso.

---

## Requisiti Funzionali per la Home

### Adattiva ai Permessi
- Ogni widget appare SOLO se l'utente ha il permesso per quel modulo
- Layout si adatta automaticamente: se mancano widget, gli altri si ridistribuiscono
- Admin vede tutti i widget

### Personalizzabile (opzionale/futuro)
- L'utente potrebbe scegliere quali widget vedere
- L'utente potrebbe riordinare i widget
- L'utente potrebbe ridimensionare i widget

### Responsive
- Desktop: layout multi-colonna (2-3 colonne)
- Tablet: 2 colonne
- Mobile: 1 colonna, widget impilati

### Performance
- I widget caricano dati in modo asincrono (non bloccano il rendering)
- Skeleton/placeholder durante il caricamento
- Cache dove possibile (dati che cambiano raramente)

### Interattiva
- Click su un widget porta alla pagina di dettaglio del modulo
- Azioni rapide dove possibile (es. cambia stato task, segna come letto)
- Aggiornamento in tempo reale delle notifiche

---

## Vincoli Tecnici (per chi implementera)

- **Stack**: PHP + Vanilla JS (no React/Vue/Angular) + CSS custom
- **AJAX**: Tutte le chiamate passano per `/ajax.php` con CSRF token
- **Autenticazione**: Sessione PHP, ruoli multipli, permessi granulari
- **Formato dati**: JSON con struttura `{success: bool, data: mixed}`
- **CSS**: Nessun framework CSS (no Bootstrap/Tailwind), tutto custom
- **JS**: ES6 modules, no bundler, import diretto

---

## Cosa serve dal designer AI

1. **Layout della home page** - Come organizzare i widget nello spazio
2. **Design dei widget** - Aspetto visivo di ogni tipo di widget (card, lista, grafico, contatore)
3. **Sistema di griglia** - Come i widget si dispongono e si adattano
4. **Stati dei widget** - Loading, vuoto, con dati, errore
5. **Gerarchia visiva** - Quali widget sono piu importanti e come attirare l'attenzione
6. **Palette colori** - Coerente con un brand di ingegneria/costruzioni
7. **Interazioni** - Hover, click, espansione, collapse
8. **Responsive** - Come si adatta a schermi diversi
9. **Widget prioritari** - Suggerire quali widget sono piu utili per ogni profilo utente

L'obiettivo e trasformare una home statica di news in una **dashboard operativa personalizzata** dove ogni utente vede subito cosa deve fare, cosa sta succedendo nei suoi progetti, e cosa richiede la sua attenzione.
