document.addEventListener('DOMContentLoaded', function () {
    const newsContainer = document.querySelector('#news-container');
    const thumbnailsContainer = document.querySelector('#thumbnails-container');
    const loadingMessage = document.querySelector('#loading-message');
    let currentIndex = 0;
    let posts = [];

    if (!newsContainer || !thumbnailsContainer) {
        console.error('Elementi richiesti non trovati nel DOM.');
        return;
    }

    // Caricamento degli articoli da WordPress
    fetch('https://www.incide.it/wp-json/wp/v2/posts?per_page=5&_embed')
        .then(response => response.json())
        .then(data => {
            posts = data;
            if (posts.length > 0) {
                showPost(currentIndex); // Mostra il primo articolo
                createThumbnails(); // Crea miniature degli articoli
            } else {
                newsContainer.innerHTML = '<p>Nessun articolo disponibile.</p>';
            }
            if (loadingMessage) loadingMessage.style.display = 'none';
        })
        .catch(error => {
            console.error('Errore nel caricamento degli articoli:', error);
            if (loadingMessage) loadingMessage.textContent = 'Errore nel caricamento degli articoli.';
        });

    // Funzione per mostrare un articolo grande
    function showPost(index) {
        const post = posts[index];
        if (!post) {
            console.error('Post non trovato per l\'indice:', index); // Debug
            return;
        }

        const imageUrl = post._embedded?.['wp:featuredmedia']?.[0]?.source_url || 'assets/images/default-thumbnail.jpg';
        const excerpt = post.excerpt.rendered.replace(/<[^>]+>/g, ''); // Rimuove HTML

        newsContainer.innerHTML = `
            <div class="image-container">
                <img src="${imageUrl}" alt="${post.title.rendered}" class="post-image">
            </div>
            <div class="post-content">
                <h3>${post.title.rendered}</h3>
                <p>${excerpt}</p>
            </div>
        `;
    }

    // Funzione per creare miniature
    function createThumbnails() {
        thumbnailsContainer.innerHTML = ''; // Svuota il contenitore

        posts.forEach((post, index) => {
            const imageUrl = post._embedded?.['wp:featuredmedia']?.[0]?.source_url || 'assets/images/default-thumbnail.jpg';

            // Contenitore di ogni miniatura
            const thumbnailItem = document.createElement('div');
            thumbnailItem.className = 'thumbnail-item';

            // Immagine della miniatura
            const thumbnail = document.createElement('img');
            thumbnail.className = 'thumbnail';
            thumbnail.src = imageUrl;
            thumbnail.alt = post.title.rendered;

            // Titolo della miniatura
            const title = document.createElement('p');
            title.className = 'thumbnail-title';
            title.textContent = post.title.rendered;

            thumbnailItem.appendChild(thumbnail);
            thumbnailItem.appendChild(title);

            // Aggiungi evento click per aggiornare l'articolo grande
            thumbnailItem.addEventListener('click', () => {
                currentIndex = index;
                showPost(index);
                highlightThumbnail(index);
            });

            thumbnailsContainer.appendChild(thumbnailItem);
        });

        highlightThumbnail(currentIndex); // Evidenzia la prima miniatura
    }

    // Funzione per evidenziare la miniatura attiva
    function highlightThumbnail(index) {
        const thumbnails = document.querySelectorAll('.thumbnail-item');
        thumbnails.forEach((thumbnail, i) => {
            if (i === index) {
                thumbnail.classList.add('active-thumbnail');
            } else {
                thumbnail.classList.remove('active-thumbnail');
            }
        });
    }


    const calendarElement = document.querySelector('.calendar');
    const monthYearElement = document.getElementById('monthYear');
    const eventDetailsContainer = document.getElementById('eventDetailsContainer');
    const calendarContainer = document.getElementById('calendarContainer');
    const eventDetails = document.getElementById('eventDetails');
    const eventDateTitle = document.getElementById('eventDateTitle');
    const backToCalendar = document.getElementById('backToCalendar');
    const prevMonthButton = document.getElementById('prevMonth');
    const nextMonthButton = document.getElementById('nextMonth');
    const calendarWrapper = document.getElementById('calendarWrapper');
    const toggleButton = document.getElementById('toggleCalendarSize');

    let currentDate = new Date();

    // Funzione per renderizzare il calendario
    function renderCalendar(date, isExpanded = false) {
        const year = date.getFullYear();
        const month = date.getMonth();

        const monthNames = [
            'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
            'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'
        ];

        // Aggiorna il titolo del mese
        monthYearElement.textContent = `${monthNames[month]} ${year}`;
        calendarElement.innerHTML = '';

        // Giorni della settimana
        const dayNames = isExpanded
            ? ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica']
            : ['L', 'Ma', 'Me', 'G', 'V', 'S', 'D'];

        dayNames.forEach(day => {
            const dayNameElement = document.createElement('div');
            dayNameElement.className = `calendar-day-name ${isExpanded ? 'expanded-day-name' : ''}`;
            dayNameElement.textContent = day;
            calendarElement.appendChild(dayNameElement);
        });

        const firstDay = (new Date(year, month, 1).getDay() || 7) - 1;
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        // Celle vuote prima del primo giorno
        for (let i = 0; i < firstDay; i++) {
            const emptyCell = document.createElement('div');
            emptyCell.className = `calendar-day empty ${isExpanded ? 'expanded-day' : ''}`;
            calendarElement.appendChild(emptyCell);
        }

        // Giorni del mese
        for (let day = 1; day <= daysInMonth; day++) {
            const dayElement = document.createElement('div');
            dayElement.className = `calendar-day ${isExpanded ? 'expanded-day' : ''}`;
            dayElement.textContent = day;

            const dateStr = `${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

            // Compleanni
            const birthday = allBirthdays.find(b =>
                b.Data_di_Nascita && b.Data_di_Nascita.slice(5, 10) === dateStr
            );

            if (birthday) {
                const profileImagePath = birthday.profile_picture ||
                    `assets/images/profile_pictures/${birthday.username.toLowerCase().replace(' ', '_')}.jpg`;

                const profileIcon = document.createElement('img');
                profileIcon.src = profileImagePath;
                profileIcon.alt = `${birthday.username} Profile`;
                profileIcon.classList.add('calendar-profile-icon');
                profileIcon.onerror = function () {
                    this.src = 'assets/images/default_profile.png'; // Immagine di fallback
                };

                dayElement.appendChild(profileIcon);
            }

            dayElement.addEventListener('click', function () {
                showEventDetails(dateStr);
            });

            calendarElement.appendChild(dayElement);
        }
    }

    // Funzione per mostrare i dettagli di un evento
    function showEventDetails(date) {
        const events = allBirthdays.filter(event =>
            event.Data_di_Nascita && event.Data_di_Nascita.slice(5, 10) === date
        );

        eventDateTitle.textContent = `Eventi per il giorno: ${date}`;
        eventDetails.innerHTML = '';

        if (events.length > 0) {
            events.forEach(event => {
                const birthdayCard = document.createElement('div');
                birthdayCard.classList.add('birthday-card');

                const profileImagePath = event.profile_picture ||
                    `assets/images/profile_pictures/${event.username.toLowerCase().replace(' ', '_')}.jpg`;

                const profileImg = document.createElement('img');
                profileImg.src = profileImagePath;
                profileImg.alt = `${event.username} Profile Picture`;
                profileImg.classList.add('birthday-image');
                profileImg.onerror = function () {
                    this.src = 'assets/images/default_profile.png';
                };

                const birthdayInfo = document.createElement('div');
                birthdayInfo.classList.add('birthday-info');

                const nameElement = document.createElement('h4');
                nameElement.textContent = event.username;

                const birthdayText = document.createElement('p');
                birthdayText.textContent = `Compleanno il ${new Date(event.Data_di_Nascita).toLocaleDateString('it-IT', { day: '2-digit', month: 'long' })}`;

                birthdayInfo.appendChild(nameElement);
                birthdayInfo.appendChild(birthdayText);

                birthdayCard.appendChild(profileImg);
                birthdayCard.appendChild(birthdayInfo);

                eventDetails.appendChild(birthdayCard);
            });
        } else {
            eventDetails.innerHTML = '<p>Nessun evento per questa data.</p>';
        }

        eventDetailsContainer.style.display = 'block';
        calendarContainer.style.display = 'none';
    }

    // Evento per tornare alla visualizzazione del calendario
    backToCalendar.addEventListener('click', function () {
        eventDetailsContainer.style.display = 'none';
        calendarContainer.style.display = 'grid';
    });

    // Navigazione mese precedente
    prevMonthButton.addEventListener('click', function () {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar(currentDate, calendarWrapper.classList.contains('expanded'));
    });

    // Navigazione mese successivo
    nextMonthButton.addEventListener('click', function () {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar(currentDate, calendarWrapper.classList.contains('expanded'));
    });

    // Espansione/riduzione del calendario
    toggleButton.addEventListener('click', () => {
        const isExpanded = calendarWrapper.classList.toggle('expanded');
        document.body.classList.toggle('calendar-expanded', isExpanded);
        renderCalendar(currentDate, isExpanded);
    });

    // Chiudi il calendario espanso cliccando fuori
    document.addEventListener('click', (event) => {
        if (
            calendarWrapper.classList.contains('expanded') &&
            !calendarWrapper.contains(event.target) &&
            !toggleButton.contains(event.target)
        ) {
            calendarWrapper.classList.remove('expanded');
            document.body.classList.remove('calendar-expanded');
            renderCalendar(currentDate, false);
        }
    });

    // Render iniziale del calendario
    renderCalendar(currentDate);
});
