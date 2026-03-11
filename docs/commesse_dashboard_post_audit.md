# Dashboard Commesse - Post-Audit Hardening

**Data**: 2026-02-27
**Versione**: 1.2 (aggiornato con analisi query planner)

---

## Modifiche Applicate

### 1. Performance - Indici Database

**File**: `workbench/migrations/commesse_dashboard_indexes.sql`

| Indice | Colonna | Utilizzo Query Planner |
|--------|---------|------------------------|
| `idx_elenco_commesse_data_creazione` | `data_creazione` | **USATO** per ORDER BY DESC LIMIT |
| `idx_elenco_commesse_stato` | `stato` | **NON USATO** (vedi nota sotto) |

**Esecuzione**:
```sql
-- 1. Verifica versione
SELECT VERSION();

-- 2. Verifica indici esistenti
SHOW INDEX FROM elenco_commesse;

-- 3. Esegui script
SOURCE workbench/migrations/commesse_dashboard_indexes.sql;
```

---

### 2. Robustezza Logica - Normalizzazione Stato

**File**: `service_router.php` (action `getDashboardStats`)

**Problema**: Confronto `stato = 'Chiusa'` era case-sensitive e non gestiva spazi.

**Soluzione**: Condizioni normalizzate:
```sql
-- CHIUSA (robusto)
TRIM(UPPER(stato)) = 'CHIUSA'

-- APERTA (robusto)
stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA'
```

Ora riconosce correttamente: `Chiusa`, `CHIUSA`, `chiusa`, ` Chiusa `, etc.

---

### 3. UX Dati - Risoluzione Responsabile

**File**: `service_router.php` (action `getDashboardStats`)

**Problema**: `responsabile_commessa` può contenere ID numerico o nome testuale.

**Soluzione**:
- Caricata mappa `personale` (user_id → Nominativo) una sola volta
- Helper `$resolveResponsabile()` converte ID in nome
- Applicato a: `byPm[].label`, `latest[].pm`

---

## Analisi Query Planner

### Limitazione Nota: Indice su `stato`

L'indice `idx_elenco_commesse_stato` **NON viene utilizzato** dal query planner perché le query usano:

```sql
WHERE (stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA')
```

Le funzioni `TRIM()` e `UPPER()` applicate alla colonna **impediscono** l'uso dell'indice (MySQL deve valutare la funzione per ogni riga → full table scan).

### Query EXPLAIN Attese

| Query | type | key | Extra |
|-------|------|-----|-------|
| **latest** (ORDER BY data_creazione DESC LIMIT 5) | `index` | `idx_elenco_commesse_data_creazione` | Using where |
| **COUNT aperte** | `ALL` | NULL | Using where |
| **GROUP BY business_unit** | `ALL` | NULL | Using where; Using temporary; Using filesort |

### Impatto Pratico

- **< 10.000 record**: Nessun problema, full scan accettabile
- **10.000 - 50.000 record**: Possibile rallentamento su COUNT/GROUP BY
- **> 50.000 record**: Considerare ottimizzazione (vedi sezione sotto)

---

## Ottimizzazione Futura (se necessaria)

Se le performance diventano critiche, tre opzioni:

### Opzione A: Normalizzare dati esistenti (consigliata)
```sql
UPDATE elenco_commesse SET stato = 'Chiusa'
WHERE TRIM(UPPER(stato)) = 'CHIUSA';

UPDATE elenco_commesse SET stato = 'Aperta'
WHERE stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA';
```
Poi modificare query PHP: `WHERE stato = 'Aperta'`

### Opzione B: Colonna generata (MySQL 5.7+)
```sql
ALTER TABLE elenco_commesse
  ADD COLUMN stato_norm VARCHAR(50) AS (UPPER(TRIM(COALESCE(stato,'')))) STORED;

CREATE INDEX idx_elenco_commesse_stato_norm ON elenco_commesse(stato_norm);
```
Poi modificare query PHP: `WHERE stato_norm <> 'CHIUSA'`

### Opzione C: Collation case-insensitive
```sql
ALTER TABLE elenco_commesse
  MODIFY stato VARCHAR(50) COLLATE utf8mb4_general_ci;
```
Poi query PHP: `WHERE stato <> 'Chiusa'` (senza UPPER)

---

## Checklist Test

### Pre-requisiti
- [ ] MySQL/MariaDB in esecuzione
- [ ] Eseguito script SQL indici

### Test Indici
```sql
-- Verifica indici creati
SHOW INDEX FROM elenco_commesse WHERE Key_name LIKE 'idx_elenco_commesse%';

-- Verifica EXPLAIN query latest (deve mostrare key = idx_elenco_commesse_data_creazione)
EXPLAIN SELECT codice, oggetto, cliente, business_unit, responsabile_commessa, data_creazione
FROM elenco_commesse
WHERE (stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA')
ORDER BY data_creazione DESC LIMIT 5;
```

### Test API
- [ ] Apri dashboard: `index.php?section=commesse&page=dashboard_commesse`
- [ ] Verifica che i KPI mostrino numeri (non "—")
- [ ] Verifica che `byPm` mostri nomi, non ID numerici
- [ ] Verifica che `latest` mostri PM come nomi
- [ ] Apri DevTools → Network → verifica response JSON

### Test Response JSON
```json
{
  "success": true,
  "data": {
    "kpi": {
      "open": <numero>,
      "closed": <numero>,
      "total": <numero>,
      "pmCount": <numero>,
      "sectorCount": <numero>,
      "buCount": <numero>
    },
    "byBu": [{"label": "...", "count": ...}],
    "byPm": [{"label": "Nome Cognome", "count": ..., "initials": "NC"}],
    "bySector": [{"label": "...", "count": ...}],
    "latest": [
      {
        "codice": "...",
        "titolo": "...",
        "cliente": "...",
        "bu": "...",
        "pm": "Nome Cognome",
        "apertura": "dd/mm/yyyy"
      }
    ]
  }
}
```

---

## Rollback

Se necessario ripristinare:

1. **Indici**:
   ```sql
   DROP INDEX idx_elenco_commesse_data_creazione ON elenco_commesse;
   DROP INDEX idx_elenco_commesse_stato ON elenco_commesse;
   ```

2. **Codice**: Ripristinare `service_router.php` da backup/git

---

## File Modificati

| File | Tipo | Descrizione |
|------|------|-------------|
| `workbench/migrations/commesse_dashboard_indexes.sql` | Nuovo | Script indici (v1.1 - rimosso DESC) |
| `service_router.php` | Modificato | Action getDashboardStats |
| `docs/commesse_dashboard_post_audit.md` | Nuovo | Questa documentazione |

---

## Changelog

| Versione | Data | Modifiche |
|----------|------|-----------|
| 1.0 | 2026-02-27 | Implementazione iniziale |
| 1.1 | 2026-02-27 | Hardening: TRIM/UPPER stato, risoluzione PM ID→nome |
| 1.2 | 2026-02-27 | Analisi query planner, fix indice DESC→ASC, documentazione limitazioni |
