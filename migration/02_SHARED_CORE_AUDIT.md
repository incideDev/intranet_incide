# 02 - Shared Core Audit

Analisi delle infrastrutture trasversali che devono essere replicate o interfacciate nel nuovo stack Next.js.

## 1. Authentication & Session Management

L'autenticazione attuale è gestita dalla classe `Session` in `core/session.php`.

- **Source of Truth**: Tabella `users`.
- **Session Init**: `Session::startSession()` viene chiamato in `bootstrap.php`.
- **Stato**: Memorizzato in `$_SESSION` (user_id, user_email, role_id, permissions).
- **CSRF**: Token generato ad ogni sessione e validato via header `Csrf-Token` in `ajax.php`.
- **Strategia Migrazione**:
  - Inizialmente: Next.js può leggere il cookie di sessione PHP se configurato sullo stesso dominio/sottodominio.
  - Target: Migrazione a **Better Auth** con supporto per password legacy (BCrypt).

## 2. Permissions & Roles

Sistema Granulare basato su tabelle `sys_role_permissions`.

- **Funzione Core**: `userHasPermission($permission_name)` riceve una stringa e controlla se l'utente loggato ha tale permesso.
- **Aggregazione**: I permessi sono aggregati se un utente ha più ruoli.
- **Inibizione**: Alcuni file (`index.php`) usano hardcoded checks su `$_SESSION['role_id']` (legacy).
- **Strategia Migrazione**: Replicare la logica in un middleware Next.js o un adapter per Better Auth.

## 3. Database Library (PDO Wrapper)

Classe `MySQLDB` in `core/database.php`.

- **Pattern**: Wrapper PDO che forza il log delle query fallite e gestisce la connessione singleton.
- **Sicurezza**: Supporto nativo per Prepared Statements.
- **Note**: In Next.js si consiglia l'uso di **Kysely** o **Drizzle** per mantenere il controllo sulle query SQL legacy pur avendo type-safety.

## 4. Global Helpers (`core/functions.php`)

Oltre 2500 righe di utility. Le più critiche:

- `getProfileImage($id, $type)`: Risoluzione path immagini profilo (logica fallback complessa).
- `formatDate($date)`: Formattazione date IT/EN.
- `getStaticMenu()`: Generazione dinamica sidebar (basata su registry e menu_custom).
- `norm($string)`: Normalizzazione stringhe per confronti.

## 5. Front-end Shared Assets

- **Layout**: Gestito in `index.php` con inclusione di `views/layout/header.php` e `footer.php`.
- **JS Globals**:
  - `window.CSRF_TOKEN`: Disponibile globalmente.
  - `customFetch`: Wrapper su `fetch()` che inietta automaticamente il token CSRF.
- **CSS Framework**: Mix di Bootstrap (legacy) e componenti custom (`assets/css/`).

## 6. Entry Points Strategici

- **`ajax.php`**: Deve essere mantenuto come proxy o progressivamente sostituito da Next.js API Routes.
- **`service_router.php`**: Contiene la mappatura tra azioni frontend e metodi backend. Utile per identificare tutti gli endpoint da migrare.
