<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    die();
}

if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
    header('HTTP/1.0 403 Forbidden');
    include("page-errors/403.php");
    exit;
}
?>

<div class="main-container">
    <div class="dashboard-impostazioni-wrapper">
        <div class="flex-header" style="margin-bottom:20px;">
            <div>
                <h1 class="dashboard-title">Database Whitelist</h1>
                <p class="dashboard-desc">
                    Gestisci quali tabelle e colonne sono accessibili dal Page Editor.
                </p>
            </div>
        </div>

        <div class="ds-list-container">
            <input type="text" id="tableSearch" placeholder="Cerca tabella..." class="form-control"
                style="width:100%; padding:10px; margin-bottom:15px; border:1px solid #ddd; border-radius:4px;">

            <table class="table-list" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8f9fa; border-bottom:2px solid #ddd; text-align:left;">
                        <th style="padding:12px; width:40px;"></th> <!-- Expand icon -->
                        <th style="padding:12px;">Nome Tabella</th>
                        <th style="padding:12px; text-align:center;">Visibile nel Page Editor</th>
                        <th style="padding:12px;">Colonne</th>
                    </tr>
                </thead>
                <tbody id="dsTableBody">
                    <tr>
                        <td colspan="4" style="padding:20px; text-align:center;">Caricamento tabelle...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<template id="tpl-columns-row">
    <tr class="columns-row" style="background:#fcfcfc; display:none;">
        <td colspan="4" style="padding:0;">
            <div style="padding:15px 15px 15px 60px; border-bottom:1px solid #eee;">
                <div style="margin-bottom:10px; font-weight:600; font-size:13px; color:#555;">Seleziona colonne
                    visibili:</div>
                <div class="columns-grid"
                    style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:10px;">
                    <!-- Checkboxes inseriti via JS -->
                </div>
                <div style="margin-top:15px; text-align:right;">
                    <button class="button small primary btn-save-cols">Salva modifica colonne</button>
                </div>
            </div>
        </td>
    </tr>
