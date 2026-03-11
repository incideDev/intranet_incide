document.addEventListener('DOMContentLoaded', () => {
    checkAndAdvanceCandidates();
    loadView('kanban');
});

function openCreateCandidateModal() {
    fetch('/api/selection/get_create_form.php')
        .then(response => response.json())
        .then(data => {
            const modalContent = document.querySelector('#addCandidateModal .modal-content');
            if (modalContent) {
                modalContent.innerHTML = data.content;
                populateCampaignDropdown(data.campaigns);
                document.getElementById('addCandidateModal').style.display = 'block';
            } else {
                console.error("Errore: contenuto del modale non trovato");
            }
        })
        .catch(error => console.error("Errore nel caricamento del modale:", error));
}

function closeCreateCandidateModal() {
    document.getElementById('addCandidateModal').style.display = 'none';
}

// Chiudi il modale cliccando fuori
window.addEventListener('click', function(event) {
    const modal = document.getElementById('addCandidateModal');
    if (event.target === modal) {
        closeCreateCandidateModal();
    }
});

function populateCampaignDropdown(campaigns) {
    const campaignDropdown = document.getElementById('campaign');
    if (!campaignDropdown) {
        console.error("Dropdown delle campagne non trovato");
        return;
    }

    campaignDropdown.innerHTML = '<option value="">Seleziona una campagna</option>';
    campaigns.forEach(campaign => {
        const option = document.createElement('option');
        option.value = campaign.id;
        option.textContent = `${campaign.profile_title} (${campaign.publication_date})`;
        campaignDropdown.appendChild(option);
    });
}

function submitAddCandidateForm() {
    const formData = new FormData(document.getElementById('addCandidateForm'));

    fetch('/api/selection/add_candidate.php', {
        method: 'POST',
        body: formData,
    })
    .then(response => {
        if (!response.ok) throw new Error('Errore nella risposta del server');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            closeCreateCandidateModal();
            refreshKanbanView();
        } else {
            console.error("Errore nell'aggiunta del candidato:", data.error);
        }
    })
    .catch(error => console.error("Errore nella richiesta di salvataggio:", error));
}

function openCandidatePhaseModal(candidateId, phase) {
    let modal = document.getElementById('candidatePhaseModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'candidatePhaseModal';
        modal.classList.add('modal');
        modal.innerHTML = `
            <div class="modal-content">
                <span class="close" onclick="closeCandidatePhaseModal()">&times;</span>
                <div id="candidateModalContent">Caricamento dati...</div>
            </div>`;
        document.body.appendChild(modal);
    }
    modal.style.display = 'block';

    const phaseParam = phase === 'discarded' ? 7 : phase; // Associa "discarded" all'ID della fase scartati

    fetch(`/api/selection/get_phase_modal.php?candidate_id=${candidateId}&phase=${phaseParam}`)
        .then(response => response.json())
        .then(data => {
            const modalContent = document.getElementById('candidateModalContent');
            if (data.error) {
                console.error(data.error);
                modalContent.innerHTML = `<p>${data.error}</p>`;
            } else {
                modalContent.innerHTML = data.content;
            }
        })
        .catch(error => {
            console.error('Errore nel caricamento dei dati del candidato:', error);
            document.getElementById('candidateModalContent').innerHTML = `<p>Errore nel caricamento dei dati.</p>`;
        });
}

function closeCandidatePhaseModal() {
    const modal = document.getElementById('candidatePhaseModal');
    if (modal) modal.style.display = 'none';
}

function saveCandidateFeedback(candidateId, stageId) {
    const feedback = document.getElementById('feedbackInput').value;
    const score = document.getElementById('scoreInput').value;

    fetch('/api/selection/save_feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ candidateId, feedback, score })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("Feedback salvato con successo");
            closeCandidatePhaseModal();
            checkAndAdvanceCandidates();
        } else {
            console.error("Errore nel salvataggio del feedback:", data.error);
        }
    })
    .catch(error => console.error("Errore nella richiesta di salvataggio del feedback:", error));
}

function setupDragAndDrop() {
    const candidates = document.querySelectorAll('.candidate');
    const columns = document.querySelectorAll('.kanban-column');

    candidates.forEach(candidate => {
        candidate.addEventListener('dragstart', handleDragStart);
    });

    columns.forEach(column => {
        column.addEventListener('dragover', handleDragOver);
        column.addEventListener('drop', handleDrop);
    });
}

