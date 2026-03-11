let pieChartInstance; // Variabile globale per gestire il grafico a torta

document.addEventListener('DOMContentLoaded', () => {
    // Carica i dati iniziali per i grafici
    fetch('index.php?page=task_management&dashboard=true&action=get_data_for_charts')
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Errore nei dati:', data.error);
                return;
            }

            // Popola il menu a tendina e inizializza il grafico
            if (data.completedTasks && data.completedTasks.length > 0) {
                populateStatusFilter(data.completedTasks); // Popola il menu a tendina
                renderPieChart(data.completedTasks); // Mostra il grafico con i dati iniziali
            } else {
                console.warn('Nessun dato per il grafico a torta.');
            }

            if (data.taskCounts && data.taskCounts.length > 0) {
                renderBarChart(data.taskCounts); // Mostra il grafico a barre
            } else {
                console.warn('Nessun dato per il grafico a barre.');
            }
        })
        .catch(error => console.error('Errore nel caricamento dei dati:', error));

    // Aggiungi un listener per il cambio di stato
    const statusFilter = document.getElementById('statusFilter');
    statusFilter.addEventListener('change', () => updatePieChart());
});

function populateStatusFilter(completedTasks) {
    const statusFilter = document.getElementById('statusFilter');
    statusFilter.innerHTML = ''; // Resetta il contenuto del menu a tendina

    const defaultOption = document.createElement('option');
    defaultOption.value = 'all';
    defaultOption.textContent = 'Tutti';
    statusFilter.appendChild(defaultOption);

    completedTasks.forEach(task => {
        const option = document.createElement('option');
        option.value = task.board;
        option.textContent = task.board;
        statusFilter.appendChild(option);
    });
}

function updatePieChart() {
    const selectedBoard = document.getElementById('statusFilter').value;

    // Carica i dati filtrati dal backend
    fetch('index.php?page=task_management&dashboard=true&action=get_data_for_charts')
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Errore nei dati:', data.error);
                return;
            }

            // Filtra i dati per la bacheca selezionata
            const filteredTasks = selectedBoard === 'all'
                ? data.completedTasks
                : data.completedTasks.filter(task => task.board === selectedBoard);

            // Ricrea il grafico
            renderPieChart(filteredTasks);
        })
        .catch(error => console.error('Errore nel caricamento dei dati filtrati:', error));
}

function renderPieChart(completedTasks) {
    const ctx = document.getElementById('pieChart').getContext('2d');

    // Distruggi il grafico esistente, se presente
    if (pieChartInstance) {
        pieChartInstance.destroy();
    }

    // Controlla se ci sono dati per il grafico
    if (!completedTasks || completedTasks.length === 0) {
        console.warn('Nessun dato disponibile per il grafico a torta.');
        return;
    }

    // Crea il nuovo grafico
    pieChartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: completedTasks.map(item => item.board),
            datasets: [{
                data: completedTasks.map(item => item.count),
                backgroundColor: generateDynamicColors(completedTasks.length),
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: true,
                    labels: {
                        color: '#000',
                    }
                }
            }
        }
    });
}

function renderBarChart(taskCounts) {
    const ctx = document.getElementById('barChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: taskCounts.map(item => item.status),
            datasets: [{
                label: 'Task per Stato',
                data: taskCounts.map(item => item.count),
                backgroundColor: '#cd211d',
                borderColor: '#333',
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: true,
                    labels: {
                        color: '#000',
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#666' }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#666' }
                }
            }
        }
    });
}

// Funzione per generare colori dinamici
function generateDynamicColors(num) {
    const colors = ['#ff6384', '#36a2eb', '#cc65fe', '#ffce56', '#cd211d', '#666666'];
    return Array.from({ length: num }, (_, i) => colors[i % colors.length]);
}
