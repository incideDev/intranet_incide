# Audit tecnico — Blocco mysqli / lock IP in core/database.php

## SEZIONE A — AUDIT TECNICO

| Metodo | File:riga | Stato attuale | Problema tecnico | Rischio reale |
|--------|-----------|---------------|------------------|---------------|
| IsLocked() | database.php:144-170 | Misto: mysqli (blocco globale) + PDO (blocco sito) | Doppia connessione: mysqli a IP_SERVER per DB separato, PDO per DB principale. SQL riga 151: `WHERE ip=? AND log>=X OR perman>=X` — precedenza operatori: `(ip=? AND log>=X) OR perman>=X`; la condizione `perman>=X` matcha qualsiasi riga, non solo l'IP corrente. | Con IPLOCK_ATTIVO=1: mysqli_connect a IP_SERVER con placeholder (constants.php:33-36) fallirebbe. Con DB configurato: possibile bug logico su perman. |
| loginErrorAddLog() | database.php:172-205 | PDO | Usa solo $this->query (PDO). Chiama AddGlobalLog() ma la chiamata è commentata (riga 196). | Nessuno. |
| ClearLog() | database.php:206-232 | PDO + blocco commentato | Blocco commentato (219-227) usa mysqli_query($this->conn_ipcheck, $sql). conn_ipcheck mai inizializzato. Parte attiva usa PDO. | Nessuno (blocco mysqli commentato). |
| AddGlobalLog() | database.php:235-262 | mysqli | Usa mysqli_query($this->conn_ipcheck, ...). conn_ipcheck non è proprietà dichiarata; viene impostata solo in LockedTimeGlobal() che non è mai chiamata. SQL con concatenazione diretta: rischio SQL injection su $this->ip. | **Fatal:** se chiamato, Undefined property $conn_ipcheck. In pratica mai eseguito (chiamata commentata). |
| LockedTime() | database.php:263-291 | PDO | Usa $this->query (PDO). Riferimento a LockedTimeGlobal() commentato (274). | Nessuno. |
| LockedTimeGlobal() | database.php:292-319 | mysqli | Imposta $this->conn_ipcheck = mysqli_connect(...). Usa mysqli_query, mysqli_fetch_array. SQL con concatenazione: `WHERE ip='".$this->ip."'` — rischio injection. | **Mai chiamato** (solo riferimento commentato in LockedTime). Se chiamato: possibile SQL injection, mysqli con credenziali placeholder. |
| conn_ipcheck | database.php | Non dichiarato | Proprietà non presente nella dichiarazione della classe (righe 40-52). Creata dinamicamente solo in LockedTimeGlobal(). | AddGlobalLog e ClearLog (blocco commentato) la usano senza che sia mai impostata. |
| Costanti IP_* | constants.php:32-36 | Placeholder | IP_SERVER=localhost, IP_USER/IP_PASS/IP_NAME con valori placeholder. | Con IPLOCK_ATTIVO=1, connessione mysqli a DB "inserire nome database" fallirebbe. |

**Riepilogo:** IPLOCK_ATTIVO=0 in constants.php — tutto il blocco lock IP è disattivato. Con IPLOCK_ATTIVO=1, IsLocked() e LockedTimeGlobal() userebbero mysqli verso un DB separato; AddGlobalLog() è codice morto (chiamata commentata, conn_ipcheck mai impostato).

---

## SEZIONE B — CHIAMANTI

