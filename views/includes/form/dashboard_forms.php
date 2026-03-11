<?php
if (!checkPermissionOrWarn('view_segnalazioni'))
    return;

$forms = [];
try {
    // Recupera moduli da menu_custom
    $customMenus = $database->query(
        "SELECT title, link FROM menu_custom 
     WHERE section = 'collaborazione' 
     AND parent_title = 'Segnalazioni' 
     AND attivo = 1 
     AND title != 'Dashboard Segnalazioni'
     ORDER BY ordinamento ASC, title ASC",
        [],
        __FILE__
    );

    $forms = [];
    if ($customMenus) {
        while ($menu = $customMenus->fetch(PDO::FETCH_ASSOC)) {
            // Estrai form_name dal link
            if (preg_match('/[?&]form_name=([^&]+)/', $menu['link'], $matches)) {
                $formName = urldecode($matches[1]);
                $tableName = 'mod_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $formName));

                // Recupera stati custom del form (stessa logica di gestione_segnalazioni.php)
                $formStatiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
                try {
                    $statesResp = \Services\PageEditorService::getFormStates($formName);
                    if (!empty($statesResp['success']) && !empty($statesResp['states']) && is_array($statesResp['states'])) {
                        $formStatiMap = [];
                        $idx = 1;
                        foreach ($statesResp['states'] as $s) {
                            $label = is_array($s) ? ($s['name'] ?? '') : (string) $s;
                            if ($label === '')
                                continue;
                            $formStatiMap[$idx] = $label;
                            $idx++;
                        }
                        if (empty($formStatiMap))
                            $formStatiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
                    }
                } catch (\Throwable $e) {
                    // fallback ai default
                }

                // Conta segnalazioni - aggrega per label (non per status_id numerico)
                $count = 0;
                $statusCounts = ['Aperta' => 0, 'In corso' => 0, 'Chiusa' => 0];
                $tableExists = $database->query("SHOW TABLES LIKE :table", [':table' => $tableName], __FILE__);

                if ($tableExists && $tableExists->rowCount() > 0) {
                    // Escludi subtask (parent_record_id > 0) se la colonna esiste
                    $hasParent = false;
                    try {
                        $colCheck = $database->query("SHOW COLUMNS FROM `$tableName` LIKE 'parent_record_id'", [], __FILE__);
                        $hasParent = $colCheck && $colCheck->rowCount() > 0;
                    } catch (\Throwable $e) {
                    }
                    $whereMain = $hasParent ? " WHERE (parent_record_id IS NULL OR parent_record_id = 0)" : "";

                    $resCount = $database->query("SELECT COUNT(*) as total FROM `$tableName`" . $whereMain, [], __FILE__);
                    $rowCount = $resCount ? $resCount->fetch(PDO::FETCH_ASSOC) : null;
                    $count = (int) ($rowCount['total'] ?? 0);

                    $resStatus = $database->query("SELECT status_id, COUNT(*) as cnt FROM `$tableName`" . $whereMain . " GROUP BY status_id", [], __FILE__);
                    if ($resStatus) {
                        while ($row = $resStatus->fetch(PDO::FETCH_ASSOC)) {
                            $sid = intval($row['status_id']);
                            $label = $formStatiMap[$sid] ?? null;
                            if ($label !== null && array_key_exists($label, $statusCounts)) {
                                $statusCounts[$label] += intval($row['cnt']);
                            }
                        }
                    }
                }

                // Recupera info responsabile e creatore dal form
                $formInfo = $database->query(
                    "SELECT f.responsabile, f.created_at 
                 FROM forms f 
                 WHERE f.name = :name",
                    [':name' => $formName],
                    __FILE__
                );
                $info = $formInfo ? $formInfo->fetch(PDO::FETCH_ASSOC) : null;

                $responsabiliInfo = [];
                $raw_resp = (string) ($info['responsabile'] ?? '');
                $resp_ids = array_filter(explode(',', $raw_resp));
                foreach ($resp_ids as $rid) {
                    $rnome = $database->getNominativoByUserId($rid);
                    if ($rnome) {
                        $rimg = getProfileImage($rnome, 'nominativo');
                        if (!$rimg || $rimg === 'assets/images/default_profile.png') {
                            $rimg = '/assets/images/default_profile.png';
                        } elseif (strpos($rimg, '/') !== 0) {
                            $rimg = '/' . $rimg;
                        }
                        $responsabiliInfo[] = [
                            'id' => $rid,
                            'nome' => $rnome,
                            'img' => $rimg
                        ];
                    }
                }

                $created_at = $info['created_at'] ?? date('Y-m-d');

                $forms[] = [
                    'id' => 'custom_' . md5($formName),
                    'name' => $formName,
                    'description' => 'Modulo: ' . $menu['title'],
                    'total_reports' => $count,
                    'color' => '#cccccc',
                    'created_by' => 'Sistema',
                    'created_by_img' => getProfileImage('Admin Incide', 'nominativo'),
                    'created_at' => $created_at,
                    'responsabili' => $responsabiliInfo,
                    'status_counts' => $statusCounts
                ];
            }
        }
    }

    $res = ['success' => true, 'stats' => $forms];
    if (is_array($res) && !empty($res['stats']) && is_array($res['stats'])) {
        $forms = $res['stats'];
    }
} catch (\throwable $e) {
    $forms = [];
}

