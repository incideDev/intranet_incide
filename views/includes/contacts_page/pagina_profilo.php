<?php
// Protezione da accesso diretto
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    die();
}
?>

<div id="profile-modal" class="modal large-modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('profile-modal')">&times;</span>
        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-header-left">
                    <img id="profile-image" alt="Foto Profilo" class="profile-image-large">
                    <div class="profile-header-info">
                        <h1 id="profile-fullname">Mario Rossi</h1>
                        <p id="profile-company">INCIDE ENGINEERING S.R.L.</p>
                        <p id="profile-work-duration">Lavora con noi da: N/A</p>
                        <p id="profile-hire-date" style="font-size: 0.9em; color: #666;">Assunto dal: N/A</p>
                    </div>
                </div>
                <div class="profile-header-right">
                    <div class="contact-info">
                        <p><img src="assets/icons/mail.png" alt="Email Icon" class="icon"><span id="profile-email">N/A</span></p>
                        <p><img src="assets/icons/cellulare.png" alt="Cellulare Icon" class="icon"><span id="profile-mobile">N/A</span></p>
                        <p><img src="assets/icons/telefono.png" alt="Telefono Icon" class="icon"><span id="profile-phone">N/A</span></p>
                    </div>
                </div>
            </div>

            <div class="profile-section">
                <h2>About Me</h2>
                <p id="profile-bio">Nessuna bio disponibile</p>
            </div>

            <div class="profile-section">
                <h2>Contatti & Info</h2>
                <div class="profile-info-grid">
                    <div><label>Data di Nascita:</label><p id="profile-birthdate">N/A</p></div>
                    <div><label>Luogo di Nascita:</label><p id="profile-birthplace">N/A</p></div>
                    <div><label>Reparto:</label><p id="profile-department">N/A</p></div>
                    <div>
                        <label>Ruoli:</label>
                        <div id="profile-roles-container">N/A</div>
                    </div>
                </div>
            </div>

            <div class="profile-section">
                <h2>Organizzazione</h2>
                <div id="profile-organization-container">N/A</div>
            </div>

            <div class="profile-section">
                <h2>Commesse Attive</h2>
                <div id="profile-projects-container">Nessuna commessa attiva.</div>
            </div>

            <div class="profile-section">
                <h2>Collabora spesso con</h2>
                <div id="profile-coworkers-container">Nessun collaboratore frequente.</div>
            </div>

            <?php if (userHasPermission('view_competenze_profilo')): ?>
                <div class="profile-section">
                    <h2>Competenze</h2>
                    <div class="profile-info-grid">
                        <div><p id="skills-container">Nessuna competenza assegnata.</p></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="profile-section">
                <h2>Curriculum</h2>
                <button id="toggle-cv-btn" onclick="toggleCv()">Mostra Curriculum</button>
                <iframe id="pdf-preview" style="display: none;"></iframe>
                <p id="no-cv-message" style="display: none;">Nessun curriculum disponibile</p>
            </div>
        </div>
    </div>
</div>
