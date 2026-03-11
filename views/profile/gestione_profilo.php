<?php
$profileImage = $_SESSION['user']['profile_picture'] ?? 'assets/images/default_profile.png';
$user = $_SESSION['user'] ?? [];
$activeTab = $_GET['tab'] ?? 'personal-info';

// Recupera la bio aggiornata direttamente dal DB
$userId = $_SESSION['user_id'] ?? null;
$bio = '';
if ($userId) {
    $stmt = $database->query("SELECT bio FROM personale WHERE user_id = ?", [$userId], __FILE__);
    $bio = $stmt->fetchColumn();
}

?>

<div class="main-container">
    <!-- Intestazione del Profilo -->
    <div class="profile-header">
        <div class="profile-image-container">
            <img id="profile-image" src="<?= htmlspecialchars($profileImage) ?>" alt="Foto Profilo" class="profile-image">
            <h1 class="profile-username"><?= htmlspecialchars($user['username'] ?? 'Utente') ?></h1>
        </div>
    </div>

    <!-- Campo nascosto per l'ID utente -->
    <input type="hidden" id="userId" value="<?= htmlspecialchars($user['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <!-- Navigazione tra le schede -->
    <div class="tab">
        <button class="tab-link <?= $activeTab === 'personal-info' ? 'active' : '' ?>" data-tab="personal-info">Informazioni Personali</button>
        <button class="tab-link <?= $activeTab === 'bio' ? 'active' : '' ?>" data-tab="bio">Bio</button>
        <button class="tab-link <?= $activeTab === 'password' ? 'active' : '' ?>" data-tab="password">Cambio Password</button>
        <!-- <button class="tab-link <?= $activeTab === 'competenze' ? 'active' : '' ?>" data-tab="competenze">Competenze</button> -->
    </div>

    <!-- Contenuto delle schede -->
    <div class="tabcontent <?= $activeTab === 'personal-info' ? 'active' : '' ?>" id="personal-info">
        <form id="updatePersonalInfoForm" class="profile-form">
            <fieldset>
                <legend>Dati anagrafici</legend>
                <div class="fieldset-grid">
                    <div>
                        <label for="Luogo_di_Nascita">Luogo di Nascita:</label>
                        <input type="text" id="Luogo_di_Nascita" name="Luogo_di_Nascita" value="<?= htmlspecialchars($user['Luogo_di_Nascita'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Data_di_Nascita">Data di Nascita:</label>
                        <input type="date" id="Data_di_Nascita" name="Data_di_Nascita" value="<?= htmlspecialchars($user['Data_di_Nascita'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Codice_Fiscale">Codice Fiscale:</label>
                        <input type="text" id="Codice_Fiscale" name="Codice_Fiscale" value="<?= htmlspecialchars(strtoupper($user['Codice_Fiscale'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </fieldset>
            <fieldset>
                <legend>Contatti</legend>
                <div class="fieldset-grid">
                    <div>
                        <label for="Email_Personale">Email personale:</label>
                        <input type="email" id="Email_Personale" name="Email_Personale" value="<?= htmlspecialchars($user['Email_Personale'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Cellulare_Personale">Cellulare personale:</label>
                        <input type="text" id="Cellulare_Personale" name="Cellulare_Personale" value="<?= htmlspecialchars($user['Cellulare_Personale'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Cellulare_Aziendale">Cellulare aziendale:</label>
                        <input type="text" id="Cellulare_Aziendale" name="Cellulare_Aziendale" value="<?= htmlspecialchars($user['Cellulare_Aziendale'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Telefono_Personale">Telefono personale:</label>
                        <input type="text" id="Telefono_Personale" name="Telefono_Personale" value="<?= htmlspecialchars($user['Telefono_Personale'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </fieldset>
            <fieldset>
                <legend>Indirizzo</legend>
                <div class="fieldset-grid">
                    <div>
                        <label for="Indirizzo">Indirizzo:</label>
                        <input type="text" id="Indirizzo" name="Indirizzo" value="<?= htmlspecialchars($user['Indirizzo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="CAP">CAP:</label>
                        <input type="text" id="CAP" name="CAP" value="<?= htmlspecialchars($user['CAP'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Citt">Città:</label>
                        <input type="text" id="Citt" name="Citt" value="<?= htmlspecialchars($user['Citt'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Provincia">Provincia:</label>
                        <input type="text" id="Provincia" name="Provincia" value="<?= htmlspecialchars($user['Provincia'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="Nazione">Nazione:</label>
                        <input type="text" id="Nazione" name="Nazione" value="<?= htmlspecialchars($user['Nazione'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </fieldset>
            <fieldset>
                <legend>Altri dati</legend>
                <div class="fieldset-grid">
                    <div>
                        <label for="Titolo_di_Studio">Titolo di studio:</label>
                        <input type="text" id="Titolo_di_Studio" name="Titolo_di_Studio" value="<?= htmlspecialchars($user['Titolo_di_Studio'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </fieldset>
            <button type="submit" class="button">Aggiorna Informazioni</button>
        </form>
    </div>

    <div class="tabcontent <?= $activeTab === 'bio' ? 'active' : '' ?>" id="bio">
        <form id="updateBioForm" class="profile-form">
            <fieldset>
                <legend>La tua Bio</legend>
                <div class="fieldset-grid">
                    <div style="grid-column: 1 / -1;">
                        <label for="bio">Bio:</label>
                        <textarea id="bio_textarea" name="bio" rows="5" required><?= htmlspecialchars($bio ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </fieldset>
            <button type="submit" class="button">Aggiorna Bio</button>
        </form>
    </div>

    <div class="tabcontent <?= $activeTab === 'password' ? 'active' : '' ?>" id="password">
        <div id="password-messages"></div>
        <form id="changePasswordForm" class="profile-form">
            <input type="hidden" id="token-csrf" name="csrf_token" value="<?= htmlspecialchars($_SESSION['CSRFtoken'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <fieldset>
                <legend>Cambio Password</legend>
                <div class="fieldset-grid">
                    <div>
                        <label for="currentPassword">Password Attuale:</label>
                        <input type="password" id="currentPassword" name="currentPassword" required>
                    </div>
                    <div>
                        <label for="newPassword">Nuova Password:</label>
                        <input type="password" id="newPassword" name="newPassword" required>
                    </div>
                    <div>
                        <label for="confirmNewPassword">Conferma Nuova Password:</label>
                        <input type="password" id="confirmNewPassword" name="confirmNewPassword" required>
                    </div>
                </div>
            </fieldset>
            <button type="submit" class="button">Cambia Password</button>
        </form>
    </div>

<!--
<div class="tabcontent <?= $activeTab === 'competenze' ? 'active' : '' ?>" id="competenze">
    <form id="addCompetenceForm">
        <div>
            <label for="areaSelect">Seleziona Area:</label>
            <select id="areaSelect" required>
                <option value="">-- Seleziona un'area --</option>
            </select>
        </div>
        <div class="inline-container">
            <div class="select-container">
                <label for="competenceSelect">Seleziona Competenza:</label>
                <select id="competenceSelect" required>
                    <option value="">-- Seleziona una competenza --</option>
                </select>
            </div>
            <div class="level-container">
                <label for="competenceLevel">Seleziona Livello:</label>
                <input type="range" id="competenceLevel" min="1" max="3" step="1" value="1" />
                <span id="levelIndicator">1</span>
            </div>
            <div>
                <button type="submit" class="button">Aggiungi Competenza</button>
            </div>
        </div>
    </form>
    <h3>Competenze Associate</h3>
    <div id="userCompetencesContainer" class="competences-container">
        <!-- Tag delle competenze -->
        </div>
</div>
-->

</div>
