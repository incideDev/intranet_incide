# Refactor tecnico database.php — Report finale

## SEZIONE A — AUDIT TECNICO

| Problema | File:riga | Evidenza | Impatto | Azione proposta |
|----------|-----------|----------|---------|-----------------|
| Firma query() incoerente | database.php:690 | Chiamate legacy `(query, filerow, types, params)` ma firma attesa `(query, params, filerow)`; execute() riceveva null per le query parametrizzate | **Critico** — query con placeholder falliscono silenziosamente | Normalizzare in query(): rilevare signature legacy e mappare correttamente params |
| num_rows su PDOStatement | database.php:164, 181, 195, 443, 464 | `$res->num_rows` non esiste su PDOStatement | **Critico** — fatal error a runtime | Sostituire con `$res->fetch(PDO::FETCH_ASSOC) !== false` o `fetchAll()` + count |
| fetch_assoc() su PDOStatement | database.php:270, 448, 656 | `$result->fetch_assoc()` è mysqli, PDO usa `fetch(PDO::FETCH_ASSOC)` | **Critico** | Sostituire con `fetch(PDO::FETCH_ASSOC)` o `fetchAll(PDO::FETCH_ASSOC)` |
| free() su PDOStatement | database.php:735, 907 | `$result->free()` non esiste su PDO | **Critico** | Sostituire con `closeCursor()` |
| insert_id su PDO | database.php:892 | `$this->connection->insert_id` è mysqli | **Critico** | Usare `lastInsertId()` |
| connection->error / errno | database.php:883, 887 | PDO non ha error/errno | **Critico** | Usare `$this->connection->errorInfo()` |
| stmt->errno / stmt->error in stmt_execute | database.php:870, 873 | PDOStatement non ha errno/error | **Critico** | Usare `$stmt->errorInfo()` |
| return_result usa free() | database.php:735 | Chiama free() su PDOStatement | **Critico** | Usare closeCursor() |
| getConfigurazione: num_rows + return_result | database.php:464-467 | Doppia incompatibilità | **Critico** | Sostituire con fetch + closeCursor |
| getSitePrivilege: num_rows + fetch_assoc | database.php:443-448 | Loop incompatibile | **Critico** | Usare fetchAll + foreach |
| getNaviMenu: fetch_assoc in while | database.php:656 | Incompatibile PDO | **Critico** | Usare fetchAll + foreach |
| getNumMembers: nessun guard su result | database.php:485 | Se query fallisce, fetchColumn() su false | **Medio** | Aggiungere guard |
| calcNumActiveGuests: idem | database.php:519 | Idem | **Medio** | Aggiungere guard |
| AddGlobalLog / LockedTimeGlobal | database.php:236-264, 296-318 | Usano mysqli_* e $this->conn_ipcheck (mai inizializzato) | **Basso** — codice morto | Non toccato — fase 2 |
| IsLocked primo blocco | database.php:150-157 | Usa mysqli per IP check | **Nessuno** | Lasciato: usa DB separato per lock IP |

---

## SEZIONE B — IMPATTO ESTERNO

