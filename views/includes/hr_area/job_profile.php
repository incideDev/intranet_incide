<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
    <div class="job-profile-container">
        <h2>Definizione Job Profile</h2>
        <button class="btn-add" onclick="openAddJobProfileModal()">+ Aggiungi Job Profile</button>

        <table id="jobProfileTable" class="hr-table">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Figura</th>
                    <th>Descrizione</th>
                    <th>Dipartimento</th>
                    <th>Skill Tecnici</th>
                    <th>Soft Skill</th>
                    <th>Sede di Lavoro</th>
                    <th>Inquadramento</th>
                    <th>Data Creazione</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($jobProfiles)) : ?>
                    <?php foreach ($jobProfiles as $profile) : ?>
                        <tr>
                            <td><?= $profile['title'] !== null ? htmlspecialchars($profile['title']) : '' ?></td>
                            <td><?= $profile['position_level'] !== null ? htmlspecialchars($profile['position_level']) : '' ?></td>
                            <td><?= $profile['description'] !== null ? htmlspecialchars($profile['description']) : '' ?></td>
                            <td><?= $profile['department'] !== null ? htmlspecialchars($profile['department']) : '' ?></td>
                            <td><?= $profile['technical_skills'] !== null ? htmlspecialchars($profile['technical_skills']) : '' ?></td>
                            <td><?= $profile['soft_skills'] !== null ? htmlspecialchars($profile['soft_skills']) : '' ?></td>
                            <td><?= $profile['work_location'] !== null ? htmlspecialchars($profile['work_location']) : '' ?></td>
                            <td><?= $profile['job_grade'] !== null ? htmlspecialchars($profile['job_grade']) : '' ?></td>
                            <td><?= $profile['created_at'] !== null ? htmlspecialchars($profile['created_at']) : '' ?></td>
                            <td>
                                <button class="btn-action btn-edit" onclick="editJobProfile(<?= $profile['id'] ?>)">Modifica</button>
                                <button class="btn-action btn-archive" onclick="archiveJobProfile(<?= $profile['id'] ?>)">Archivia</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="10">Nessun profilo di lavoro trovato.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal per aggiungere un nuovo Job Profile -->
    <div id="addJobProfileModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span onclick="closeAddJobProfileModal()" class="close">&times;</span>
            <h2>Aggiungi Job Profile</h2>
            <form id="addJobProfileForm" method="post" action="index.php?page=hr_area&section=job_profile&action=add">
                <label for="title">Titolo:</label>
                <input type="text" name="title" id="title" required>

                <label for="position_level">Figura (Junior/Middle/Senior):</label>
                <input type="text" name="position_level" id="position_level" required>

                <label for="description">Descrizione:</label>
                <textarea name="description" id="description" required></textarea>

                <label for="department">Dipartimento:</label>
                <input type="text" name="department" id="department" required>

                <label for="technical_skills">Skill Tecnici:</label>
                <textarea name="technical_skills" id="technical_skills"></textarea>

                <label for="soft_skills">Soft Skill:</label>
                <textarea name="soft_skills" id="soft_skills"></textarea>

                <label for="work_location">Sede di Lavoro:</label>
                <input type="text" name="work_location" id="work_location" required>

                <label for="job_grade">Inquadramento:</label>
                <input type="text" name="job_grade" id="job_grade">

                <label for="legal_disclaimer">Disclaimer legale:</label>
                <textarea name="legal_disclaimer" id="legal_disclaimer" readonly>
    La ricerca si intende rivolta ai candidati di ambo i sessi ai sensi delle Leggi 903/1977 e 125/1991 e D.Lgs. 198/2006.
                </textarea>
                <button class="btn-submit" type="submit">Salva</button>
            </form>
        </div>
    </div>
</div>
<!-- Inclusione del file JavaScript dedicato -->
<script src="assets/js/job_profile.js"></script>
