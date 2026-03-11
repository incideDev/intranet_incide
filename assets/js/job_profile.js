    function loadJobProfiles() {
        fetch('/api/hr/job_profile/get_all.php')
            .then(response => response.json())
            .then(data => {
                const tableBody = document.querySelector('#jobProfileTable tbody');
                if (!tableBody) return;

                tableBody.innerHTML = ''; // Pulisce la tabella

                if (data.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="10">Nessun profilo di lavoro trovato.</td></tr>';
                } else {
                    data.forEach(profile => {
                        const row = `
                            <tr>
                                <td>${profile.title}</td>
                                <td>${profile.position_level}</td>
                                <td>${profile.description}</td>
                                <td>${profile.department}</td>
                                <td>${profile.technical_skills}</td>
                                <td>${profile.soft_skills}</td>
                                <td>${profile.work_location}</td>
                                <td>${profile.job_grade}</td>
                                <td>${profile.created_at}</td>
                                <td>
                                    <button class="btn-action btn-edit" onclick="editJobProfile(${profile.id})">Modifica</button>
                                    <button class="btn-action btn-archive" onclick="archiveJobProfile(${profile.id})">Archivia</button>
                                </td>
                            </tr>`;
                        tableBody.innerHTML += row;
                    });
                }
            })
            .catch(err => console.error('Errore nel caricamento dei profili di lavoro:', err));
    }

    // Inizializza il caricamento
    document.addEventListener('DOMContentLoaded', loadJobProfiles);

    function openAddJobProfileModal() {
        document.getElementById('addJobProfileModal').style.display = 'block';
    }

    function closeAddJobProfileModal() {
        document.getElementById('addJobProfileModal').style.display = 'none';
    }

    // Chiusura del modale cliccando fuori dal contenuto
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('addJobProfileModal');
        if (event.target === modal) {
            closeAddJobProfileModal();
        }
    });
