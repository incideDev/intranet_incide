# Audit uniformità MySQLDB::query()

## SEZIONE A — AUDIT COMPLETO

| File:riga | Chiamata sintetica | Classe | Motivo | Azione proposta |
|-----------|--------------------|--------|--------|-----------------|
| core/database.php:496 | query($sql, '', __FILE__ . " ==>" . __LINE__) | INCOERENTE | 2° arg '' invece di [] | Sostituire '' con [] |
| core/database.php:1006 | query($sql, ['id'=>$id], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/database.php:1022 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/functions.php:849 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/functions.php:875 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/functions.php:1498 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/functions.php:1503-1506 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| core/functions.php:1553-1556 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/archivio_commesse.php:9-15 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/archivio_commesse.php:28 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/archivio_commesse.php:126-129 | query($sql, [$x], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/elenco_commesse.php:9-15 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/elenco_commesse.php:28 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/elenco_commesse.php:127-130 | query($sql, [$x], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_task.php:17-20 | query($sql, [':t'=>$t], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_task.php:59 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_task.php:74 | query($sql, [$id], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_task.php:259 | query($sql, [':t'=>$t], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_task.php:274-277 | query($sql, $partecipanti, __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:17-22 | query($sql, [$t], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:47-50 | query($sql, [$cand], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:67 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:88-92 | query($sql, [$r], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:102 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_organigramma.php:131-134 | query($sql, [$id], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:577 | query($sql, [$id], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1038 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1056-1059 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1062-1065 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1068-1071 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1074-1079 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1082-1087 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1090-1095 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1099-1104 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1112-1117 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1146-1151 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| service_router.php:1160-1165 | query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/CommessaCronoService.php | tutte (29, 45-48, 57-60, 88, 225, 271, 313, 348) | NON_ESPLICITA_MA_VALIDA | filerow = __FILE__ senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/ContactService.php | tutte (37, 103, 137, 155, 327, 346, 360, 372, 384, 398, 405, 412, 443, 465, 502, 524, 562, 589, 617, 651, 707, 742) | NON_ESPLICITA_MA_VALIDA | filerow = __FILE__ senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/CommesseService.php | tutte (~50 occorrenze) | NON_ESPLICITA_MA_VALIDA | filerow = __FILE__ senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/DashboardOreService.php | 579 e tutte le altre | NON_ESPLICITA_MA_VALIDA | filerow = __FILE__ senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/MomService.php | tutte | NON_ESPLICITA_MA_VALIDA | filerow = __FILE__ senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| services/DatasourceService.php:36 | query($sql) | NON_ESPLICITA_MA_VALIDA | 1 solo arg, no params no filerow | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:48 | query($sql) | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:51 | query("SELECT * FROM sys_db_whitelist") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:73 | query($sql) | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:95 | query("UPDATE...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:97 | query("INSERT...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:110 | query("UPDATE...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:112 | query("INSERT...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:121 | query("SELECT...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/DatasourceService.php:179 | query($sql, array_values($values), __FILE__ . ' resolveDbselectValues') | NON_ESPLICITA_MA_VALIDA | filerow descrittivo, no __LINE__ | Valutare: mantenere stile o uniformare |
| services/DatasourceService.php:205 | query("SELECT...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/FormSubmissionService.php:62 | query("SHOW COLUMNS...") | NON_ESPLICITA_MA_VALIDA | 1 solo arg | Aggiungere [], __FILE__ . " ==> " . __LINE__ |
| services/FormSubmissionService.php:68 | query("START TRANSACTION", [], __FILE__ . ' ⇒ updateEsito.tx') | ESPLICITA_CANONICA | filerow descrittivo, forma corretta | Nessuna (opzionale: uniformare a __LINE__) |
| views/includes/commesse/commessa_chiusura.php:108-111 | $db->query($sql, [$x], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |
| views/includes/commesse/commessa_chiusura.php:126-129 | $db->query($sql, [], __FILE__) | NON_ESPLICITA_MA_VALIDA | filerow senza __LINE__ | Aggiungere . " ==> " . __LINE__ |

**Nota:** Le chiamate in core/functions.php con filerow `__FILE__ . ' ⇒ nomeContesto'` (es. menu_custom, getModuliBySection.forms_check) sono forme esplicite con contesto descrittivo. Per uniformità stretta andrebbero convertite a `__FILE__ . " ==> " . __LINE__`, ma il contesto descrittivo può essere preferibile in funzioni complesse. Si propone di lasciarle come NON_ESPLICITA_MA_VALIDA solo se si vuole uniformità totale a __LINE__.

**Esclusi dall'audit (non MySQLDB::query):**
- ImportManagerService: $mysqli->query
- GareService, CommesseService:3335: $pdo->query
- NextcloudService: $xpath->query (DOMXPath)

---

## SEZIONE B — PATCH PLAN

### Regola di conversione principale

**DA (NON_ESPLICITA o INCOERENTE):**
```
query($sql)
query($sql, [], __FILE__)
query($sql, [$x], __FILE__)
query($sql, '', __FILE__ . " ==>" . __LINE__)
```

**A (ESPLICITA_CANONICA):**
```
query($sql, [], __FILE__ . " ==> " . __LINE__)
query($sql, [$x], __FILE__ . " ==> " . __LINE__)
```

### Patch per categoria

1. **INCOERENTE (1 occorrenza):**
   - database.php:496: `''` → `[]`

2. **NON_ESPLICITA con 1 arg (11 occorrenze in DatasourceService + FormSubmissionService):**
   - Aggiungere `, [], __FILE__ . " ==> " . __LINE__` come 2° e 3° argomento

3. **NON_ESPLICITA con filerow = __FILE__ (senza __LINE__):**
   - Sostituire `__FILE__` con `__FILE__ . " ==> " . __LINE__` in tutte le chiamate che usano solo __FILE__
   - Per filerow descrittivi (es. `__FILE__ . ' ⇒ menu_custom'`): valutare se uniformare a __LINE__ o mantenere per leggibilità contesto

### Ordine di applicazione

1. Correggere INCOERENTE (database.php:496)
2. Correggere 1-arg (DatasourceService, FormSubmissionService)
3. Uniformare __FILE__ → __FILE__ . " ==> " . __LINE__ nei file restanti (batch per file)

---

## SEZIONE C — ELENCO FILE COINVOLTI

| File | Occorrenze da uniformare | Tipo |
|------|--------------------------|------|
| core/database.php | 2 | INCOERENTE + NON_ESPLICITA |
| core/functions.php | 6 | NON_ESPLICITA (filerow) |
| views/archivio_commesse.php | 3 | NON_ESPLICITA |
| views/elenco_commesse.php | 3 | NON_ESPLICITA |
| views/includes/commesse/commessa_task.php | 5 | NON_ESPLICITA |
| views/includes/commesse/commessa_organigramma.php | 6 | NON_ESPLICITA |
| views/includes/commesse/commessa_chiusura.php | 2 | NON_ESPLICITA |
| service_router.php | 14 | NON_ESPLICITA |
| services/CommessaCronoService.php | 9 | NON_ESPLICITA |
| services/ContactService.php | 22 | NON_ESPLICITA |
| services/CommesseService.php | ~50 | NON_ESPLICITA |
| services/DashboardOreService.php | ~40 | NON_ESPLICITA |
| services/MomService.php | ~25 | NON_ESPLICITA |
| services/DatasourceService.php | 11 | NON_ESPLICITA (1-arg + filerow) |
| services/FormSubmissionService.php | 1 | NON_ESPLICITA (1-arg) |
| + altri services (DocumentManager, FormsData, PageEditor, ecc.) | ~30 | NON_ESPLICITA |

**Totale file: ~25**

---

## SEZIONE D — NUMERO TOTALE CHIAMATE DA UNIFORMARE

| Categoria | Conteggio stimato |
|-----------|-------------------|
| INCOERENTE | 1 |
| NON_ESPLICITA (1 arg) | 11 |
| NON_ESPLICITA (filerow senza __LINE__) | ~220 |
| **Totale** | **~232** |

*Nota: il conteggio esatto richiederebbe una scansione riga per riga di tutti i services. La stima si basa sull'audit parziale e sui pattern osservati.*
