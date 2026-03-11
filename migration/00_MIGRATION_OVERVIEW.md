# 00 - Migration Overview: Legacy PHP to Next.js

## 1. Entry Points & Routing

L'architettura attuale è basata su un **Single Entry Point** con delega a router specifici per AJAX e Business Logic.

- **Main Entry Point**: `index.php`
  - Gestisce il routing basato su parametri query string `section` e `page`.
  - Inizializza il kernel tramite `core/bootstrap.php`.
  - Include i file UI presenti in `views/`.
- **AJAX Entry Point**: `ajax.php`
  - Riceve tutte le chiamate asincrone del frontend.
  - Verifica il token CSRF (`$_SESSION['CSRFtoken']`).
  - Delega a `service_router.php` o richiama direttamente metodi statici in `Services\`.
- **Service Router**: `service_router.php`
  - Punto di smistamento per le azioni business (CRUD, export, calcoli complessi).
  - Mapper `action` -> `ServiceMethod`.

## 2. Moduli/Sezioni Principali

L'applicazione è suddivisa logicamente nelle seguenti aree macro (identificate nel `switch($Page)` di `index.php`):

| Modulo             | Descrizione                                                     | Business Logic (PHP)                                           | Frontend Logic (JS)                                 |
| :----------------- | :-------------------------------------------------------------- | :------------------------------------------------------------- | :-------------------------------------------------- |
| **Commesse**       | Core business: gestione progetti, cantieri e stato avanzamento. | `CommesseService.php`                                          | `commesse.js`, `commessa_crono.js`                  |
| **Gare**           | Gestione bandi, requisiti e preventivazione.                    | `GareService.php`, `RequisitiService.php`                      | `gare_detail.js`, `gare_list.js`                    |
| **HR & Contacts**  | Anagrafiche personale, recruiting, contatti clienti/fornitori.  | `HrService.php`, `ContactService.php`, `CvService.php`         | `contacts.js`, `cv_manager.js`, `hr_anagrafiche.js` |
| **Time Tracking**  | Dashboard ore, rendicontazione business unit.                   | `DashboardOreService.php`                                      | `dashboard_ore.js`, `ore_business_unit.js`          |
| **Collaborazione** | Verbali riunione (MOM), task management, segnalazioni.          | `MomService.php`, `TaskService.php`, `SegnalazioniService.php` | `mom.js`, `board_management.js`                     |
| **Document Area**  | Gestione documentale gerarchica, integrazione Nextcloud.        | `DocumentManagerService.php`, `NextcloudService.php`           | `document_manager.js`                               |
| **Admin Settings** | Gestione ruoli, permessi, dynamic forms (Page Editor).          | `RoleService.php`, `PageEditorService.php`                     | `gestione_ruoli.js`, `form_editor.js`               |

## 3. Dipendenze Shared & Core

Il progetto poggia su un core procedurale evoluto:

- **`core/bootstrap.php`**: Inizializzazione globale, caricamento `.env`, autoloading PSR-4 (simulato).
- **`core/session.php`**: Classe `Session` per gestione stato autenticazione, cookie legacy e sicurezza Session Hijacking.
- **`core/database.php`**: Wrapper PDO denominato `MySQLDB` con logging query integrato.
- **`core/functions.php`**: Libreria di utility globali (oltre 100kb di funzioni procedurali).

## 4. Auth & Sessione

L'autenticazione è centralizzata e sarà il punto critico per la migrazione incrementale (Better Auth dovrà coesistere o rimpiazzare gradualmente):

- **Storage**: Tabella `users`, `sys_user_remember_tokens`.
- **Password**: `password_hash` / `password_verify` (BCrypt).
- **Sessione**: PHP Native Session con cookie `secure; httponly; Lax`.
- **Ruoli**: Sistema multi-ruolo (`sys_user_roles`) con permessi granulari granulari (`sys_role_permissions`).

## 5. Frontend & AJAX

Il frontend è un mix di jQuery e librerie esterne integrate tramite script tags.

- **Library Base**: jQuery 3.x, jQuery UI.
- **UI Components**: DataTables (tabelle), fullCalendar (calendari), jKanban (board), Chart.js (grafici).
- **Comunicazione**: `customFetch` (wrapper AJAX) con gestione CSRF header `Csrf-Token`.

## 6. Analisi Accoppiamento

- **Alto Accoppiamento**: Modulo `Gare` e `Commesse`. Hanno service file giganti (>170kb) che contengono SQL quasi "raw" e molta logica di business intrecciata alla formattazione HTML.
- **Già Isolabili**: `Task Board`, `Changelog`, `Anagrafiche Base`. Hanno endpoint AJAX puliti che restituiscono JSON, rendendoli ottimi candidati per una migrazione "next-first".
