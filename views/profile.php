<div class="main-container">
    <!-- Intestazione del Profilo -->
    <div class="profile-header">
        <div class="profile-image-container">
            <img id="profile-image" src="assets/images/default_profile.png" alt="Foto Profilo" class="profile-image">
            <h1 class="profile-username"><?php echo htmlspecialchars($user['username']); ?></h1>
        </div>
    </div>

    <?php
    // Determina la scheda attiva dal parametro URL
    $activeTab = $_GET['tab'] ?? 'personal-info';
    ?>

    <!-- Campo nascosto per l'ID utente -->
    <input type="hidden" id="userId" value="<?php echo htmlspecialchars($user['id']); ?>">

    <!-- Navigazione tra le schede -->
    <div class="tab">
        <button class="tab-link <?php echo $activeTab === 'personal-info' ? 'active' : ''; ?>" data-tab="personal-info">Informazioni Personali</button>
        <button class="tab-link <?php echo $activeTab === 'password' ? 'active' : ''; ?>" data-tab="password">Cambio Password</button>
        <button class="tab-link <?php echo $activeTab === 'bio' ? 'active' : ''; ?>" data-tab="bio">Bio</button>
        <button class="tab-link <?php echo $activeTab === 'competenze' ? 'active' : ''; ?>" data-tab="competenze">Competenze</button>
    </div>

    <!-- Contenuto delle schede -->
    <div class="tabcontent <?php echo $activeTab === 'personal-info' ? 'active' : ''; ?>" id="personal-info">
        <form id="updatePersonalInfoForm" class="profile-form">
            <div>
                <label for="nominativo">Nominativo:</label>
                <input type="text" id="nominativo" name="nominativo" value="<?php echo htmlspecialchars($user['nominativo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div>
                <label for="email_aziendale">Email Aziendale:</label>
                <input type="email" id="email_aziendale" name="email_aziendale" value="<?php echo htmlspecialchars($user['email_aziendale'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div>
                <label for="luogo_nascita">Luogo di Nascita:</label>
                <input type="text" id="luogo_nascita" name="luogo_nascita" value="<?php echo htmlspecialchars($user['luogo_nascita'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div>
                <label for="data_nascita">Data di Nascita:</label>
                <input type="date" id="data_nascita" name="data_nascita" value="<?php echo htmlspecialchars($user['data_nascita'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly>
            </div>
            <div>
                <label for="indirizzo">Indirizzo:</label>
                <input type="text" id="indirizzo" name="indirizzo" value="<?php echo htmlspecialchars($user['indirizzo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label for="telefono_personale">Telefono Personale:</label>
                <input type="text" id="telefono_personale" name="telefono_personale" value="<?php echo htmlspecialchars($user['telefono_personale'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <button type="submit" class="button">Aggiorna Informazioni</button>
            </div>
        </form>
    </div>

    <div class="tabcontent <?php echo $activeTab === 'password' ? 'active' : ''; ?>" id="password">
        <!-- Contenitore per i messaggi di errore/successo -->
        <div id="password-messages"></div>

        <form id="changePasswordForm">
            <div>
                <label for="current-password">Password Attuale:</label>
                <input type="password" id="current-password" name="current-password" required>
            </div>
            <div>
                <label for="new-password">Nuova Password:</label>
                <input type="password" id="new-password" name="new-password" required>
            </div>
            <div>
                <label for="confirm-new-password">Conferma Nuova Password:</label>
                <input type="password" id="confirm-new-password" name="confirm-new-password" required>
            </div>
            <button type="submit" class="button">Cambia Password</button>
        </form>
    </div>

    <div class="tabcontent <?php echo $activeTab === 'bio' ? 'active' : ''; ?>" id="bio">
        <form action="index.php?page=update_bio" method="post">
            <div>
                <label for="bio">La tua Bio:</label>
                <textarea id="bio" name="bio" rows="5" required><?php echo htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div>
                <button type="submit" class="button">Aggiorna Bio</button>
            </div>
        </form>
    </div>

    <div class="tabcontent <?php echo $activeTab === 'competenze' ? 'active' : ''; ?>" id="competenze">
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
</div>

<!-- Inclusione del file JS -->
<script src="assets/js/gestione_profilo.js"></script>
