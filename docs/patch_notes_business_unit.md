# Patch Notes: Gestione Ore → Business Unit

**Data**: 2026-03-02
**Feature**: Nuova pagina "Business Unit" nella sezione "Gestione Ore"

---

## Riepilogo Modifiche

### File Creati (3)

| File | Descrizione |
|------|-------------|
| `views/ore_business_unit.php` | View PHP della nuova pagina |
| `assets/js/ore_business_unit.js` | Frontend JS completo (filtri, KPI, pie charts, trend SVG, tabelle) |
| `assets/css/ore_business_unit.css` | CSS dedicato (tabs BU, pie charts, trend) |

### File Modificati (3)

| File | Modifiche |
|------|-----------|
| `core/functions.php` | Menu: "Dashboard Ore" → "Gestione Ore" con submenu; Breadcrumb per ore_business_unit |
| `services/DashboardOreService.php` | +2 metodi: `getBusinessUnitData()`, `getBusinessUnitTrend()` |
| `service_router.php` | +2 actions: `getBusinessUnitData`, `getBusinessUnitTrend` |

---

## Dettaglio Tecnico

### 1. Menu/Routing (core/functions.php)

**Prima:**
```php
userHasPermission('view_dashboard_ore') ? [
    'title' => 'Dashboard Ore',
    'link' => 'index.php?section=commesse&page=dashboard_ore'
] : null,
```

**Dopo:**
```php
userHasPermission('view_dashboard_ore') ? [
    'title' => 'Gestione Ore',
    'submenus' => [
        ['title' => 'Dashboard Ore', 'link' => 'index.php?section=commesse&page=dashboard_ore'],
        ['title' => 'Business Unit', 'link' => 'index.php?section=commesse&page=ore_business_unit']
    ]
] : null,
```

### 2. Backend (DashboardOreService.php)

**Nuovi Endpoint:**

#### `getBusinessUnitData(input)`
- **Input**: `year`, `month`, `pmId`, `projectId`, `buCode`
- **Output**:
  ```json
  {
    "success": true,
    "data": {
      "bus": [{"code": "BU-...", "name": "..."}],
      "filters": {
        "years": [2026, 2025, ...],
        "months": [{"value": "01", "label": "Gennaio"}, ...],
        "pms": [{"id": "...", "name": "..."}],
        "projects": [{"id": "...", "code": "...", "bu": "..."}]
      },
      "rows": [
        {
          "bu": "BU-ARCHITETTURA",
          "ym": "2026-01",
          "projectId": "...",
          "projectCode": "...",
          "resourceId": "...",
          "resourceName": "...",
          "resourceRole": "...",
          "wh": 20.5,
          "eh": 40.0,
          "projectStatus": "Aperta"
        }
      ]
    }
  }
  ```

#### `getBusinessUnitTrend(input)`
- **Input**: `year`, `buCode`
- **Output**:
  ```json
  {
    "success": true,
    "data": [
      {"ym": "2026-01", "label": "Jan", "wh": 1200, "eh": 1500},
      ...
    ]
  }
  ```

### 3. Query DB Riusate

| Tabella | Colonne | Uso |
|---------|---------|-----|
| `project_time` | `projectTimeDate`, `idBusinessUnit`, `idProject`, `idHResource`, `resourceDesc`, `workHours` | Ore imputate (wh) |
| `project_time_budget` | `budgetDate`, `idBusinessUnit`, `idProject`, `idHResource`, `budgetTotalHours` | Ore budget (eh) |
| `commesse` | `Codice`, `Stato` | Stato commessa (Aperta/Chiusa) |

### 4. Frontend JS

**Pattern copiati da dashboard_ore.js:**
- `window.customFetch('dashboard_ore', action, params, { showLoader: false })`
- CSRF token da `sessionStorage.getItem('CSRFtoken')` (gestito automaticamente da customFetch)
- `data-tooltip` per tooltip (gestito da main_core.js)

**Funzionalità:**
- Filtri: Anno, Mese, PM, Commessa + BU tabs
- KPI cards: Ore imputate, Ore budget, Risorse attive, Avanzamento
- Pie charts SVG: Top 8 commesse, Top 8 risorse
- Trend mensile SVG: wh vs eh
- Tabelle: Commesse (aggregato), Risorse (aggregato)
- Export CSV client-side

### 5. CSS

Riusa classi da `dashboard_ore.css`:
- `.dboard-page-header`, `.dboard-filter-bar`, `.dboard-kpi-grid`, `.dboard-card`, etc.

Nuove classi in `ore_business_unit.css`:
- `.orebu-tabs`, `.orebu-tab` - Tabs selezione BU
- `.orebu-pie-*` - Pie charts SVG
- `.orebu-trend-*` - Trend SVG
- `.orebu-kpi-grid` - Grid 4 colonne

---

## Sicurezza

- **Permesso**: riusa `view_dashboard_ore`
- **CSRF**: header `X-Csrf-Token` (gestito da customFetch)
- **Session**: check `userHasPermission()` in ogni metodo service
- **Sanitizzazione**: `filter_var(..., FILTER_SANITIZE_*)` su tutti gli input

---

## Test Manuale

1. **Sidebar**: verificare che "Gestione Ore" sia presente con submenu funzionante
2. **Pagina**: `index.php?section=commesse&page=ore_business_unit`
3. **Filtri**: Anno, Mese, PM, Commessa → Applica/Reset
4. **BU Tabs**: click su una BU → filtra dati
5. **KPI**: valori coerenti con filtri
6. **Pie charts**: hover mostra tooltip
7. **Trend**: grafico SVG con punti interattivi
8. **Tabelle**: ordinate per ore decrescenti
9. **Export CSV**: download funzionante

---

## Note Architetturali

- Nessun jQuery introdotto
- Nessuna nuova tabella DB
- Pattern identico a dashboard_ore per consistenza
- SVG inline per pie/trend (no librerie esterne)
