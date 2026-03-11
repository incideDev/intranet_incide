document.addEventListener('DOMContentLoaded', () => {
    loadOpenSearchData();
});

function loadOpenSearchData() {
    fetch('/api/hr/open_search/get_data.php')
        .then(response => response.json())
        .then(data => {
            console.log("Dati ricevuti per Open Search:", data);

            const tableBody = document.querySelector('#openSearchTable tbody');
            if (!data.openSearches || data.openSearches.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5">Nessuna campagna trovata.</td></tr>';
                return;
            }

            tableBody.innerHTML = ''; // Svuota la tabella prima di popolarla

            data.openSearches.forEach(campaign => {
                const row = `
                    <tr>
                        <td>${campaign.profile_title || 'Non specificato'}</td>
                        <td>${campaign.publication_date || 'Non disponibile'}</td>
                        <td>${campaign.start_date || 'Inizio non definito'} - ${campaign.end_date || 'Fine non definita'}</td>
                        <td>${campaign.status || 'Stato non disponibile'}</td>
                        <td>
                            <button class="btn-action btn-edit" onclick="editOpenSearch(${campaign.id})">Modifica</button>
                            <button class="btn-action btn-archive" onclick="closeOpenSearch(${campaign.id})">Chiudi</button>
                        </td>
                    </tr>
                `;
                tableBody.innerHTML += row;
            });
        })
        .catch(error => {
            console.error("Errore durante il caricamento dei dati Open Search:", error);
        });
}

function openAddSearchModal() {
    document.getElementById('addOpenSearchModal').style.display = 'block';
}

function closeAddSearchModal() {
    document.getElementById('addOpenSearchModal').style.display = 'none';
}

// Chiudi il modale quando si clicca fuori dal contenuto
window.addEventListener('click', function(event) {
    const modal = document.getElementById('addOpenSearchModal');
    if (event.target === modal) {
        closeAddSearchModal();
    }
});

// Aggiorna il display del budget dinamicamente
document.getElementById('budget').addEventListener('input', function() {
    document.getElementById('budgetDisplay').textContent = this.value;
});