| Metodo/Property | File:riga | Tipo uso | Frequenza | Decisione |
|-----------------|-----------|----------|-----------|-----------|
| IsLocked() | database.php:120 | Chiamato da confirmUserPass() | 1 | Attivo quando IPLOCK_ATTIVO=1 |
| AddGlobalLog() | database.php:196 | Chiamata commentata | 0 | Codice morto |
| LockedTimeGlobal() | database.php:274 | Chiamata commentata in LockedTime() | 0 | Codice morto |
| LockedTime() | index.php:66 | if ($database->LockedTime() > 0) | 1 | Attivo |
| LockedTime() | service_router.php:30 | if ($database->LockedTime() > 0 \|\| ...) | 1 | Attivo |
| LockedTime() | ajax.php:23, 73 | if ($database->LockedTime() > 0 \|\| ...) | 2 | Attivo |
| LockedTime() | MainPage/login.php:7 | if ($database->LockedTime() > 0) | 1 | Attivo |
| LockedTime() | views/protocollo_email.php:4 | if ($database->LockedTime() > 0) | 1 | Attivo |
| LockedTime() | locked.php:3 | $TempoResiduo = $database->LockedTime() | 1 | Attivo |
| conn_ipcheck | database.php:222,226,242,248,251,259 | Usato in AddGlobalLog e ClearLog (commentato) | 0 attivo | Mai impostato; AddGlobalLog non chiamato |

**Conclusione:** Solo IsLocked() e LockedTime() sono usati. AddGlobalLog e LockedTimeGlobal sono morti. conn_ipcheck non è mai impostato in flussi attivi.

---

## SEZIONE C — CLASSIFICAZIONE

| Blocco | Categoria | Motivo |
|--------|-----------|--------|
| IsLocked() — ramo mysqli (righe 150-157) | DA MIGRARE SUBITO | Unico blocco mysqli attivo; con IPLOCK_ATTIVO=1 crea connessione separata. Migrare a PDO con DSN da IP_* per coerenza. |
| IsLocked() — ramo PDO (righe 162-167) | — | Già PDO, nessuna modifica. |
| AddGlobalLog() | CODICE MORTO DA RIMUOVERE | Mai chiamato, conn_ipcheck mai impostato, SQL injection. |
| LockedTimeGlobal() | CODICE MORTO DA RIMUOVERE | Mai chiamato, riferimento commentato. |
| ClearLog() — blocco commentato (219-227) | CODICE MORTO DA RIMUOVERE | Commentato, usa conn_ipcheck inesistente. |
| LockedTime() | DA LASCIARE SEPARATO MA PULIRE | Già PDO. Rimuovere commenti a LockedTimeGlobal. |
| conn_ipcheck | CODICE MORTO DA RIMUOVERE | Non dichiarato, usato solo in codice morto. |
| Costanti IP_* | DA RIMANDARE | Configurazione: valutare se servono con lock unificato. |

---

## SEZIONE D — PIANO MINIMO CONSIGLIATO

### Opzione A — Migrazione a PDO del blocco globale (consigliata)
1. In IsLocked(), sostituire mysqli con PDO usando DSN da IP_SERVER, IP_USER, IP_PASS, IP_NAME.
2. Rimuovere AddGlobalLog(), LockedTimeGlobal(), blocco commentato in ClearLog(), ogni riferimento a conn_ipcheck.
3. Correggere SQL in IsLocked() (riga 151): `WHERE (ip=? AND (log>=? OR perman>=?))` con parametri bind.
4. Impatto: un solo layer PDO, nessuna proprietà conn_ipcheck.

### Opzione B — Mantenere mysqli e pulire
1. Dichiarare `var $conn_ipcheck;` e inizializzarla in __construct() quando IPLOCK_ATTIVO=1 (mysqli_connect).
2. Rimuovere AddGlobalLog() e LockedTimeGlobal() (codice morto).
3. Rimuovere blocco commentato in ClearLog().
4. Parametrizzare le query in AddGlobalLog se si decide di riattivarla (attualmente morta).

### Opzione C — Solo rimozione codice morto
1. Eliminare AddGlobalLog(), LockedTimeGlobal(), blocco commentato in ClearLog().
2. Lasciare IsLocked() com’è (mysqli + PDO).
3. Impatto minimo; il dualismo mysqli/PDO resta.

**Raccomandazione:** Opzione A — migrare il ramo mysqli di IsLocked() a PDO e rimuovere tutto il codice morto. Con IPLOCK_ATTIVO=0 non cambia il comportamento; con IPLOCK_ATTIVO=1 si ha un solo layer PDO e meno debito tecnico.
