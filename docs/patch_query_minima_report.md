# Report patch minima — Uniformità MySQLDB::query()

## SEZIONE A — FILE MODIFICATI

| File | Numero modifiche |
|------|------------------|
| core/database.php | 1 |
| services/DatasourceService.php | 10 |
| services/FormSubmissionService.php | 1 |

**Totale: 3 file, 12 modifiche.**

---

## SEZIONE B — CONVERSIONI APPLICATE

| File:riga | Prima | Dopo |
|-----------|-------|------|
| core/database.php:496 | query($sql, '', __FILE__ . " ==>" . __LINE__) | query($sql, [], __FILE__ . " ==>" . __LINE__) |
| services/DatasourceService.php:36 | query($sql) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:48 | query($sql) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:51 | query("SELECT * FROM sys_db_whitelist") | query("SELECT * FROM sys_db_whitelist", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:73 | query($sql) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:95 | query("UPDATE...") | query("UPDATE...", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:97 | query("INSERT...") | query("INSERT...", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:110 | query("UPDATE...") | query("UPDATE...", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:112 | query("INSERT...") | query("INSERT...", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:121 | query("SELECT...") | query("SELECT...", [], __FILE__ . " ==> " . __LINE__) |
| services/DatasourceService.php:205 | query("SELECT...") | query("SELECT...", [], __FILE__ . " ==> " . __LINE__) |
| services/FormSubmissionService.php:62 | query("SHOW COLUMNS...") | query("SHOW COLUMNS...", [], __FILE__ . " ==> " . __LINE__) |

---

## SEZIONE C — RESIDUO

| File | Query non esplicite residue |
|------|-----------------------------|
| core/database.php | Nessuna |
| services/DatasourceService.php | Nessuna |
| services/FormSubmissionService.php | Nessuna |

**Nota:** Le chiamate con filerow = `__FILE__` (senza __LINE__) presenti in database.php:1022 e FormSubmissionService (es. righe 37, 45, 99, 122, ecc.) non sono state modificate in questa fase, come da scope autorizzato.

---

## SEZIONE D — NOTA

- **Non sono stati toccati altri file.** Solo core/database.php, services/DatasourceService.php e services/FormSubmissionService.php sono stati modificati.
- **La patch massiva dei filerow senza __LINE__ è stata rinviata a fase separata.** Le chiamate valide nel formato `query($sql, $params, __FILE__)` in core/functions.php, views/, service_router.php, services/ContactService.php, CommesseService.php, MomService.php, DashboardOreService.php, ecc. non sono state modificate.