function handleDragStart(event) {
    event.dataTransfer.setData('text/plain', event.target.id);
    event.dropEffect = 'move';
}

function handleDragOver(event) {
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
}

function handleDrop(event) {
    event.preventDefault();
    const candidateId = event.dataTransfer.getData('text/plain');
    const candidateElement = document.getElementById(candidateId);
    const newStageId = event.currentTarget.getAttribute('data-stage-id');

    event.currentTarget.querySelector('.candidate-container').appendChild(candidateElement);
    updateCandidateStageInDatabase(candidateId, newStageId);
}

function updateCandidateStageInDatabase(candidateId, newStageId) {
    const formData = new FormData();
    formData.append('candidate_id', candidateId);
    formData.append('new_stage_id', newStageId);

    fetch('/api/selection/update_stage.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Stage aggiornato con successo.');
            } else {
                console.error('Errore nell\'aggiornamento dello stage:', data.message);
            }
        })
        .catch(error => console.error('Errore nella richiesta di aggiornamento dello stage:', error));
}

function checkAndAdvanceCandidates() {
    fetch('/api/hr/selection/check_and_advance.php', {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("Avanzamento dei candidati completato con successo.");
            refreshKanbanView();
        } else {
            console.error("Errore durante l'avanzamento dei candidati:", data);
        }
    })
    .catch(error => console.error("Errore durante la chiamata avanzamento candidati:", error));
}

function loadView(view) {
    if (view !== 'kanban') {
        console.error(`Vista non supportata: ${view}`);
        return;
    }

    console.log("Caricamento della vista Kanban...");
    fetch('/api/hr/selection/get_view_data.php')
        .then(response => response.json())
        .then(data => {
            console.log("Dati ricevuti dall'API:", data);

            const viewContainer = document.querySelector('.main-container');
            if (!data.selectionStages || !data.candidates) {
                viewContainer.innerHTML = '<p>Errore: Dati mancanti o non validi.</p>';
                return;
            }

            let html = '<div class="kanban-container">';
            data.selectionStages.forEach(stage => {
                html += `
                    <div class="kanban-column" data-stage-id="${stage.id}">
                        <div class="kanban-header" style="border-bottom: 4px solid ${stage.color};">
                            <h2>${stage.name}</h2>
                            ${stage.id === 1 ? `
                                <button class="add-candidate-btn" onclick="openCreateCandidateModal()">
                                    <img src="assets/icons/plus.png" alt="Aggiungi Candidato" class="candidate-icon">
                                </button>
                            ` : ''}
                        </div>
                        <div class="candidate-container">
                            ${
                                data.candidates
                                    .filter(candidate => candidate.stage_id === stage.id)
                                    .map(candidate => `
                                        <div class="candidate" id="candidate-${candidate.id}" draggable="true" onclick="openCandidatePhaseModal(${candidate.id}, ${stage.id})">
                                            <div class="candidate-card">
                                                <h3>${candidate.name}</h3>
                                                <p>${candidate.position_applied}</p>
                                            </div>
                                        </div>
                                    `).join('') || '<p class="no-candidates-message">Nessun candidato in questa fase.</p>'
                            }
                        </div>
                    </div>`;
            });
            html += '</div>';
            viewContainer.innerHTML = html;

            setupDragAndDrop();
        })
        .catch(error => {
            console.error("Errore nel caricamento della vista Kanban:", error);
        });
}

function refreshKanbanView() {
    loadView('kanban');
}

function updateActiveTab(view) {
    document.querySelectorAll('.view-tab').forEach(tab => tab.classList.remove('active'));
    const activeTab = document.querySelector(`.view-tab[data-view="${view}"]`);
    if (activeTab) activeTab.classList.add('active');
}

