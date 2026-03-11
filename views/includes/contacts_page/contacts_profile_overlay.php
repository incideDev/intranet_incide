<?php
// Protezione da accesso diretto
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    die();
}
?>

<!-- Contacts Profile Overlay - Isolato, copre solo .main-container -->
<div id="contacts-profile-overlay" class="contacts-overlay contacts-overlay--hidden">
    <!-- Header sticky -->
    <header class="contacts-overlay__header">
        <button type="button" class="contacts-overlay__back" id="contacts-overlay-close" aria-label="Chiudi profilo">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            <span>Indietro</span>
        </button>
        <h1 class="contacts-overlay__title" id="contacts-overlay-title">Profilo</h1>
    </header>

    <!-- Body scrollabile -->
    <div class="contacts-overlay__body">
        <!-- Hero section -->
        <section class="contacts-profile__hero">
            <div class="contacts-profile__avatar-wrap">
                <img id="cp-image" class="contacts-profile__avatar" alt="Foto Profilo" src="/assets/images/default_profile.png">
            </div>
            <div class="contacts-profile__hero-info">
                <h2 id="cp-fullname" class="contacts-profile__name">—</h2>
                <p id="cp-company" class="contacts-profile__company">—</p>
                <p id="cp-work-duration" class="contacts-profile__tenure">—</p>
                <p id="cp-hire-date" class="contacts-profile__hire-date">—</p>
            </div>
            <div class="contacts-profile__hero-contact">
                <p id="cp-email-container"><img src="assets/icons/mail.png" alt="" class="contacts-profile__icon"><span id="cp-email">—</span></p>
                <p id="cp-mobile-container"><img src="assets/icons/cellulare.png" alt="" class="contacts-profile__icon"><span id="cp-mobile">—</span></p>
                <p id="cp-phone-container"><img src="assets/icons/telefono.png" alt="" class="contacts-profile__icon"><span id="cp-phone">—</span></p>
            </div>
        </section>

        <!-- Sezione Bio -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">About Me</h3>
            <p id="cp-bio" class="contacts-profile__bio">Nessuna bio disponibile</p>
        </section>

        <!-- Sezione Info personali -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">Contatti & Info</h3>
            <div class="contacts-profile__grid">
                <div class="contacts-profile__grid-item">
                    <span class="contacts-profile__label">Data di Nascita</span>
                    <span id="cp-birthdate" class="contacts-profile__value">—</span>
                </div>
                <div class="contacts-profile__grid-item">
                    <span class="contacts-profile__label">Luogo di Nascita</span>
                    <span id="cp-birthplace" class="contacts-profile__value">—</span>
                </div>
                <div class="contacts-profile__grid-item">
                    <span class="contacts-profile__label">Reparto</span>
                    <span id="cp-department" class="contacts-profile__value">—</span>
                </div>
            </div>
        </section>

        <!-- Grid 2 colonne: Organizzazione (1fr) + Ruoli (2fr) -->
        <div class="contacts-profile__grid-sections">
            <!-- Sezione Organizzazione -->
            <section class="contacts-profile__section">
                <h3 class="contacts-profile__section-title">Organizzazione</h3>
                <div id="cp-organization-container" class="contacts-profile__organization">—</div>
            </section>

            <!-- Sezione Ruoli -->
            <section class="contacts-profile__section">
                <h3 class="contacts-profile__section-title">Ruoli</h3>
                <div id="cp-roles-container" class="contacts-profile__roles">—</div>
            </section>
        </div>

        <!-- Sezione Commesse Attive -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">Commesse Attive</h3>
            <div id="cp-projects-container" class="contacts-profile__projects">Nessuna commessa attiva.</div>
        </section>

        <!-- Sezione Collabora spesso con -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">Collabora spesso con</h3>
            <div id="cp-coworkers-container" class="contacts-profile__coworkers">Nessun collaboratore frequente.</div>
        </section>

        <?php if (userHasPermission('view_competenze_profilo')): ?>
        <!-- Sezione Competenze -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">Competenze</h3>
            <div id="cp-skills-container" class="contacts-profile__skills">Nessuna competenza assegnata.</div>
        </section>
        <?php endif; ?>

        <!-- Sezione Curriculum -->
        <section class="contacts-profile__section">
            <h3 class="contacts-profile__section-title">Curriculum</h3>
            <button type="button" id="cp-toggle-cv-btn" class="contacts-profile__cv-btn">Mostra Curriculum</button>
            <iframe id="cp-pdf-preview" class="contacts-profile__cv-frame contacts-profile__cv-frame--hidden"></iframe>
            <p id="cp-no-cv-message" class="contacts-profile__cv-message contacts-profile__cv-message--hidden">Curriculum non disponibile</p>
        </section>
    </div>
</div>
