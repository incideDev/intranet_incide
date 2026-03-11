<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    die();
}

// Autorizzazione: admin OR view_gestione_intranet
if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
    header('HTTP/1.0 403 Forbidden');
    include("page-errors/403.php");
    exit;
}
?>

<div class="main-container">
    <div class="dashboard-impostazioni-wrapper">
        <h1 class="dashboard-title">Gestione Ruoli Utenti</h1>
        <p class="dashboard-desc">
            Assegna e gestisci i ruoli degli utenti del sistema.<br>
            Cerca un utente e seleziona i ruoli da assegnare.
        </p>

        <div class="ruoli-management-grid">
            <!-- Colonna sinistra: ricerca utenti -->
            <div class="ruoli-search-section">
                <h3>Cerca Utente</h3>
                <div class="search-container">
                    <input type="text" id="userSearch" placeholder="Username o email..." class="form-control">
                    <button id="searchBtn" class="button" data-tooltip="cerca utenti">Cerca</button>
                </div>
                <div id="usersList" class="users-list">
                    <!-- Risultati ricerca -->
                </div>
            </div>

            <!-- Colonna destra: ruoli disponibili -->
            <div class="ruoli-roles-section">
                <h3>Ruoli Disponibili</h3>
                <div id="rolesContainer" class="roles-container">
                    <!-- Checkboxes ruoli verranno caricati via AJAX -->
                </div>

                <!-- Sezione Pagine Page Editor (visibile quando si seleziona un ruolo) -->
                <div id="pageEditorSection" class="page-editor-section" style="display: none;">
                    <h4>Pagine Page Editor</h4>
                    <div id="pageEditorFormsContainer" class="page-editor-forms-container">
                        <!-- Checkboxes pagine verranno caricate via AJAX -->
                    </div>
                </div>

                <div class="actions-container">
                    <button id="saveRolesBtn" class="button primary" data-tooltip="salva assegnazione ruoli" disabled>
                        Salva Assegnazione
                    </button>
                    <div id="saveStatus" class="save-status"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ruoli-management-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-top: 30px;
}