function generateKanbanHTML(selectionStages, candidates) {
    let html = '<div class="kanban-container">';
    selectionStages.forEach(stage => {
        html += `<div class="kanban-column" data-stage-id="${stage.id}">
                    <div class="kanban-header" style="border-bottom: 4px solid ${stage.color};">
                        <h2>${stage.name}</h2>`;
        if (stage.id === 1) { // Solo nella fase "Nuove Candidature"
            html += `<button class="add-candidate-btn" onclick="openCreateCandidateModal()">
                        <img src="assets/icons/plus.png" alt="Aggiungi Candidato" class="candidate-icon">
                    </button>`;
        }
        html += `</div>
                    <div class="candidate-container">`;
        const candidatesForStage = candidates.filter(candidate => candidate.stage_id === stage.id);
        candidatesForStage.forEach(candidate => {
            html += `<div class="candidate" id="candidate-${candidate.id}" draggable="true" onclick="openCandidatePhaseModal(${candidate.id}, ${stage.id})" style="cursor: pointer;">
                        <div class="candidate-card">
                            <h3>${candidate.name}</h3>
                            <p>${candidate.position_applied}</p>
                        </div>
                    </div>`;
        });
        if (candidatesForStage.length === 0) {
            html += `<p class="no-candidates-message">Nessun candidato in questa fase.</p>`;
        }
        html += `</div></div>`;
    });
    html += '</div>';
    return html;
}

window.addEventListener('click', function(event) {
    const modal = document.getElementById('candidatePhaseModal');
    if (event.target === modal) {
        closeCandidatePhaseModal();
    }
});

function savePhaseData(event, candidateId, stageId) {
    event.preventDefault();

    // Costruzione dati da inviare
    const dataToSend = { candidateId, stageId };

    // Raccogli i campi specifici della fase
    switch (stageId) {
        case 1: // Fase 1: Raccolta delle Candidature
            dataToSend.score = document.getElementById('score')?.value || null;
            dataToSend.feedback = document.getElementById('feedback')?.value || null;

            // Scarta automaticamente se lo score è inferiore a 50
            dataToSend.stageAction = dataToSend.score < 50 ? 'discard' : 'advance';
            break;

        case 2: // Fase 2: In Valutazione
            dataToSend.evaluation = document.getElementById('evaluation')?.value || null;
            dataToSend.stageAction = document.querySelector('input[name="stage_action"]:checked')?.value || 'advance';
            break;

        case 3: // Fase 3: Primo Colloquio
            dataToSend.firstInterview = document.getElementById('firstInterview')?.value || null;
            dataToSend.stageAction = document.querySelector('input[name="stage_action"]:checked')?.value || 'advance';
            break;

        case 4: // Fase 4: Secondo Colloquio
            dataToSend.secondInterview = document.getElementById('secondInterview')?.value || null;
            dataToSend.stageAction = document.querySelector('input[name="stage_action"]:checked')?.value || 'advance';
            break;

        case 5: // Fase 5: Proposta
            dataToSend.finalDecision = document.getElementById('finalDecision')?.value || null;
            dataToSend.finalScore = document.getElementById('finalScore')?.value || null;
            dataToSend.notes = document.getElementById('notes')?.value || null;
            dataToSend.proposedSalaryNet = document.getElementById('proposedSalaryNet')?.value || null;
            dataToSend.proposedSalaryGross = document.getElementById('proposedSalaryGross')?.value || null;
            dataToSend.startDate = document.getElementById('startDate')?.value || null;
            dataToSend.stageAction = document.querySelector('input[name="stage_action"]:checked')?.value || 'advance';
            break;

        default:
            console.error("Errore: fase non riconosciuta.");
            return;
    }

    // Invio dati al server
    fetch('/api/selection/save_phase_data.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dataToSend)
    })
        .then(response => response.json())
        .then(data => {
            console.log("Risposta del server:", data);
            if (data.success) {
                console.log("Dati della fase salvati con successo");
                closeCandidatePhaseModal();
                refreshKanbanView();
            } else {
                console.error("Errore nel salvataggio dei dati:", data.error);
            }
        })
        .catch(error => {
            console.error("Errore nella richiesta di salvataggio:", error);
        });
}

function calculateGrossSalary() {
    const netSalary = parseFloat(document.getElementById('proposedSalaryNet').value);
    if (isNaN(netSalary)) {
        document.getElementById('proposedSalaryGross').value = '';
        return;
    }

    // Approssimazione dell'aliquota fiscale media
    const averageTaxRate = 0.30; // 30%

    // Calcolo approssimativo del lordo
    const grossSalary = netSalary / (1 - averageTaxRate);

    document.getElementById('proposedSalaryGross').value = grossSalary.toFixed(2);
}