?>

<div class="main-container">
    <?php renderPageTitle("Dashboard Segnalazioni", "#C0392B"); ?>
    <!-- Grafico delle segnalazioni -->
    <div class="dashboard-section" style="display: flex; gap: 30px; align-items: flex-start;">
        <div style="flex: 2;">
            <h2>Andamento delle Segnalazioni</h2>
            <canvas id="chartSegnalazioni"></canvas>
        </div>
        <div style="flex: 1;">
            <h2 style="margin-bottom:8px;">Stati Segnalazioni</h2>
            <select id="filterSegnalazione" style="width:100%;margin-bottom:8px;">
                <option value="">Tutte le segnalazioni</option>
                <?php foreach ($forms as $form): ?>
                    <option value="<?= htmlspecialchars($form['id']) ?>">
                        <?= htmlspecialchars($form['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <canvas id="chartStatiSegnalazioni" style="min-width:300px;max-width:400px;"></canvas>
        </div>
    </div>

    <?php if (!empty($forms)): ?>
        <div class="form-grid">
            <?php foreach ($forms as $form): ?>
                <div class="form-preview" data-form-id="<?= $form['id'] ?>"
                    data-form-name="<?= htmlspecialchars($form['name']) ?>" data-id="<?= $form['id'] ?>"
                    style="--form-color: <?= htmlspecialchars($form['color'] ?? '#ccc') ?>;">

                    <div class="form-header">
                        <h3 class="form-title"><?= htmlspecialchars($form['name']) ?></h3>
                    </div>
                    <div class="form-description">
                        <?= isset($form['description']) && !empty($form['description'])
                            ? htmlspecialchars($form['description'])
                            : 'Nessuna descrizione disponibile.'; ?>
                    </div>

                    <div class="form-responsabile-meta" style="display:flex; align-items:center;">
                        <span class="label" style="color:#666; margin-right:8px;">Responsabile:</span>
                        <div class="assignee-avatars-group">
                                    <?php if (!empty($form['responsabili'])): ?>
                                            <?php
                                            $maxVisible = 3;
                                            $visible = array_slice($form['responsabili'], 0, $maxVisible);
                                            $overflow = count($form['responsabili']) - $maxVisible;
                                            foreach ($visible as $idx => $resp):
                                                ?>
                                                    <img src="<?= htmlspecialchars($resp['img']) ?>" alt="<?= htmlspecialchars($resp['nome']) ?>"
                                        title="<?= htmlspecialchars($resp['nome']) ?>" class="assignee-avatar"
                                        style="z-index: <?= count($visible) - $idx ?>;">
                                            <?php endforeach; ?>
                                            <?php if ($overflow > 0): ?>
                                    <span class="assignee-overflow-badge"
                                        title="<?= htmlspecialchars(implode(', ', array_column(array_slice($form['responsabili'], $maxVisible), 'nome'))) ?>">+<?= $overflow ?></span>
                              <?php endif; ?>
                          <?php else: ?>
                                <img src="assets/images/default_profile.png" alt="Responsabile" class="assignee-avatar"
                                    style="z-index: 1;">
                          <?php endif; ?>
                        </div>
                    </div>

                    <div class="total-reports" id="form-count-<?= $form['id']; ?>">
                        <strong>Segnalazioni:</strong> <?= isset($form['total_reports']) ? $form['total_reports'] : 0; ?>
                        <div class="stato-mini-counts" id="form-state-mini-<?= $form['id']; ?>"
                            style="font-size:11px; color:#666; margin-top:4px; line-height:1.2;"></div>
                    </div>
                    <div class="form-meta">
                        <span class="form-created-by">Creato da</span>
                        <?php if (!empty($form['created_by_img'])): ?>
                            <img src="<?= htmlspecialchars($form['created_by_img']) ?>" alt="Creatore" class="profile-icon">
                        <?php else: ?>
                            <img src="assets/images/default_profile.png" alt="Creatore" class="profile-icon">
                        <?php endif; ?>
                        <span
                            class="form-date"><?= isset($form['created_at']) ? date('d/m/Y', strtotime($form['created_at'])) : '—' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>Nessuna segnalazione disponibile al momento.</p>
    <?php endif; ?>
</div>

<!-- Script per il grafico -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const chartSegnalazioniEl = document.getElementById('chartSegnalazioni');
        const chartStatiEl = document.getElementById('chartStatiSegnalazioni');
        if (!chartSegnalazioniEl || !chartStatiEl) {
            console.error("Canvas non trovati! chartSegnalazioniEl:", chartSegnalazioniEl, "chartStatiEl:", chartStatiEl);
            return;
        }
        const ctx = chartSegnalazioniEl.getContext('2d');
        const ctxPie = chartStatiEl.getContext('2d');

        // Colori di stato (personalizzali come vuoi)
        const statusLabels = ['Aperta', 'In corso', 'Chiusa'];
        const statusKeys = ['Aperta', 'In corso', 'Chiusa'];
        const statusColors = [
            'rgba(0,153,255,0.7)',    // Aperta - azzurro
            'rgba(255,214,0,0.7)',    // In corso - giallo
            'rgba(76,175,80,0.7)'     // Chiusa - verde
        ];

        const visibleFormIds = Array.from(document.querySelectorAll('.form-preview[data-form-id]'))
            .map(el => parseInt(el.getAttribute('data-form-id'), 10))
            .filter(id => !isNaN(id) && id > 0);

        const visibleFormNames = Array.from(document.querySelectorAll('.form-preview[data-form-name]'))
            .map(el => (el.getAttribute('data-form-name') || '').trim())
            .filter(n => n !== '');

        // Usa i dati già caricati dal PHP invece di fare una nuova chiamata AJAX
        const phpStats = <?= json_encode($forms) ?>;

        Promise.resolve({ success: true, stats: phpStats })
            .then(data => {
                if (!data.success) {
                    console.error("Errore dalla fetch:", data.error || "Errore generico");
                    if (window.showToast) showToast("Errore nel caricamento del grafico", "error");
                    return;
                }

                if (!data.stats || !Array.isArray(data.stats) || !data.stats.length) {
                    console.warn("Nessuna statistica disponibile per costruire il grafico.");
                    return;
                }

                lastStats = data.stats;

                // --- BAR CHART ---
                const labels = data.stats.map(f => f.name);

                const getGradient = (ctx, color) => {
                    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
                    gradient.addColorStop(0, color.replace('0.7', '0.15'));
                    gradient.addColorStop(1, color.replace('0.7', '0.35'));
                    return gradient;
                };

                const datasets = statusKeys.map((status, idx) => ({
                    label: statusLabels[idx],
                    data: data.stats.map(f => f.status_counts?.[status] ?? 0),
                    backgroundColor: getGradient(ctx, statusColors[idx]),
                    borderColor: statusColors[idx],
                    borderWidth: 2,
                    borderRadius: 8,
                    barPercentage: 0.5,
                    categoryPercentage: 0.7,
                    stack: 'Stati'
                }));

                new Chart(ctx, {
                    type: 'bar',
                    data: { labels, datasets },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' }
                        },
                        scales: {
                            x: { stacked: true },
                            y: { stacked: true, beginAtZero: true }
                        }
                    }
                });

                // Aggiorna i contatori sulle card
                data.stats.forEach(stat => {
                    // Aggiorna il totale segnalazioni
                    const counter = document.getElementById(`form-count-${stat.id}`);
                    if (counter) {
                        counter.innerHTML = `<strong>Segnalazioni:</strong> ${stat.total_reports}`
                            + `<div class="stato-mini-counts" id="form-state-mini-${stat.id}" style="font-size:11px; color:#666; margin-top:4px; line-height:1.2;"></div>`;
                    }

                    // Mini conteggio per stato
                    const statoMini = document.getElementById(`form-state-mini-${stat.id}`);
                    if (statoMini) {
                        let htmlRiga = '';
                        for (let i = 0; i < statusKeys.length; i++) {
                            const n = stat.status_counts?.[statusKeys[i]] ?? 0;
                            htmlRiga += `<span style="margin-right:7px;white-space:nowrap;">${statusLabels[i]}: <b style="font-weight:600;color:${statusColors[i].replace('0.7', '1')};font-size:11px;">${n}</b></span>`;
                        }
                        statoMini.innerHTML = `<div>${htmlRiga}</div>`;
                    }
                });

                renderPieChart(data.stats);

                const select = document.getElementById('filterSegnalazione');
                if (select) {
                    select.addEventListener('change', function () {
                        renderPieChart(lastStats, this.value);
                    });
                }
            })
            .catch(err => {
                console.error("Errore nella fetch:", err);
                if (window.showToast) showToast("Errore nel caricamento del grafico", "error");
            });

        let pieChartInstance = null;
        let lastStats = null;

        function renderPieChart(stats, filterId = "") {
            // Calcola i dati da mostrare: totale o solo per un form
            let totaliStato;
            let title = "Tutte le segnalazioni";
            if (filterId && filterId !== "") {
                const formStat = stats.find(f => f.id == filterId);
                if (formStat) {
                    totaliStato = statusKeys.map((status, idx) => formStat.status_counts?.[status] ?? 0);
                    title = formStat.name || "Segnalazione selezionata";
                } else {
                    totaliStato = statusKeys.map(() => 0);
                }
            } else {
                totaliStato = statusKeys.map((status, idx) =>
                    stats.reduce((sum, f) => sum + (f.status_counts?.[status] ?? 0), 0)
                );
            }

            // Aggiorna solo il chart, non creare canvas ogni volta!
            if (pieChartInstance) {
                pieChartInstance.data.datasets[0].data = totaliStato;
                pieChartInstance.options.plugins.title.text = title;
                pieChartInstance.update();
                return;
            }

            pieChartInstance = new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: totaliStato,
                        backgroundColor: statusColors.map(c => c.replace('0.7', '0.23')),
                        borderColor: statusColors.map(c => c.replace('0.7', '0.39')),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'right' },
                        title: {
                            display: true,
                            text: title,
                            font: { size: 15 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percent}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

    });

</script>

<script>
    // Click normale per aprire modulo
    document.querySelectorAll(".form-preview").forEach(card => {
        card.addEventListener("click", (e) => {
            const formName = card.dataset.formName;
            if (formName) {
                window.location.href = `index.php?section=collaborazione&page=gestione_segnalazioni&form_name=${encodeURIComponent(formName)}`;
            }
        });
    });
</script>
<!--
<script src="assets/js/formGenerator.js?v=<?php echo time(); ?>" defer></script>
-->