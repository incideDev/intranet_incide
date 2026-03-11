# 01 - Module Inventory

Questo documento elenca i moduli principali applicativi analizzati, dettagliando la loro complessità, dipendenze e idoneità alla migrazione verso Next.js.

## Legenda

- **Accoppiamento (Coupling)**: L (Low), M (Medium), H (High)
- **Priorità (Priority)**: 1 (Alta/Immediata), 2 (Media), 3 (Bassa/Diferita)
- **Tipo**: Client-side (viste semplici), Server-side (logica complessa), Mixed.

## Tabella Inventario Moduli

| Modulo               | File Principali (View/Service)                 | DB Tables                           | AJAX Endpoints                 | Coupling | Tipo   | Priorità | Rischi / Note                                        |
| :------------------- | :--------------------------------------------- | :---------------------------------- | :----------------------------- | :------- | :----- | :------- | :--------------------------------------------------- |
| **Dashboard Home**   | `home.php`                                     | -                                   | `get_stats`, `recent_activity` | L        | Mixed  | 2        | Semplice, ma aggrega dati da più moduli.             |
| **Contatti & Staff** | `contacts.php`, `ContactService.php`           | `personale`, `hr_resource`          | `getContacts`, `getProfile`    | L        | Mixed  | 1        | **Ottimo candidato.** API pulite.                    |
| **Commesse (Core)**  | `commesse.php`, `CommesseService.php`          | `commesse_bacheche`, `com_*`        | `getTasks`, `saveTask`         | H        | Mixed  | 2        | Molta logica "form-to-db" diretta.                   |
| **Bandi & Gare**     | `gare.php`, `GareService.php`                  | `ext_gare`, `ext_jobs`              | `listGare`, `startExtraction`  | M        | Mixed  | 3        | Logica AI complessa gestita via API esterne.         |
| **MOM (Meeting)**    | `mom.php`, `MomService.php`                    | `mom_items`, `mom_headers`          | `saveMom`, `getMomDetail`      | M        | Mixed  | 1        | Molto interattivo (drawer), ideale per React.        |
| **Time Tracking**    | `dashboard_ore.php`, `DashboardOreService.php` | `project_time`, `user_hours`        | `getStats`, `saveEntry`        | H        | Mixed  | 2        | Calcoli SQL pesanti e reportistica complessa.        |
| **Doc Manager**      | `document_manager.php`, `NextcloudService.php` | `sys_docs`                          | `uploadFile`, `listDocs`       | M        | Mixed  | 3        | Dipendenza stretta da API Nextcloud.                 |
| **Task Board**       | `task_management.php`, `TaskService.php`       | `sys_tasks`                         | `updateTaskStatus`             | L        | Mixed  | 1        | Modulo stand-alone con nuove API Next-ready.         |
| **Ruoli & Permessi** | `gestione_ruoli.php`, `RoleService.php`        | `sys_roles`, `sys_role_permissions` | `assignRole`                   | H        | Server | 3        | Core security. Da migrare per ultimo (o coesistere). |

## Analisi Dettagliata per Sezione

### 1. Sezione Collaborativa (MOM & Tasks)

- **Caratteristiche**: Elevata interattività lato client, interfacce tipo board o timeline.
- **Next.js Fit**: Eccellente. Beneficia di stati React per drag-and-drop (Kanban) e aggiornamenti real-time.
- **Dependencies**: jQuery UI (da rimpiazzare con dnd-kit o simile).

### 2. Sezione Anagrafiche (HR & Contacts)

- **Caratteristiche**: Viste Tabulari (DataTables) e schede dettaglio.
- **Next.js Fit**: Molto buono. La logica di filtraggio può essere spostata su API routes di Next.js consumando il DB legacy.
- **Dependencies**: Molti include PHP per i form (`view_form.php`).

### 3. Sezione Core Business (Commesse & Gare)

- **Caratteristiche**: Workflow approvativi lunghi, generazione PDF, tabelle SQL dinamiche.
- **Next.js Fit**: Medio. Richiede una refactoring profondo della logica dei service PHP per separare il rendering (HTML) dai dati (JSON).
- **Rischi**: Le tabelle `com_X` dinamiche sono difficili da gestire con ORM moderni (Prisma/Drizzle) senza schemi predefiniti.
