function initializeCalendar() {
    console.log('Inizializzazione del calendario...');

    // Recupera tutti gli elementi necessari
    const calendarContainer = document.getElementById('kanban-calendar-container');
    const calendarBody = document.getElementById('kanban-calendar-body');
    const currentMonthSpan = document.getElementById('kanban-current-month');
    const prevMonthBtn = document.getElementById('kanban-prev-month');
    const nextMonthBtn = document.getElementById('kanban-next-month');

    // Controlla se tutti gli elementi esistono
    if (!calendarContainer || !calendarBody || !currentMonthSpan || !prevMonthBtn || !nextMonthBtn) {
        console.error('Elementi del calendario mancanti. Assicurati che la struttura HTML sia corretta.');
        return;
    }

    let currentDate = new Date();

    // Funzione per recuperare i task
    async function fetchTasks() {
        const bachecaId = new URLSearchParams(window.location.search).get('board');
        try {
            const response = await fetch(`index.php?page=get_tasks_calendar&board=${bachecaId}`);
            return response.ok ? await response.json() : [];
        } catch (error) {
            console.error('Errore durante il recupero delle task:', error);
            return [];
        }
    }

    // Funzione per renderizzare il calendario
    async function renderCalendar(date) {
        console.log('Rendering calendario per:', date);
        const tasks = await fetchTasks();
        const year = date.getFullYear();
        const month = date.getMonth();
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        const monthNames = [
            'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
            'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'
        ];
        currentMonthSpan.textContent = `${monthNames[month]} ${year}`;
        calendarBody.innerHTML = '';

        // Giorni vuoti per allineare al primo giorno
        for (let i = 0; i < (firstDay === 0 ? 6 : firstDay - 1); i++) {
            const emptyCell = document.createElement('div');
            emptyCell.classList.add('kanban-calendar-day', 'disabled');
            calendarBody.appendChild(emptyCell);
        }

        // Popola i giorni del mese
        for (let day = 1; day <= daysInMonth; day++) {
            const cell = document.createElement('div');
            cell.classList.add('kanban-calendar-day');
            cell.textContent = day;

            const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const dayTasks = tasks.filter(task => task.data_scadenza === dateString);

            dayTasks.forEach(task => {
                const taskElement = document.createElement('div');
                taskElement.classList.add('kanban-task');
                taskElement.textContent = task.titolo;
                taskElement.title = task.descrizione;
                taskElement.onclick = () => openTaskDetailModal(task.id);
                cell.appendChild(taskElement);
            });

            calendarBody.appendChild(cell);
        }
    }

    prevMonthBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar(currentDate);
    });

    nextMonthBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar(currentDate);
    });

    renderCalendar(currentDate);
}
