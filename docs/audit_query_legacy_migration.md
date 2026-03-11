# Audit migrazione chiamate legacy MySQLDB::query()

## SEZIONE A — AUDIT COMPLETO

| File:riga | Chiamata sintetica | Tipo | Motivo classificazione | Azione |
|-----------|--------------------|------|------------------------|--------|
| core/database.php:104 | query($sql, '', __FILE__) | DUBBIA | 2° arg '' (stringa vuota) invece di [] per "no params" | Convertire '' → [] |
| core/database.php:123 | query($q, [':username'=>$u], __FILE__) | CANONICA | params array, filerow 3° | Nessuna |
| core/database.php:163 | query($sql, __FILE__, 's', [$ip]) | LEGACY | 2° filerow, 3° types, 4° params | Convertire a (sql, [$ip], __FILE__) |
| core/database.php:180 | query($q, __FILE__, 's', [$ip]) | LEGACY | Idem | Convertire |
| core/database.php:191 | query($query, __FILE__, $Tipo, $Dati) | LEGACY | 2° filerow, 3° types, 4° params | Convertire a (query, $Dati, __FILE__) |
| core/database.php:193 | query($sql, __FILE__, 'si', [$ip, SITE_MAX_CHANCE]) | LEGACY | Idem | Convertire |
| core/database.php:202 | query($sql, __FILE__, 's', [$ip]) | LEGACY | Idem | Convertire |
| core/database.php:231 | query($sql, [':time'=>$t], __FILE__) | CANONICA | params array, filerow 3° | Nessuna |
| core/database.php:269 | query($sql, __FILE__, "ss", [$ip, SITE_MAX_CHANCE]) | LEGACY | Idem | Convertire |
| core/database.php:334-337 | query($sql, [':username'=>..., ':auth_token'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:361 | query($sql, [':username'=>$u], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:371 | query($sql, [':username'=>$u], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:382 | query($q, [':value'=>..., ':username'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:404 | query($q, [':username'=>$u], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:428 | query($sql, '', __FILE__) | DUBBIA | 2° '' invece di [] | Convertire '' → [] |
| core/database.php:441 | query($q, __FILE__, "i", [$level]) | LEGACY | 2° filerow, 3° types, 4° params | Convertire |
| core/database.php:462 | query($sql, __FILE__) | LEGACY | 2° filerow, 2 arg (no params) | Convertire a (sql, [], __FILE__) |
| core/database.php:483 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/database.php:496 | query($sql, '', __FILE__) | DUBBIA | 2° '' invece di [] | Convertire '' → [] |
| core/database.php:506 | query($sql, '', __FILE__) | DUBBIA | Idem | Convertire |
| core/database.php:517 | query($sql, __FILE__) | LEGACY | 2° filerow, 2 arg | Convertire a (sql, [], __FILE__) |
| core/database.php:535 | query($q, [':timestamp'=>..., ':username'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:540-545 | query($q, [':username'=>..., ...], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:569 | query($q, [':username'=>$u], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:579 | query($sql, [':ip'=>$ip], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:590 | query($sql, [':timestamp'=>$t], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:601 | query($sql, [':timestamp'=>$t], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:611 | query($q, [':ip'=>..., ':porta'=>..., ...], __FILE__) | CANONICA | params array | Nessuna |
| core/database.php:643 | query($q, __FILE__, "siii", [$u, $l, $t, $lato]) | LEGACY | 2° filerow, 3° types, 4° params | Convertire |
| core/database.php:645 | query($q, __FILE__, "sii", [$u, $l, $t]) | LEGACY | Idem | Convertire |
| core/database.php:835 | query($Q, $filerow, $types, $Params) | LEGACY | cleanQuery: 2° filerow, 3° types, 4° params | Modificare cleanQuery per chiamare (Q, Params, filerow) |
| core/database.php:914 | query("LOCK TABLES...", $filerow) | LEGACY | 2° filerow, no params | Convertire a (sql, [], $filerow) |
| core/database.php:919 | query("UNLOCK TABLES") | DUBBIA | 1 solo arg, no params no filerow | Convertire a (sql, [], __FILE__) — filerow mancante |
| core/database.php:1013 | query($sql, ['id'=>$id], __FILE__) | CANONICA | params array (nota: placeholder :id richiede ':id') | Nessuna per signature; bug separato |
| core/database.php:1029 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:373-380 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:485-488 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:671-678 | query($sql, [':s'=>..., ':p'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:692-695 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:744-751 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:786-789 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:849 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:875 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:1498 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:1503-1506 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:1553-1556 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| core/functions.php:1968 | query($sql, $params, __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2036-2039 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2197-2202 | query($sql, [$u,$s,$h,...], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2240-2243 | query($sql, [$s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2250-2253 | query($sql, [$u], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2316 | query($sql, [$s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2336-2339 | query($sql, [$s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2366-2369 | query($sql, [$t, $uid], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2373-2376 | query($sql, [$s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2383-2386 | query($sql, [$h, $s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2441 | query($sql, [$s], __FILE__) | CANONICA | params array | Nessuna |
| core/functions.php:2618-2620 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/archivio_commesse.php:9-15 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/archivio_commesse.php:28 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/archivio_commesse.php:126-129 | query($sql, [$c['codice']], __FILE__) | CANONICA | params array | Nessuna |
| views/elenco_commesse.php:9-15 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/elenco_commesse.php:28 | query($sql, [], __FILE__) | Nessuna |
| views/elenco_commesse.php:127-130 | query($sql, [$c['codice']], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa.php:43-47 | query($sql, [$tabella], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_organigramma.php:17-22 | query($sql, [$tabella], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_organigramma.php:47-50 | query($sql, [$cand], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_organigramma.php:67 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/commesse/commessa_organigramma.php:88-92 | query($sql, [$r], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_organigramma.php:102 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/commesse/commessa_organigramma.php:131-134 | query($sql, [$id], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_task.php:17-20 | query($sql, [':t'=>$t], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_task.php:59 | query("SELECT...personale") | DUBBIA | 1 solo arg, nessun params/filerow | Convertire a (sql, [], __FILE__) |
| views/includes/commesse/commessa_task.php:74 | query($sql, [$id], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_task.php:259 | query($sql, [':t'=>$t], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_task.php:274-277 | query($sql, $partecipanti, __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:18 | query($sql, [$codice], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:26 | query($sql, [$codice], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:31 | query($sql, [$norm], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:36 | query($sql, ["%{$codice}%"], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:228 | query($query, [$oggi], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:269 | query($sql, [$bacheca_id], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_dati.php:289 | query($sql, $ids, __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:20 | query($sql, [$codice], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:26 | query($sql, [$bacheca_id], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:30 | query($sql, [$codice], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:51 | query($sql, [$resp_raw], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:108-111 | $db->query($sql, [$x], __FILE__) | CANONICA | $db è MySQLDB, params array | Nessuna |
| views/includes/commesse/commessa_chiusura.php:126-129 | $db->query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/form_viewer.php:35 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/form_viewer.php:56 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/form_viewer.php:63 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/form_viewer.php:70 | query($sql, [':id'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/dashboard_forms.php:8-13 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/dashboard_forms.php:51 | query($sql, [':table'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/dashboard_forms.php:57 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/dashboard_forms.php:63 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/dashboard_forms.php:67 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/dashboard_forms.php:80-84 | query($sql, [':name'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/gestione_intranet/page_editor.php:15-20 | query($sql, [':name'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/gestione_intranet/maintenance_settings.php:21 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/gestione_intranet/maintenance_settings.php:23-26 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/gestione_intranet/maintenance_settings.php:33-36 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/view_form.php:49 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/view_form.php:102 | query($sql, [':id'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/view_form.php:140 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| views/includes/form/view_form.php:147 | query($sql, [':id'=>...], __FILE__) | CANONICA | params array | Nessuna |
| views/includes/form/view_form.php:166 | query($sql, [':n'=>...], __FILE__) | CANONICA | params array | Nessuna |
| index.php:203 | query($sql, [$id], __FILE__) | CANONICA | params array | Nessuna |
| service_router.php:577 | query($sql, [$id], __FILE__) | CANONICA | params array | Nessuna |
| service_router.php:1038 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1056-1059 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1062-1065 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1068-1071 | query($sql, [], __FILE__) | Nessuna |
| service_router.php:1074-1079 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1082-1087 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1090-1095 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1099-1104 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1112-1117 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1146-1151 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| service_router.php:1160-1165 | query($sql, [], __FILE__) | CANONICA | params [] | Nessuna |
| services/CommessaCronoService.php | tutte | CANONICA | params array o [] | Nessuna |
| services/DashboardOreService.php | tutte | CANONICA | params array o [] | Nessuna |
| services/ContactService.php | tutte | CANONICA | params array o [] | Nessuna |
| services/CommesseService.php | tutte | CANONICA | params array o [] | Nessuna |
| services/MomService.php | tutte | CANONICA | params array o [] | Nessuna |
| services/* (altri) | tutte | CANONICA | params array o [] | Nessuna |

---

## SEZIONE B — ANALISI RISCHIO

| File:riga | Rischio | Motivo | Patch meccanica possibile |
|-----------|---------|--------|---------------------------|
| core/database.php:104 | BASSO | '' → []; query senza placeholder | Sì |
| core/database.php:163 | BASSO | params in 4° pos, types inutilizzato da PDO | Sì |
| core/database.php:180 | BASSO | Idem | Sì |
| core/database.php:191 | BASSO | $Dati è array params, $Tipo inutilizzato | Sì |
| core/database.php:193 | BASSO | Idem | Sì |
| core/database.php:202 | BASSO | Idem | Sì |
| core/database.php:269 | BASSO | Idem | Sì |
| core/database.php:428 | BASSO | '' → [] | Sì |
| core/database.php:441 | BASSO | params in 4° pos | Sì |
| core/database.php:462 | BASSO | 2 arg: aggiungere [] come 2° | Sì |
| core/database.php:496 | BASSO | '' → [] | Sì |
| core/database.php:506 | BASSO | '' → [] | Sì |
| core/database.php:517 | BASSO | 2 arg: aggiungere [] come 2° | Sì |
| core/database.php:643 | BASSO | params in 4° pos | Sì |
| core/database.php:645 | BASSO | Idem | Sì |
| core/database.php:835 | MEDIO | cleanQuery costruisce la chiamata; va modificato l'ordine degli argomenti passati a query() | Sì — cambiare riga 835 da query($Q, $filerow, $types, $Params) a query($Q, $Params, $filerow) |
| core/database.php:914 | BASSO | blocca(): 2 arg, aggiungere [] | Sì |
| core/database.php:919 | MEDIO | sblocca(): 1 arg, nessun filerow. Aggiungere [], __FILE__ richiede includere contesto | Sì — (sql, [], __FILE__ . " ==> " . __LINE__) |
| views/includes/commesse/commessa_task.php:59 | BASSO | 1 arg: aggiungere [], __FILE__ | Sì |

---

## SEZIONE C — PATCH PLAN

### 1. File da modificare

| File | Chiamate da convertire | Note |
|------|------------------------|------|
| core/database.php | 18 | Tutte interne alla classe |
| views/includes/commesse/commessa_task.php | 1 | Riga 59 |

**Totale: 2 file, 19 chiamate.**

### 2. Dettaglio conversioni per file

**core/database.php (18 conversioni):**

| Riga | DA | A |
|------|-----|---|
| 104 | query($sql, '', __FILE__) | query($sql, [], __FILE__) |
| 163 | query($sql, __FILE__, 's', [$ip]) | query($sql, [$ip], __FILE__) |
| 180 | query($q, __FILE__, 's', [$ip]) | query($q, [$ip], __FILE__) |
| 191 | query($query, __FILE__, $Tipo, $Dati) | query($query, $Dati, __FILE__) |
| 193 | query($sql, __FILE__, 'si', [$ip, SITE_MAX_CHANCE]) | query($sql, [$ip, SITE_MAX_CHANCE], __FILE__) |
| 202 | query($sql, __FILE__, 's', [$ip]) | query($sql, [$ip], __FILE__) |
| 269 | query($sql, __FILE__, "ss", [$ip, SITE_MAX_CHANCE]) | query($sql, [$ip, SITE_MAX_CHANCE], __FILE__) |
| 428 | query($sql, '', __FILE__) | query($sql, [], __FILE__) |
| 441 | query($q, __FILE__, "i", [$level]) | query($q, [$level], __FILE__) |
| 462 | query($sql, __FILE__) | query($sql, [], __FILE__) |
| 496 | query($sql, '', __FILE__) | query($sql, [], __FILE__) |
| 506 | query($sql, '', __FILE__) | query($sql, [], __FILE__) |
| 517 | query($sql, __FILE__) | query($sql, [], __FILE__) |
| 643 | query($q, __FILE__, "siii", [...]) | query($q, [$username, $level, $tipo, $lato], __FILE__) |
| 645 | query($q, __FILE__, "sii", [...]) | query($q, [$username, $level, $tipo], __FILE__) |
| 835 | query($Q, $filerow, $types, $Params) | query($Q, $Params, $filerow) |
| 914 | query("LOCK TABLES...", $filerow) | query("LOCK TABLES...", [], $filerow) |
| 919 | query("UNLOCK TABLES") | query("UNLOCK TABLES", [], __FILE__ . " ==> " . __LINE__) |

**views/includes/commesse/commessa_task.php (1 conversione):**

| Riga | DA | A |
|------|-----|---|
| 59 | query("SELECT user_id, Nominativo FROM personale") | query("SELECT user_id, Nominativo FROM personale", [], __FILE__) |

### 3. Casi dubbi da lasciare fuori

- **Nessuno.** Tutte le LEGACY e DUBBIA identificate hanno patch meccanica possibile con rischio BASSO o MEDIO.

### 4. Impatto atteso

- **Comportamento:** invariato.
- **query():** dopo la patch, tutte le chiamate useranno la signature canonica `(sql, params, filerow)`.
- **Supporto legacy in query():** può essere rimosso da `core/database.php` (blocco di rilevamento `is_string($params) && is_string($filerow) && is_array($AF)` e mappatura) **solo dopo** aver applicato questa patch. Fino ad allora il supporto legacy deve restare.