</template>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.getElementById('dsTableBody');
        const searchInput = document.getElementById('tableSearch');
        const tplCols = document.getElementById('tpl-columns-row');

        let allTables = [];
        const expandedRows = new Set(); // store table names expanded

        loadTables();

        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            renderList(allTables.filter(t => t.name.toLowerCase().includes(term)));
        });

        async function loadTables() {
            try {
                const res = await customFetch('datasource', 'adminListTables');
                if (res.success) {
                    allTables = res.tables;
                    renderList(allTables);
                } else {
                    tableBody.innerHTML = `<tr><td colspan="4" class="error">Errore: ${res.message}</td></tr>`;
                }
            } catch (e) {
                console.error(e);
                tableBody.innerHTML = `<tr><td colspan="4" class="error">Errore caricamento</td></tr>`;
            }
        }

        function renderList(list) {
            if (!list.length) {
                tableBody.innerHTML = `<tr><td colspan="4" style="padding:20px; text-align:center;">Nessuna tabella trovata.</td></tr>`;
                return;
            }

            tableBody.innerHTML = '';
            list.forEach(item => {
                // Main Row
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #eee';

                const isExp = expandedRows.has(item.name);
                const toggleIcon = isExp ? '▼' : '▶';

                tr.innerHTML = `
                <td style="padding:12px; text-align:center; cursor:pointer; color:#999;" class="expander">${toggleIcon}</td>
                <td style="padding:12px; font-family:monospace; font-size:14px; font-weight:600;">${item.name}</td>
                <td style="padding:12px; text-align:center;">
                    <label class="switch-generic">
                        <input type="checkbox" class="toggle-active" ${item.is_active ? 'checked' : ''}>
                        <span class="slider round"></span>
                    </label>
                </td>
                <td style="padding:12px; font-size:12px; color:#666;">
                    ${item.has_column_rules ? '<span style="color:#d35400;">Filtro colonne attivo</span>' : 'Tutte visibili'}
                </td>
            `;

                // Events
                tr.querySelector('.expander').onclick = () => toggleExpand(item.name);
                tr.querySelector('.toggle-active').onchange = (e) => toggleTableActive(item.name, e.target.checked);

                tableBody.appendChild(tr);

                // Detail Row (se espanso)
                if (isExp) {
                    const detailClone = tplCols.content.cloneNode(true);
                    const detailTr = detailClone.querySelector('tr');
                    detailTr.style.display = 'table-row';
                    detailTr.setAttribute('data-table', item.name);

                    const grid = detailClone.querySelector('.columns-grid');
                    const btnSave = detailClone.querySelector('.btn-save-cols');

                    tableBody.appendChild(detailTr);

                    // Carica colonne
                    loadColumns(item.name, grid, btnSave);
                }
            });
        }

        function toggleExpand(tableName) {
            if (expandedRows.has(tableName)) expandedRows.delete(tableName);
            else expandedRows.add(tableName);
            renderList(allTables); // Re-render per semplicitÃ 
        }

        async function toggleTableActive(table, active) {
            try {
                const res = await customFetch('datasource', 'adminToggleTable', { table, active });
                if (!res.success) {
                    showToast(res.message || 'Errore', 'error');
                    loadTables(); // Revert
                } else {
                    // Aggiorna stato locale
                    const t = allTables.find(x => x.name === table);
                    if (t) t.is_active = active;
                    showToast(`Tabella ${active ? 'attivata' : 'disattivata'}`, 'success');
                }
            } catch (e) { showToast('Errore di rete', 'error'); }
        }

        async function loadColumns(table, container, btnSave) {
            container.innerHTML = 'Caricamento colonne...';
            try {
                const res = await customFetch('datasource', 'adminListColumns', { table });
                if (res.success) {
                    container.innerHTML = '';
                    const cols = res.columns || [];

                    // Genera checkbox
                    cols.forEach(c => {
                        const label = document.createElement('label');
                        label.style.display = 'flex';
                        label.style.alignItems = 'center';
                        label.style.gap = '6px';
                        label.style.fontSize = '12px';
                        label.style.cursor = 'pointer';

                        const chk = document.createElement('input');
                        chk.type = 'checkbox';
                        chk.checked = c.is_active;
                        chk.value = c.name;
                        chk.className = 'col-check';

                        label.append(chk, document.createTextNode(c.name));
                        container.appendChild(label);
                    });

                    // Attach Save Event
                    btnSave.onclick = () => saveColumns(table, container);

                } else {
                    container.innerHTML = 'Errore: ' + res.message;
                }
            } catch (e) {
                container.innerHTML = 'Errore caricamento colonne';
            }
        }

        async function saveColumns(table, container) {
            const checkboxes = container.querySelectorAll('.col-check');
            const visible = [];
            let allChecked = true;

            checkboxes.forEach(c => {
                if (c.checked) visible.push(c.value);
                else allChecked = false;
            });

            // Se tutte checkate, mandiamo null per dire "tutte" (cleaner db)
            const payload = allChecked ? null : visible;

            try {
                const res = await customFetch('datasource', 'adminUpdateColumns', { table, columns: payload });
                if (res.success) {
                    showToast('Configurazione colonne salvata', 'success');
                    // Aggiorna stato "has_column_rules" nella lista principale
                    const t = allTables.find(x => x.name === table);
                    if (t) t.has_column_rules = !allChecked;
                    renderList(allTables); // Refresh UI
                } else {
                    showToast(res.message || 'Errore salvataggio', 'error');
                }
            } catch (e) { showToast('Errore salvataggio', 'error'); }
        }
    });
</script>
<style>
    .switch-generic {
        position: relative;
        display: inline-block;
        width: 34px;
        height: 20px;
    }

    .switch-generic input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        -webkit-transition: .4s;
        transition: .4s;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 12px;
        width: 12px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        -webkit-transition: .4s;
        transition: .4s;
    }

    input:checked+.slider {
        background-color: #2196F3;
    }

    input:focus+.slider {
        box-shadow: 0 0 1px #2196F3;
    }

    input:checked+.slider:before {
        -webkit-transform: translateX(14px);
        -ms-transform: translateX(14px);
        transform: translateX(14px);
    }

    .slider.round {
        border-radius: 34px;
    }

    .slider.round:before {
        border-radius: 50%;
    }
</style>