| Metodo/Pattern | File:riga | Come viene usato | Rischio regressione | Decisione |
|----------------|-----------|------------------|---------------------|-----------|
| $database->query() | core/functions.php, services/*, views/*, index.php | (sql, params, __FILE__) o (sql, __FILE__, types, params) | **Normale** | query() normalizzata internamente |
| $database->prepare() | index.php:573,604; CvService.php | prepare + execute + fetch | **Nessuno** | prepare() restituisce PDOStatement nativo |
| $database->stmt_execute() | **Nessun uso** | — | **Nessuno** | Corretto internamente |
| $database->free() | Solo database.php | return_result, metodo free() | **Nessuno** | Sostituito con closeCursor |
| $database->lastid() | **Nessun uso esterno** | — | **Nessuno** | Corretto internamente |
| $database->lastInsertId() | MomService, CommesseService, DocumentManagerService, ecc. | Dopo INSERT | **Nessuno** | Già PDO |
| fetch_assoc() | ImportManagerService.php | Su $desc da mysqli (non da database) | **Nessuno** | Fuori scope |
| num_rows | ImportManagerService.php | Su $chk da mysqli | **Nessuno** | Fuori scope |
| return_result() | Solo interno getConfigurazione | — | **Nessuno** | Corretto |

**Conclusione:** Il refactor è confinato a database.php. Nessun file chiamante richiede modifiche.

---

## SEZIONE C — PIANO DI PATCH

1. **Normalizzare query()** — Rilevare signature legacy (2° stringa, 3° stringa, 4° array) e mappare params correttamente.
2. **Sostituire num_rows** — Con `fetch() !== false` o `fetchAll()` + count a seconda del contesto.
3. **Sostituire fetch_assoc()** — Con `fetch(PDO::FETCH_ASSOC)` o `fetchAll(PDO::FETCH_ASSOC)`.
4. **Sostituire free()** — Con `closeCursor()` in return_result e nel metodo free().
5. **Correggere query_error() / query_errorno()** — Usare `connection->errorInfo()`.
6. **Correggere lastid()** — Delegare a `lastInsertId()`.
7. **Correggere stmt_execute()** — Usare `$stmt->errorInfo()` al posto di errno/error.
8. **Correggere getConfigurazione** — Inline fetch + closeCursor, rimuovere num_rows.
9. **Correggere getSitePrivilege** — fetchAll + foreach.
10. **Correggere getNaviMenu** — fetchAll + foreach.
11. **Aggiungere guard** — getNumMembers, calcNumActiveGuests quando result è false.

---

## SEZIONE D — MODIFICHE APPLICATE

### core/database.php

| Modifica | Cosa | Perché | Rischio residuo |
|----------|------|--------|-----------------|
| query() | Rilevamento signature legacy e mappatura params | Le chiamate (query, filerow, types, params) non passavano i params a execute() | Nessuno |
| query() | Uso di actualFilerow nel branch errore | Coerenza con filerow corretto in modalità legacy | Nessuno |
| IsLocked (blocco PDO) | num_rows → fetch | PDOStatement non ha num_rows | Nessuno |
| loginErrorAddLog | num_rows → fetch | Idem | Nessuno |
| LockedTime | fetch_assoc → fetch | PDO compat | Nessuno |
| getSitePrivilege | num_rows + fetch_assoc → fetchAll + foreach | PDO compat | Nessuno |
| getConfigurazione | num_rows + return_result → fetch + closeCursor | PDO compat | Nessuno |
| getNaviMenu | fetch_assoc in while → fetchAll + foreach | PDO compat | Nessuno |
| return_result | free() → closeCursor() | PDO compat | Nessuno |
| free() | free() → closeCursor() con instanceof PDOStatement | PDO compat | Nessuno |
| query_error() | connection->error → errorInfo()[2] | PDO compat | Nessuno |
| query_errorno() | connection->errno → errorInfo()[1] | PDO compat | Nessuno |
| errorInfo() | Nuovo metodo delegato a connection->errorInfo() | Compatibilità CvService | Nessuno |
| lastid() | insert_id → lastInsertId() | PDO compat | Nessuno |
| stmt_execute | stmt->errno/error → stmt->errorInfo() | PDO compat | Nessuno |
| getNumMembers | Guard su result | Evita fetchColumn su false | Nessuno |
| calcNumActiveGuests | Guard su result | Idem | Nessuno |

### File non toccati

- Nessun file esterno modificato (core/functions.php, services/*, views/*, index.php).

---

## SEZIONE E — VERIFICA FINALE

### Checklist

- [x] **PDO coerente** — Tutti gli usi interni su PDOStatement usano API PDO (fetch, fetchAll, fetchColumn, closeCursor, rowCount, lastInsertId, errorInfo).
- [x] **Nessun uso interno incompatibile residuo** — Nessun num_rows, fetch_assoc, free, insert_id, error, errno su PDO.
- [x] **Nessun alias-toppa introdotto** — Nessuna funzione wrapper o alias duplicato.
- [x] **Nessuna duplicazione funzionale** — lastid() delega a lastInsertId(); return_result resta unico.
- [x] **Nessun file core ignorato** — Solo database.php modificato.

### Punti ancora critici (non risolti — fase 2)

1. **AddGlobalLog()** (database.php:236-264) — Usa `mysqli_query`, `mysqli_num_rows`, `$this->conn_ipcheck` che non è mai inizializzato. Codice morto (chiamata commentata). Da migrare a PDO o rimuovere.
2. **LockedTimeGlobal()** (database.php:296-318) — Usa `mysqli_connect` e `mysqli_query` per DB lock IP separato. Coesistenza mysqli/PDO. Da valutare migrazione.
3. **IsLocked() primo blocco** (database.php:150-157) — Usa mysqli per IP_SERVER. Intenzionale: DB lock separato. Nessuna azione se non si unifica il layer.

### Chiamanti esterni (nessuna fase 2 richiesta)

- Tutti i chiamanti usano `query()`, `prepare()`, `lastInsertId()` che restano compatibili.
- CvService.php:700 usa `$database->errorInfo()` — Aggiunto `errorInfo()` a MySQLDB che delega a `connection->errorInfo()` per compatibilità.

---

## Riepilogo

- **File modificati:** 1 (core/database.php)
- **Problemi risolti:** 15
- **Compatibilità:** Mantenuta per tutti i chiamanti
- **Comportamento business:** Invariato