.ruoli-search-section, .ruoli-roles-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.search-container {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.search-container input {
    flex: 1;
}

.users-list {
    max-height: 400px;
    overflow-y: auto;
}

.user-item {
    padding: 10px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    margin-bottom: 8px;
    cursor: pointer;
    background: white;
    transition: background-color 0.2s;
}

.user-item:hover {
    background: #e3f2fd;
}

.user-item.selected {
    background: #2196f3;
    color: white;
    border-color: #2196f3;
}

.user-info {
    font-weight: 500;
}

.user-details {
    font-size: 0.9em;
    opacity: 0.8;
}

.roles-container {
    margin-bottom: 20px;
}

.role-checkbox {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    padding: 8px;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.role-checkbox input[type="checkbox"] {
    margin-right: 10px;
}

.role-info h4 {
    margin: 0 0 4px 0;
    font-size: 1em;
}

.role-info p {
    margin: 0;
    font-size: 0.9em;
    color: #666;
}

.actions-container {
    border-top: 1px solid #dee2e6;
    padding-top: 20px;
}

.save-status {
    margin-top: 10px;
    min-height: 20px;
}

.save-status.success {
    color: #28a745;
}

.save-status.error {
    color: #dc3545;
}

.page-editor-section {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

.page-editor-section h4 {
    margin-bottom: 15px;
    color: #495057;
}

.page-editor-forms-container {
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 15px;
}

.page-editor-checkbox {
    display: flex;
    align-items: flex-start;
    margin-bottom: 8px;
    padding: 8px;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.page-editor-checkbox input[type="checkbox"] {
    margin-right: 10px;
    margin-top: 2px;
}

.page-editor-info h5 {
    margin: 0 0 4px 0;
    font-size: 0.9em;
    font-weight: 600;
}

.page-editor-info p {
    margin: 0;
    font-size: 0.8em;
    color: #666;
}

.loading {
    opacity: 0.6;
    pointer-events: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let selectedUserId = null;
    let selectedRoleId = null;
    let availableRoles = [];
    let availablePageEditorForms = [];

    const userSearch = document.getElementById('userSearch');
    const searchBtn = document.getElementById('searchBtn');
    const usersList = document.getElementById('usersList');
    const rolesContainer = document.getElementById('rolesContainer');
    const pageEditorSection = document.getElementById('pageEditorSection');
    const pageEditorFormsContainer = document.getElementById('pageEditorFormsContainer');
    const saveRolesBtn = document.getElementById('saveRolesBtn');
    const saveStatus = document.getElementById('saveStatus');

    // Carica ruoli disponibili all'avvio
    loadAvailableRoles();
    loadAvailablePageEditorForms();

    // Event listeners
    searchBtn.addEventListener('click', searchUsers);
    userSearch.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchUsers();
        }
    });
    saveRolesBtn.addEventListener('click', saveUserRoles);

    async function loadAvailableRoles() {
        try {
            const response = await customFetch('roles', 'listRoles');
            if (response.success) {
                availableRoles = response.data;
                renderRoles([]);
            } else {
                showStatus('Errore caricamento ruoli: ' + (response.message || 'Sconosciuto'), 'error');
            }
        } catch (error) {
            showStatus('Errore caricamento ruoli', 'error');
            console.error('Error loading roles:', error);
        }
    }

    async function loadAvailablePageEditorForms() {
        if (availablePageEditorForms.length > 0) return; // Cache

        try {
            const response = await customFetch('roles', 'listPageEditorForms');
            if (response.success) {
                availablePageEditorForms = response.data;
            } else {
                console.error('Errore caricamento pagine Page Editor:', response.message);
            }
        } catch (error) {
            console.error('Error loading Page Editor forms:', error);
        }
    }

    async function searchUsers() {
        const query = userSearch.value.trim();
        if (!query) {
            showStatus('Inserisci un termine di ricerca', 'error');
            return;
        }

        try {
            const response = await customFetch('roles', 'searchUsers', { q: query });
            if (response.success) {
                renderUsersList(response.data);
            } else {
                showStatus('Errore ricerca utenti: ' + (response.message || 'Sconosciuto'), 'error');
            }
        } catch (error) {
            showStatus('Errore ricerca utenti', 'error');
            console.error('Error searching users:', error);
        }
    }

    async function loadUserRoles(userId) {
        try {
            const response = await customFetch('roles', 'getUserRoles', { user_id: userId });
            if (response.success) {
                renderRoles(response.data);
            } else {
                showStatus('Errore caricamento ruoli utente: ' + (response.message || 'Sconosciuto'), 'error');
            }
        } catch (error) {
            showStatus('Errore caricamento ruoli utente', 'error');
            console.error('Error loading user roles:', error);
        }
    }

    async function saveUserRoles() {
        if (!selectedUserId) {
            showStatus('Seleziona prima un utente', 'error');
            return;
        }

        const selectedRoles = Array.from(document.querySelectorAll('.role-checkbox input:checked'))
            .map(cb => parseInt(cb.value));

        try {
            saveRolesBtn.disabled = true;
            saveRolesBtn.textContent = 'Salvataggio...';
            saveRolesBtn.classList.add('loading');

            // Salva prima i ruoli dell'utente
            const roleResponse = await customFetch('roles', 'setUserRoles', {
                user_id: selectedUserId,
                role_ids: selectedRoles
            });

            if (!roleResponse.success) {
                showStatus('Errore salvataggio ruoli: ' + (roleResponse.message || 'Sconosciuto'), 'error');
                return;
            }

            // Se è selezionato un ruolo, salva anche le pagine Page Editor
            if (selectedRoleId) {
                const selectedPageForms = Array.from(document.querySelectorAll('.page-editor-checkbox input:checked'))
                    .map(cb => parseInt(cb.value));

                const pageResponse = await customFetch('roles', 'setRolePageEditorPermissions', {
                    role_id: selectedRoleId,
                    form_ids: selectedPageForms
                });

                if (!pageResponse.success) {
                    showStatus('Ruoli salvati, ma errore salvataggio pagine: ' + (pageResponse.message || 'Sconosciuto'), 'error');
                    return;
                }
            }

            showStatus('Ruoli e pagine salvati con successo!', 'success');
            // Ricarica i ruoli dell'utente per confermare
            await loadUserRoles(selectedUserId);
        } catch (error) {
            showStatus('Errore salvataggio', 'error');
            console.error('Error saving:', error);
        } finally {
            saveRolesBtn.disabled = false;
            saveRolesBtn.textContent = 'Salva Assegnazione';
            saveRolesBtn.classList.remove('loading');
        }
    }

    function renderUsersList(users) {
        if (users.length === 0) {
            usersList.innerHTML = '<p>Nessun utente trovato.</p>';
            return;
        }

        usersList.innerHTML = users.map(user => `
            <div class="user-item ${selectedUserId === user.id ? 'selected' : ''}" data-user-id="${user.id}">
                <div class="user-info">${user.username}</div>
                <div class="user-details">${user.email || 'No email'}</div>
            </div>
        `).join('');

        // Event listeners per selezione utente
        document.querySelectorAll('.user-item').forEach(item => {
            item.addEventListener('click', function() {
                const userId = parseInt(this.dataset.userId);
                selectUser(userId);
            });
        });
    }

    function selectUser(userId) {
        selectedUserId = userId;

        // Aggiorna selezione visuale
        document.querySelectorAll('.user-item').forEach(item => {
            item.classList.toggle('selected', parseInt(item.dataset.userId) === userId);
        });

        // Carica ruoli dell'utente
        loadUserRoles(userId);

        // Abilita bottone salva
        saveRolesBtn.disabled = false;

        showStatus('');
    }

    function renderRoles(userRoleIds) {
        if (availableRoles.length === 0) {
            rolesContainer.innerHTML = '<p>Nessun ruolo disponibile.</p>';
            return;
        }

        rolesContainer.innerHTML = availableRoles.map(role => {
            const isChecked = userRoleIds.includes(role.id);
            return `
                <label class="role-checkbox">
                    <input type="radio" name="selectedRole" value="${role.id}" ${isChecked ? 'checked' : ''}>
                    <div class="role-info">
                        <h4>${role.name}</h4>
                        <p>${role.description || 'Nessuna descrizione'}</p>
                    </div>
                </label>
            `;
        }).join('');

        // Event listeners per selezione ruolo
        document.querySelectorAll('.role-checkbox input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const roleId = parseInt(this.value);
                selectRole(roleId);
            });
        });

        // Se c'è un ruolo già selezionato, mostralo
        const checkedRadio = document.querySelector('.role-checkbox input[type="radio"]:checked');
        if (checkedRadio) {
            selectRole(parseInt(checkedRadio.value));
        } else {
            // Nascondi sezione pagine se nessun ruolo selezionato
            pageEditorSection.style.display = 'none';
        }
    }

    async function selectRole(roleId) {
        selectedRoleId = roleId;

        if (availablePageEditorForms.length === 0) {
            await loadAvailablePageEditorForms();
        }

        // Carica permessi pagine per questo ruolo
        try {
            const response = await customFetch('roles', 'getRolePageEditorPermissions', { role_id: roleId });
            if (response.success) {
                renderPageEditorForms(response.data);
                pageEditorSection.style.display = 'block';
            } else {
                showStatus('Errore caricamento permessi pagine ruolo: ' + (response.message || 'Sconosciuto'), 'error');
            }
        } catch (error) {
            showStatus('Errore caricamento permessi pagine ruolo', 'error');
            console.error('Error loading role page permissions:', error);
        }
    }

    function renderPageEditorForms(roleFormIds) {
        if (availablePageEditorForms.length === 0) {
            pageEditorFormsContainer.innerHTML = '<p>Nessuna pagina Page Editor disponibile.</p>';
            return;
        }

        pageEditorFormsContainer.innerHTML = availablePageEditorForms.map(form => {
            const isChecked = roleFormIds.includes(form.id);
            return `
                <label class="page-editor-checkbox">
                    <input type="checkbox" value="${form.id}" ${isChecked ? 'checked' : ''}>
                    <div class="page-editor-info">
                        <h5>${form.name}</h5>
                        <p>${form.description || 'Nessuna descrizione'}</p>
                    </div>
                </label>
            `;
        }).join('');
    }

    function showStatus(message, type = '') {
        saveStatus.textContent = message;
        saveStatus.className = 'save-status ' + type;
    }
});
</script>
