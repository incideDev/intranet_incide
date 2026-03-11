# Report finale — Migrazione chiamate legacy MySQLDB::query()

## SEZIONE A — FILE MODIFICATI

| File | Numero modifiche | Tipo |
|------|------------------|------|
| core/database.php | 19 | Conversioni signature + rimozione supporto legacy |
| views/includes/commesse/commessa_task.php | 1 | Conversione signature |

**Totale: 2 file, 20 modifiche.**

---

## SEZIONE B — CONVERSIONI APPLICATE

| File:riga | Prima | Dopo |
|-----------|-------|------|
| core/database.php:104 | query($sql, '', __FILE__) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| core/database.php:163 | query($sql, __FILE__, 's', [$ip]) | query($sql, [$this->ip], __FILE__ . " ==> " . __LINE__) |
| core/database.php:180 | query($q, __FILE__, 's', [$ip]) | query($q, [$this->ip], __FILE__ . " ==> " . __LINE__) |
| core/database.php:191 | query($query, __FILE__, $Tipo, $Dati) | query($query, $Dati, __FILE__ . " ==> " . __LINE__) |
| core/database.php:193 | query($sql, __FILE__, 'si', [...]) | query($sql, [$this->ip, SITE_MAX_CHANCE], __FILE__ . " ==> " . __LINE__) |
| core/database.php:202 | query($sql, __FILE__, 's', [$ip]) | query($sql, [$this->ip], __FILE__ . " ==> " . __LINE__) |
| core/database.php:269 | query($sql, __FILE__, "ss", [...]) | query($sql, [$this->ip, SITE_MAX_CHANCE], __FILE__ . " ==>" . __LINE__) |
| core/database.php:428 | query($sql, '', __FILE__) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| core/database.php:441 | query($q, __FILE__, "i", [$level]) | query($q, [$level], __FILE__ . " ==> " . __LINE__) |
| core/database.php:462 | query($sql, __FILE__) | query($sql, [], __FILE__ . " ==> " . __LINE__) |
| core/database.php:496 | query($sql, '', __FILE__) | query($sql, [], __FILE__ . " ==>" . __LINE__) |
| core/database.php:506 | query($sql, '', __FILE__) | query($sql, [], __FILE__ . " ==>" . __LINE__) |
| core/database.php:517 | query($sql, __FILE__) | query($sql, [], __FILE__ . " ==>" . __LINE__) |
| core/database.php:643 | query($q, __FILE__, "siii", [...]) | query($q, [$username, $level, $tipo, $lato], __FILE__ . '=>' . __LINE__) |
| core/database.php:645 | query($q, __FILE__, "sii", [...]) | query($q, [$username, $level, $tipo], __FILE__ . '=>' . __LINE__) |
| core/database.php:835 | query($Q, $filerow, $types, $Params) | query($Q, $Params, $filerow) |
| core/database.php:914 | query("LOCK TABLES...", $filerow) | query("LOCK TABLES...", [], $filerow) |
| core/database.php:919 | query("UNLOCK TABLES") | query("UNLOCK TABLES", [], __FILE__ . " ==> " . __LINE__) |
| views/includes/commesse/commessa_task.php:59 | query("SELECT...personale") | query("SELECT...personale", [], __FILE__) |

---

## SEZIONE C — RESIDUO

- **Numero chiamate legacy residue:** 0
- **Numero chiamate dubbie residue:** 0
- **Elenco:** Nessuna.

**Nota:** Esistono chiamate con 1 solo argomento in altri file (es. DatasourceService.php, FormSubmissionService.php) che non erano nello scope dell'audit approvato. Queste continuano a funzionare perché `query($sql)` con params=null e filerow=null è compatibile con la signature canonica (execute(null) per query senza placeholder).

---

## SEZIONE D — query()

- **Supporto legacy rimosso:** SÌ
- **Motivo:** Il ricontrollo ha confermato zero residui legacy/dubbie nel perimetro migrato. La logica di detection `is_string($params) && is_string($filerow) && is_array($AF)` è stata rimossa. Il metodo usa ora solo `$actualParams = is_array($params) ? $params : null`.

---

## SEZIONE E — CHECK FINALE

- [x] **Una sola signature canonica usata nel repo** — Tutte le chiamate in database.php e commessa_task.php usano `query($sql, $params, $filerow)`.
- [x] **Nessun alias-toppa introdotto** — Nessun wrapper o helper aggiunto.
- [x] **Nessun refactor collaterale** — Solo conversioni di signature e rimozione logica legacy.
- [x] **Comportamento business invariato** — Nessuna modifica alle query SQL, ai placeholder o alla logica applicativa.
