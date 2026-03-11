<div class="main-container">
    <div id="notification" class="notification"></div>
    <!-- Tabella degli utenti esistenti -->
    <h2>Utenti Esistenti</h2>
    <table>
    <thead>
        <tr>
            <th class="azioni-colonna">Azioni</th>
            <th>Username</th>
            <th>Email</th>
            <th>Ruolo</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($allUsers as $user): ?>
            <tr>
                <td class="azioni-colonna">
                    <a href="#" onclick="deleteUser(<?php echo $user['id']; ?>, this); return false;">Elimina</a>
                </td>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td>
                    <select onchange="updateUserRole(<?php echo $user['id']; ?>, this.value)">
    <?php foreach ($roles as $role): ?>
        <option value="<?php echo $role['id']; ?>" <?php echo $user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($role['name']); ?>
        </option>
    <?php endforeach; ?>
</select>

                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

    <!-- Tabella degli utenti problematici -->
    <h2>Utenti Problematici</h2>
    <table>
        <thead>
            <tr>
                <th>Nominativo</th>
                <th>Email</th>
                <th>Codice Operatore</th>
                <th>Username</th>
                <th>Azione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($problematicUsers as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['Nominativo'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($user['Email_Aziendale'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($user['Cod_Operatore'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <input 
                            type="text" 
                            name="username-<?php echo htmlspecialchars($user['Cod_Operatore'], ENT_QUOTES, 'UTF-8'); ?>" 
                            id="username-<?php echo htmlspecialchars($user['Cod_Operatore'], ENT_QUOTES, 'UTF-8'); ?>" 
                            placeholder="Inserisci username"
                            value=""
                        >
                    </td>
                    <td>
                        <button 
                            onclick="resolveUserInline('<?php echo htmlspecialchars($user['Cod_Operatore'], ENT_QUOTES, 'UTF-8'); ?>')">
                            Risolvi
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="assets/js/manage_users.js"></script>
