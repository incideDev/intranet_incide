<?php

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti'))
    return;

$search = filter_input(INPUT_POST, 'search', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$department = filter_input(INPUT_POST, 'department', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
$competence = filter_input(INPUT_POST, 'competence', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
$competenceArea = filter_input(INPUT_POST, 'competence-area', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? null;
$competenceMix = $_POST['competence-mix'] ?? [];
$ageMin = filter_input(INPUT_POST, 'age-min', FILTER_SANITIZE_NUMBER_INT) ?? null;
$ageMax = filter_input(INPUT_POST, 'age-max', FILTER_SANITIZE_NUMBER_INT) ?? null;
$experienceYears = filter_input(INPUT_POST, 'experience-years', FILTER_SANITIZE_NUMBER_INT) ?? null;
?>

<link rel="stylesheet" href="/assets/css/contacts-overlay.css">

<div class="main-container" style="position: relative;">
    <?php include 'includes/contacts_page/contacts_profile_overlay.php'; ?>

    <div class="content-layout">
        <!-- Sezione Contatti -->
        <div class="contacts-container" id="contacts-container">
            <?php if (isset($contacts) && is_array($contacts) && count($contacts) > 0): ?>
                <?php foreach ($contacts as $contact): ?>
                    <div class="contact-card" data-contact="<?= htmlspecialchars(json_encode($contact), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="contact-icon">
                            <img src="<?= isset($contact['profile_picture']) && $contact['profile_picture'] ? (strpos($contact['profile_picture'], 'data:') === 0 ? $contact['profile_picture'] : '/' . ltrim($contact['profile_picture'], '/')) : '/assets/images/default_profile.png' ?>"
                                data-nominativo="<?= htmlspecialchars($contact['Nominativo'], ENT_QUOTES, 'UTF-8') ?>"
                                class="profile-img" width="50" height="50"
                                alt="Immagine di <?= htmlspecialchars($contact['Nominativo'], ENT_QUOTES, 'UTF-8') ?>"
                                loading="lazy">
                        </div>
                        <div class="contact-details">
                            <h3><img src="assets/icons/contact.png" class="icon">
                                <?= htmlspecialchars($contact['Nominativo']) ?></h3>
                            <p><img src="assets/icons/mail.png" class="icon">
                                <?= htmlspecialchars($contact['Email_Aziendale'] ?? 'N/D') ?></p>
                            <p><img src="assets/icons/telefono.png" class="icon">
                                <?= htmlspecialchars($contact['phone'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Nessun contatto trovato.</p>
            <?php endif; ?>
        </div>

        <div class="filters-container filters-contacts">
            <form id="filters-form-full">
                <input type="hidden" name="page" value="contacts">

                <div class="filter-group">
                    <label for="search">Cerca (nome o email):</label>
                    <input type="text" name="search" id="search" class="filter-input"
                        placeholder="Inserisci nome o email">
                </div>

                <div class="filter-group">
                    <label for="department">Reparto:</label>
                    <select name="department" id="department" class="filter-input">
                        <option value="">Tutti</option>
                        <?php foreach ($departments as $dep): ?>
                            <?php if (isset($dep['Reparto'])):
                                $val = htmlspecialchars($dep['Reparto']);
                                $selected = ($department === $dep['Reparto']) ? 'selected' : '';
                                ?>
                                <option value="<?= $val ?>" <?= $selected ?>><?= $val ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="roles-search">Ruoli (AND logic):</label>
                    <div class="roles-multi-container">
                        <!-- Token input con dropdown -->
                        <div class="roles-token-input">
                            <input
                                id="roles-search"
                                type="text"
                                class="filter-input"
                                autocomplete="off"
                                placeholder="Aggiungi ruolo..."
                                aria-label="Cerca ruoli"
                                aria-expanded="false"
                                aria-controls="roles-dropdown">
                            <button
                                type="button"
                                class="roles-token-toggle"
                                aria-label="Apri elenco ruoli"
                                tabindex="-1">
                                ▾
                            </button>
                            <div id="roles-dropdown" class="roles-dropdown" hidden></div>
                        </div>

                        <!-- Storage canonico (select hidden) -->
                        <select name="roles[]" id="roles" multiple style="display: none;">
                            <?php foreach ($roles as $r): ?>
                                <?php if (isset($r['id_hrrole']) && isset($r['hr_role_desc'])):
                                    $id = htmlspecialchars($r['id_hrrole']);
                                    $desc = htmlspecialchars($r['hr_role_desc']);
                                    ?>
                                    <option value="<?= $id ?>"><?= $desc ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>

                        <!-- Badge selezionati -->
                        <div id="selected-roles-badges" class="selected-roles-badges"></div>
                    </div>
                    <small class="roles-hint" style="color: #6b7280; font-size: 11px;">Clicca per selezionare, rimuovi con ✕</small>
                </div>

                <div class="filter-group">
                    <label for="area">Area/Business Unit:</label>
                    <select name="area" id="area" class="filter-input">
                        <option value="">Tutte</option>
                        <?php foreach ($areas as $a): ?>
                            <?php if (isset($a['Area']) && $a['Area']):
                                $val = htmlspecialchars($a['Area']);
                                ?>
                                <option value="<?= $val ?>"><?= $val ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php foreach ($businessUnits as $bu): ?>
                            <?php if (isset($bu['BusinessUnit']) && $bu['BusinessUnit']):
                                $val = htmlspecialchars($bu['BusinessUnit']);
                                ?>
                                <option value="<?= $val ?>"><?= $val ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="project">Commessa Attiva:</label>
                    <select name="project" id="project" class="filter-input">
                        <option value="">Tutte</option>
                        <?php foreach ($activeProjects as $proj): ?>
                            <?php if (isset($proj['code']) && isset($proj['name'])):
                                $code = htmlspecialchars($proj['code']);
                                $name = htmlspecialchars($proj['name']);
                                ?>
                                <option value="<?= $code ?>"><?= $code ?> - <?= $name ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="seniority">Anzianità:</label>
                    <select name="seniority" id="seniority" class="filter-input">
                        <option value="">Tutte</option>
                        <option value="0-1">Meno di 1 anno</option>
                        <option value="1-3">1-3 anni</option>
                        <option value="3-5">3-5 anni</option>
                        <option value="5-10">5-10 anni</option>
                        <option value="10+">Più di 10 anni</option>
                    </select>
                </div>

                <div class="buttons-container">
                    <button class="button" type="submit">Applica Filtri</button>
                    <button class="button button-secondary" type="button" id="reset-filters">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Contacts Profile Overlay Script -->
    <script src="/assets/js/contacts.js" defer></script>
</div>