<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    die();
}

require_once __DIR__ . '/../components/extra_auth.php';

// Solo admin
if (!userHasPermission('view_gestione_ruoli')) {
    echo "<p>Non hai i permessi per accedere a questa pagina.</p>";
    exit;
}
?>

<div class="main-container">
    <div>
        <?php renderPageTitle("Reset password utenti", "#2C3E50"); ?>
        <div>
            <input type="text" id="search-user" placeholder="Cerca utente (nome, email...)"
                style="margin-bottom:10px; width:250px;">
            <button id="reset-selected-btn" class="button">Resetta password selezionati</button>
            <button id="invite-selected-btn" class="button" style="margin-left:8px;">Invita selezionati</button>
        </div>
        <table class="table table-filterable" id="users-table" style="margin-top:10px;" data-page-size="50">
            <thead>
                <tr>
                    <th class="azioni-colonna" style="width:130px;">Azioni<br><input type="checkbox" id="select-all">
                    </th>
                    <th>Nominativo</th>
                    <th>Email aziendale</th>
                    <th>Stato profilo</th>
                    <th>Password temporanea</th>
                </tr>
            </thead>
            <tbody id="users-tbody">
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', async function () {
        const tbody = document.getElementById('users-tbody');
        const selectAll = document.getElementById('select-all');
        const resetSelectedBtn = document.getElementById('reset-selected-btn');
        const searchInput = document.getElementById('search-user');

        let utenti = [];
        let selectedUserIds = new Set();

        async function caricaUtenti() {
            const res = await customFetch('user', 'getUserList');
            if (res.success && Array.isArray(res.users)) {
                utenti = res.users;
                renderRows();
            }
        }

        function renderRows() {
            const search = searchInput.value.toLowerCase();
            tbody.innerHTML = '';
            (utenti || []).filter(u =>
                (u.Nominativo && u.Nominativo.toLowerCase().includes(search)) ||
                (u.Email_Aziendale && u.Email_Aziendale.toLowerCase().includes(search))
            ).forEach(u => {
                const isChecked = selectedUserIds.has(u.user_id.toString());
                const tr = document.createElement('tr');
                tr.innerHTML = `
    <td class="azioni-colonna" style="text-align:left;">
        <span style="display:inline-block;vertical-align:middle;">
            <input type="checkbox" class="user-checkbox" data-id="${u.user_id}" ${isChecked ? 'checked' : ''} style="vertical-align:middle;margin-right:6px;">
        </span>
        <span style="display:inline-flex;gap:5px;vertical-align:middle;">
            <button class="action-icon email-edit-btn" data-id="${u.user_id}" data-tooltip="Modifica email">
                <img src="assets/icons/edit.png" alt="Modifica">
            </button>
            <button class="action-icon email-save-btn" data-id="${u.user_id}" data-tooltip="Salva email" style="display:none;">
                <img src="assets/icons/save.png" alt="Salva">
            </button>
            <button class="action-icon email-cancel-btn" data-id="${u.user_id}" data-tooltip="Annulla modifica" style="display:none;">
                <img src="assets/icons/close.png" alt="Annulla">
            </button>
            <button class="reset-btn button action-icon" data-id="${u.user_id}" data-nome="${u.Nominativo}" data-tooltip="Reset password" style="padding:2px 5px;">🔑</button>
            <button class="invite-btn button action-icon" data-id="${u.user_id}" data-nome="${u.Nominativo}" data-email="${u.Email_Aziendale}" data-tooltip="Invia invito" style="padding:2px 5px;">✉️</button>
        </span>
    </td>
    <td>${u.Nominativo || ''}</td>
    <td style="vertical-align:middle;">
        <span class="email-label">${u.Email_Aziendale || ''}</span>
        <input type="email" 
            value="${u.Email_Aziendale ? u.Email_Aziendale.replace(/"/g, '&quot;') : ''}" 
            class="email-input"
            data-id="${u.user_id}"
            style="width:180px;padding:4px 6px;display:none;"
            autocomplete="off"
        >
    </td>
    <td>
        ${u.attivato_il
                        ? `<span style="color:green">Attivato<br><small>${u.attivato_il.replace(' ', '<br>')}</small></span>`
                        : `<span style="color:#d55">Non attivo</span>`
                    }
    </td>
    <td class="temp-password" data-id="${u.user_id}">
        <span class="password-text"></span>
        <img src="assets/icons/copy.png" alt="Copia password" class="action-icon copy-btn" data-tooltip="Copia per email" style="display:none; margin-left:7px;">
    </td>
`;

                tbody.appendChild(tr);
            });

            // Aggancia gli handler ai nuovi checkbox dopo il render!
            tbody.querySelectorAll('.user-checkbox').forEach(cb => {
                cb.addEventListener('change', function () {
                    const id = this.dataset.id;
                    if (this.checked) {
                        selectedUserIds.add(id);
                    } else {
                        selectedUserIds.delete(id);
                    }
                    // Aggiorna lo stato del select-all in base alla selezione
                    const allVisibleChecked = Array.from(tbody.querySelectorAll('.user-checkbox')).every(cb => cb.checked);
                    selectAll.checked = allVisibleChecked;
                });
            });

            // Aggiorna lo stato di "select all" dopo il render
            const allVisibleChecked = Array.from(tbody.querySelectorAll('.user-checkbox')).length > 0 &&
                Array.from(tbody.querySelectorAll('.user-checkbox')).every(cb => cb.checked);
            selectAll.checked = allVisibleChecked;
        }

        searchInput.addEventListener('input', function () {
            renderRows();
        });

        selectAll.addEventListener('change', function () {
            tbody.querySelectorAll('.user-checkbox').forEach(cb => {
                cb.checked = selectAll.checked;
                const id = cb.dataset.id;
                if (selectAll.checked) {
                    selectedUserIds.add(id);
                } else {
                    selectedUserIds.delete(id);
                }
            });
        });

        tbody.addEventListener('click', async function (e) {
            if (e.target.classList.contains('reset-btn')) {
                const btn = e.target;
                const userId = btn.dataset.id;
                const nome = btn.dataset.nome;
                btn.disabled = true;
                btn.textContent = 'Attendi...';
                const res = await customFetch('contacts', 'resetUserPassword', { user_id: userId });
                btn.disabled = false;
                btn.textContent = 'Reset';

                const td = btn.closest('tr').querySelector('.temp-password');
                if (res.success && res.password) {
                    const testo = `La tua nuova password temporanea è: '${res.password}'. Sei pregato di cambiarla al primo accesso nella tua area personale di Gestione Profilo.`;
                    td.querySelector('.password-text').textContent = res.password;
                    const copyBtn = td.querySelector('.copy-btn');
                    copyBtn.style.display = 'inline-block';
                    copyBtn.setAttribute('data-msg', testo);
                } else {
                    td.querySelector('.password-text').textContent = '';
                    const copyBtn = td.querySelector('.copy-btn');
                    copyBtn.style.display = 'none';
                    copyBtn.setAttribute('data-msg', '');
                }
            }

            if (e.target.classList.contains('invite-btn')) {
                const btn = e.target;
                const userId = btn.dataset.id;
                const nome = btn.dataset.nome;
                const email = btn.dataset.email;

                btn.disabled = true;
                btn.textContent = 'Invio...';
                // Chiamata AJAX per invio invito
                const res = await customFetch('user', 'inviaInvito', { user_id: userId });

                if (res && res.success) {
                    showToast("Invito inviato a " + (nome || email), "success");
                    btn.textContent = 'Inviato!';
                    setTimeout(() => btn.textContent = 'Invita', 3000);
                } else {
                    showToast("Errore invio invito: " + (res?.message || "Errore"), "error");
                    btn.textContent = 'Invita';
                }
                btn.disabled = false;
            }

            // EDIT EMAIL
            const editBtn = e.target.closest('.email-edit-btn');
            if (editBtn) {
                const userId = editBtn.dataset.id;
                const tr = editBtn.closest('tr');
                tr.querySelector('.email-label').style.display = "none";
                tr.querySelector('.email-input').style.display = "inline-block";
                tr.querySelector('.email-edit-btn').style.display = "none";
                tr.querySelector('.email-save-btn').style.display = "inline-block";
                tr.querySelector('.email-cancel-btn').style.display = "inline-block";
                tr.querySelector('.email-input').focus();
            }

            // SAVE
            const saveBtn = e.target.closest('.email-save-btn');
            if (saveBtn) {
                const userId = saveBtn.dataset.id;
                const tr = saveBtn.closest('tr');
                const input = tr.querySelector('.email-input');
                const email = input.value.trim();

                if (!email.match(/^[^@\s]+@[^@\s]+\.[^@\s]+$/)) {
                    showToast('Email non valida', 'error');
                    input.style.background = "#ffe0e0";
                    setTimeout(() => input.style.background = "", 1500);
                    input.focus();
                    return;
                }

                saveBtn.disabled = true;

                const res = await customFetch('user', 'updateEmail', { user_id: userId, email });
                saveBtn.disabled = false;

                if (res.success) {
                    input.style.background = "#e2ffe2";
                    tr.querySelector('.email-label').textContent = email;
                    // Esci da edit mode
                    tr.querySelector('.email-label').style.display = "inline";
                    input.style.display = "none";
                    tr.querySelector('.email-edit-btn').style.display = "inline-block";
                    tr.querySelector('.email-save-btn').style.display = "none";
                    tr.querySelector('.email-cancel-btn').style.display = "none";
                } else {
                    input.style.background = "#ffe0e0";
                    showToast(res.message || 'Errore nel salvataggio', 'error');
                }
                setTimeout(() => input.style.background = "", 1500);
            }

            // CANCEL
            const cancelBtn = e.target.closest('.email-cancel-btn');
            if (cancelBtn) {
                const userId = cancelBtn.dataset.id;
                const tr = cancelBtn.closest('tr');
                const label = tr.querySelector('.email-label');
                const input = tr.querySelector('.email-input');
                input.value = label.textContent.trim();
                // Torna a view
                label.style.display = "inline";
                input.style.display = "none";
                tr.querySelector('.email-edit-btn').style.display = "inline-block";
                tr.querySelector('.email-save-btn').style.display = "none";
                tr.querySelector('.email-cancel-btn').style.display = "none";
            }

            if (e.target.classList.contains('copy-btn')) {
                const msg = e.target.getAttribute('data-msg');
                if (!msg) return;
                navigator.clipboard.writeText(msg).then(function () {
                    e.target.textContent = '✅';
                    setTimeout(() => {
                        e.target.textContent = '📋';
                    }, 1500);
                }, function () {
                    alert('Errore durante la copia negli appunti.');
                });
            }
        });

        resetSelectedBtn.addEventListener('click', async function () {
            const selected = Array.from(document.querySelectorAll('.user-checkbox:checked'));
            if (selected.length === 0) return alert('Seleziona almeno un utente!');
            for (let cb of selected) {
                const userId = cb.dataset.id;
                const res = await customFetch('contacts', 'resetUserPassword', { user_id: userId });
                tbody.querySelector('.temp-password[data-id="' + userId + '"]').innerHTML = res.success && res.password
                    ? `<code>${res.password}</code>`
                    : `<span style="color:red">${res.message || 'Errore'}</span>`;
            }
        });

        const inviteSelectedBtn = document.getElementById('invite-selected-btn');
        inviteSelectedBtn.addEventListener('click', async function () {
            const selected = Array.from(document.querySelectorAll('.user-checkbox:checked'));
            if (selected.length === 0) return alert('Seleziona almeno un utente!');
            inviteSelectedBtn.disabled = true;
            inviteSelectedBtn.textContent = 'Invio in corso...';

            for (let cb of selected) {
                const userId = cb.dataset.id;
                // Evidenzia la riga
                const row = cb.closest('tr');
                row.style.opacity = 0.6;
                let btn = row.querySelector('.invite-btn');
                if (btn) {
                    btn.textContent = 'Invio...';
                    btn.disabled = true;
                }
                const res = await customFetch('user', 'inviaInvito', { user_id: userId });
                if (btn) {
                    btn.textContent = (res && res.success) ? 'Inviato!' : 'Invita';
                    btn.disabled = false;
                }
                row.style.opacity = 1;
                if (res && res.success) {
                    showToast("Invito inviato", "success");
                } else {
                    showToast("Errore invio invito: " + (res?.message || "Errore"), "error");
                }
            }

            // Attendi 5 secondi prima di riabilitare il bottone
            setTimeout(() => {
                inviteSelectedBtn.disabled = false;
                inviteSelectedBtn.textContent = 'Invita selezionati';
            }, 5000);
        });

        await caricaUtenti();
    });
</script>

<style>
    .custom-table {
        width: 100%;
        border-collapse: collapse;
    }

    .custom-table th,
    .custom-table td {
        border: 1px solid #ddd;
        padding: 7px;
        text-align: left;
    }

    .custom-table th {
        background: #f1f1f1;
    }
</style>