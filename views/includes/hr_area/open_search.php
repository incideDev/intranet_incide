<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
<div class="open-search-container">
    <h2>Apertura Ricerca</h2>
    <button class="btn-add" onclick="openAddSearchModal()">Aggiungi Apertura Ricerca</button>

    <table id="openSearchTable" class="hr-table">
        <thead>
            <tr>
                <th>Profilo di Lavoro</th>
                <th>Data Pubblicazione</th>
                <th>Durata</th>
                <th>Stato</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($openSearchData)) : ?>
                <?php foreach ($openSearchData as $search) : ?>
                    <tr>
                        <td><?= htmlspecialchars($search['profile_title'] ?? 'Non specificato') ?></td>
                        <td><?= htmlspecialchars($search['publication_date'] ?? 'Non specificata') ?></td>
                        <td>
                            <?= htmlspecialchars($search['start_date'] ?? 'Data non definita') ?> - 
                            <?= htmlspecialchars($search['end_date'] ?? 'Data non definita') ?>
                        </td>
                        <td><?= htmlspecialchars($search['status'] ?? 'Non disponibile') ?></td>
                        <td>
                            <button class="btn-action btn-edit" onclick="editOpenSearch(<?= $search['id'] ?>)">Modifica</button>
                            <button class="btn-action btn-archive" onclick="closeOpenSearch(<?= $search['id'] ?>)">Chiudi</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="5">Nessuna apertura di ricerca trovata.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="addOpenSearchModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span onclick="closeAddOpenSearchModal()" class="close">&times;</span>
        <h2>Avvia Campagna di Ricerca</h2>
        <form id="addOpenSearchForm" method="post" action="index.php?page=hr_area&section=open_search&action=add">
        <label for="job_profile_id">Profilo Lavorativo:</label>
        <select name="job_profile_id" id="job_profile_id" required>
    <option value="">Seleziona un profilo</option>
    <?php if (!empty($jobProfiles)): ?>
        <?php foreach ($jobProfiles as $profile): ?>
            <option value="<?= htmlspecialchars($profile['id']) ?>">
                <?= htmlspecialchars($profile['title']) ?>
            </option>
        <?php endforeach; ?>
    <?php else: ?>
        <option value="">Nessun profilo disponibile</option>
    <?php endif; ?>
</select>

            <label for="platforms">Piattaforme di Pubblicazione:</label>
            <div id="platforms" class="platforms">
                <input type="checkbox" name="platforms[]" value="LinkedIn"> LinkedIn
                <input type="checkbox" name="platforms[]" value="Indeed"> Indeed
                <input type="checkbox" name="platforms[]" value="Monster"> infoJobs
                <input type="checkbox" name="platforms[]" value="Glassdoor"> inRecruit
            </div>
            <!-- Durata della Campagna -->
            <label for="publication_date">Data di Pubblicazione:</label>
            <input type="date" name="publication_date" id="publication_date" required>

            <label for="start_date">Inizio Campagna:</label>
            <input type="date" name="start_date" id="start_date" required>

            <label for="end_date">Fine Campagna:</label>
            <input type="date" name="end_date" id="end_date" required>

            <!-- Budget Campagna -->
            <label for="budget">Budget Campagna (€):</label>
            <input class="custom-budget-slider" type="range" name="budget" id="budget" min="0" max="10000" step="100" oninput="document.getElementById('budgetDisplay').textContent = this.value">
            <span id="budgetDisplay">500</span> €

            <!-- Responsabile Campagna -->
            <label for="campaign_manager">Responsabile Campagna:</label>
            <select name="campaign_manager" id="campaign_manager">
                <option value="">Seleziona il responsabile</option>
                <option value="1">Responsabile 1</option>
                <option value="2">Responsabile 2</option>
                <!-- Popola con i responsabili reali -->
            </select>

            <!-- Canali Interni di Pubblicazione -->
            <label for="internal_channels">Canali Interni di Pubblicazione:</label>
            <div id="internal_channels" class="internal_channels">
                <input type="checkbox" name="internal_channels[]" value="Intranet"> Intranet
                <input type="checkbox" name="internal_channels[]" value="Email Aziendale"> Email Aziendale
            </div>

            <!-- Obiettivo di Candidati -->
            <label for="candidate_target">Obiettivo di Candidati:</label>
            <input type="number" name="candidate_target" id="candidate_target" min="1">

            <!-- Data Obiettivo per Assunzione -->
            <label for="hiring_target_date">Data Obiettivo per Assunzione:</label>
            <input type="date" name="hiring_target_date" id="hiring_target_date">

            <!-- Notifiche Automatiche -->
            <label for="automatic_notifications">Notifiche Automatiche:</label>
            <input type="checkbox" name="automatic_notifications" value="1"> Abilita Notifiche Automatiche

            <button class="btn-submit" type="submit">Avvia Campagna</button>
        </form>
    </div>
</div>
</div>
<!-- Inclusione del file JavaScript dedicato -->
<script src="assets/js/open_search.js"></script>
