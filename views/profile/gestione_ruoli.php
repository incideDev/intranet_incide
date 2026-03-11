<?php 
if (!checkPermissionOrWarn('view_gestione_ruoli')) return;

$activeTab = $_GET['tab'] ?? 'gestione';
?>

<div class="main-container">
    <?php renderPageTitle("Gestione Ruoli", "#2C3E50"); ?>

    <!-- Navigazione Tab -->
    <div class="tab">
        <button class="tab-link <?= $activeTab === 'gestione' ? 'active' : '' ?>" data-tab="tab-gestione">Gestisci Ruoli</button>
        <button class="tab-link <?= $activeTab === 'assegnazione' ? 'active' : '' ?>" data-tab="tab-assegnazione">Assegna Ruoli</button>
    </div>

    <!-- Contenuto Tab: Gestione -->
    <div id="tab-gestione" class="tabcontent <?= $activeTab === 'gestione' ? 'active' : '' ?>">
        <div class="gestione-ruoli-wrapper">
            <!-- Sidebar Ruoli -->
            <div class="ruoli-sidebar">
                <h3>Ruoli</h3>
                <ul id="ruoli-list" class="ruoli-list" style="list-style: none; padding: 0; margin: 0;"></ul>
                <button id="btn-nuovo-ruolo" class="button" style="margin-top: 15px;">+ Nuovo Ruolo</button>
            </div>

            <!-- Dettaglio Ruolo -->
            <div id="ruolo-editor" class="ruoli-dettaglio" style="display: none;">
                <h3 id="ruolo-titolo">Nessun ruolo selezionato</h3>

                <input type="text" id="ruolo-nome" class="input-full" placeholder="Nome ruolo..."/>
                <textarea id="ruolo-descrizione" class="input-full" placeholder="Descrizione ruolo..."></textarea>

                <h4>Permessi disponibili <span id="permessi-contatore" style="font-size: 0.85em; color: #666; font-weight: normal;">(Selezionati: 0)</span></h4>
                <div id="permessi-container" class="permessi-checkbox-group"></div>

                <button id="salva-ruolo" class="button" style="margin-top: 20px;">Salva Ruolo</button>
            </div>
        </div>
    </div>

    <!-- Contenuto Tab: Assegnazione -->
    <div id="tab-assegnazione" class="tabcontent <?= $activeTab === 'assegnazione' ? 'active' : '' ?>">
        <div class="table-wrapper" style="margin-bottom: 15px;">
            <table class="table table-filterable" id="user-role-table">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" id="select-all-users">
                        </th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                    </tr>
                </thead>
                <tbody id="user-role-body"></tbody>
            </table>
        </div>
        <div class="mass-role-toolbar" style="display: flex; gap: 10px; align-items: center;">
            <select id="mass-role-select" class="input-full" style="max-width: 250px;">
                <option value="">— Assegna ruolo —</option>
            </select>
            <button id="apply-mass-role" class="button">Applica a selezionati</button>
        </div>
    </div>
</div>
