<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    exit;
}

if (!isset($Session) || !$Session->logged_in) {
    header("Location: /login.php");
    exit;
}
?>

<div id="modalCalendar" class="modal">
    <div class="modal-content calendar-modal-content">
        <span class="close" onclick="closeModalCalendar()">&times;</span>

        <div class="calendar-modal-layout">
            <!-- SINISTRA: calendario -->
            <div class="calendar-modal-left">
                <div class="calendar-expanded-controls">
                    <button id="prevMonthModal" class="month-nav">&laquo;</button>
                    <h3 id="modalMonthYear" class="month-year"></h3>
                    <button id="nextMonthModal" class="month-nav">&raquo;</button>
                </div>
                <div id="modalCalendarContainer" class="calendar-expanded-wrapper"></div>
            </div>
            <!-- DESTRA: sidebar -->
            <div class="calendar-modal-sidebar" id="calendarModalSidebar">
                <h3>Dettagli Giorno</h3>
                <div id="calendarModalEvents"></div>
                <div class="calendar-sidebar-tools">
                    <!-- Qui metteremo filtri/azioni in futuro -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    #modalCalendar {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        display: none;
    }

    #modalCalendar.show {
        display: flex;
        align-items: flex-start;
    }

    #modalCalendar .calendar-modal-content {
        width: 96vw;
        max-width: 1020px;
        min-width: 320px;
        margin: 0 2vw;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        padding: 20px;
        /* position: relative; */
        display: flex;
        flex-direction: column;
        transition: width 0.2s, max-width 0.2s;
    }

    #modalCalendar .calendar-modal-layout {
        display: flex;
        flex-direction: row;
        gap: 32px;
        width: 100%;
        min-height: 480px;
    }

    #modalCalendar .calendar-modal-left {
        flex: 3;
        max-width: 80%;
        display: flex;
        flex-direction: column;
    }

    #modalCalendar .calendar-modal-sidebar {
        flex: 1;
        max-width: 25%;
        background: #f8f8fa;
        border-radius: 10px;
        box-shadow: 0 1px 8px #eee;
        padding: 18px 12px 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow-y: auto;
        max-height: 55vh;
        min-height: 200px;
    }

    #modalCalendar .calendar-expanded-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-bottom: 15px;
        gap: 12px;
        flex-shrink: 0;
    }

    #modalCalendar .calendar-expanded-controls .month-year {
        font-size: 21px;
        font-weight: bold;
        margin: 0;
    }

    #modalCalendar .month-nav {
        background: linear-gradient(to bottom, rgb(255, 0, 0), rgb(179, 15, 0));
        color: #fff;
        font-weight: bold;
        border: 1px solid rgb(128, 38, 0);
        padding: 8px 15px;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.3s;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.07);
        font-size: 16px;
    }

    #modalCalendar .month-nav:hover {
        background: linear-gradient(to bottom, rgb(255, 0, 0), rgb(204, 0, 0));
    }

    #modalCalendar .expanded-day-row {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        margin-bottom: 6px;
        gap: 6px;
    }

    #modalCalendar .expanded-day-name {
        font-weight: bold;
        text-align: center;
        padding: 7px 0;
        background: #f0f0f0;
        border-radius: 4px;
        font-size: 15px;
    }

    #modalCalendar .expanded-day-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 7px;
    }

    #modalCalendar .expanded-day {
        background: #fafafa;
        border: 1px solid #e0e0e0;
        min-height: 85px;
        border-radius: 6px;
        text-align: center;
        padding: 6px 2px 4px 2px;
        font-size: 14px;
        position: relative;
        cursor: pointer;
        transition: background 0.12s;
    }

    #modalCalendar .expanded-day:hover {
        background: #fff4f2;
    }

    #modalCalendar .expanded-day.empty {
        background: transparent;
        border: none;
        cursor: default;
    }

    #modalCalendar .expanded-day .day-number {
        font-weight: bold;
        font-size: 17px;
        margin-bottom: 5px;
        color: #222;
    }

    #modalCalendar .calendar-profile-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        display: block;
        margin: 4px auto;
        border: 2px solid #fff;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.13);
    }

    #modalCalendar .close {
        position: absolute;
        top: 8px;
        right: 12px;
        font-size: 23px;
        cursor: pointer;
        color: #900;
    }

    #modalCalendar #calendarModalEvents {
        margin-bottom: 14px;
    }

    #modalCalendar .calendar-sidebar-tools {
        margin-top: auto;
        padding-top: 8px;
        border-top: 1px solid #eee;
    }

    /* Responsive rules */
    @media (max-width: 900px) {
        #modalCalendar .calendar-modal-content {
            max-width: 98vw;
            padding: 10px 2vw;
        }

        #modalCalendar .calendar-modal-layout {
            flex-direction: column;
            gap: 16px;
            min-height: 0;
        }

        #modalCalendar .calendar-modal-left,
        #modalCalendar .calendar-modal-sidebar {
            max-width: 100%;
            min-width: 0;
        }
    }

    @media (max-width: 620px) {
        #modalCalendar .calendar-modal-content {
            padding: 2vw 2vw;
            border-radius: 0;
            min-width: 0;
        }

        #modalCalendar .calendar-modal-layout {
            gap: 8px;
        }

        #modalCalendar .calendar-modal-sidebar {
            padding: 10px 4px 8px 8px;
            border-radius: 6px;
        }
    }

    #modalCalendar .scadenza-indicator {
        position: absolute;
        top: 3px;
        right: 6px;
        width: 18px;
        height: 18px;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #modalCalendar .scadenza-indicator img {
        width: 15px;
        height: 15px;
        display: block;
    }

    #modalCalendar .scadenza-card {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        background: #fff;
        border: 1.5px solid #ffe4a6;
        border-radius: 8px;
        padding: 6px 8px;
        margin-bottom: 10px;
        box-shadow: 0 1px 5px rgba(255, 193, 90, 0.06);
        position: relative;
        min-height: 52px;
    }

    #modalCalendar .scadenza-card .scadenza-icon {
        width: 26px;
        height: 26px;
        margin-right: 2px;
        margin-top: 2px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #modalCalendar .scadenza-card .scadenza-icon img {
        width: 20px;
        height: 20px;
        display: block;
    }

    #modalCalendar .scadenza-card .scadenza-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
        /* ← QUESTA RIGA È FONDAMENTALE! */
        max-width: 100%;
        /* ← ANCHE QUESTA! */
        /* così i figli non potranno mai uscire a destra */
    }

    #modalCalendar .scadenza-card .scadenza-title {
        font-size: 12px;
        font-weight: 500;
        color: rgb(184, 0, 0);
        margin-bottom: 1px;
        line-height: 1.1;
        text-transform: uppercase;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
    }

    #modalCalendar .scadenza-card .scadenza-desc {
        font-size: 13px;
        color: #666;
        margin-bottom: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
    }

    #modalCalendar .scadenza-card .scadenza-meta {
        font-size: 12px;
        color: #a6811a;
        margin-top: 3px;
        font-weight: 500;
        opacity: .88;
    }

    /* Pallino priorità */
    #modalCalendar .scadenza-card .scadenza-priority {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 13px;
        height: 13px;
        border-radius: 50%;
        box-shadow: 0 0 2px #aaa;
    }

    #modalCalendar .scadenza-card.prio-alta .scadenza-priority {
        background: #e44c4c;
    }

    #modalCalendar .scadenza-card.prio-media .scadenza-priority {
        background: #ffc940;
    }

    #modalCalendar .scadenza-card.prio-bassa .scadenza-priority {
        background: #87cc5b;
    }

    #modalCalendar .scadenza-card.scadenza-commessa {
        border-color: #2980b9;
    }

    #modalCalendar .scadenza-card.scadenza-segnalazione {
        border-color: #ffb347;
    }

    #modalCalendar .scadenza-card.scadenza-compleanno {
        border-color: #f9e90a;
    }

    #modalCalendar .scadenza-card.scadenza-altro {
        border-color: #aaa;
    }
</style>

<script>
    (() => {
        const modal = document.getElementById("modalCalendar");
        const modalContainer = document.getElementById("modalCalendarContainer");
        const modalMonthYear = document.getElementById("modalMonthYear");
        const prevMonthBtn = document.getElementById("prevMonthModal");
        const nextMonthBtn = document.getElementById("nextMonthModal");

        let modalDate = new Date();
        let modalBirthdays = [];
        let modalScadenze = [];
        let selectedDayInModal = new Date();

        async function fetchModalData(date = new Date()) {
            // Compleanni
            try {
                const response = await customFetch('home', 'getAllBirthdays');
                modalBirthdays = response.success ? response.birthdays : [];
            } catch (e) {
                modalBirthdays = [];
                console.warn(" Errore caricamento compleanni:", e);
            }
            // Scadenze
            try {
                const response = await customFetch('segnalazioni', 'getScadenze');
                modalScadenze = response.success ? response.scadenze : [];
            } catch (e) {
                modalScadenze = [];
                console.warn(" Errore caricamento scadenze:", e);
            }
        }

        function renderModalCalendar(date = new Date()) {
            modalContainer.innerHTML = '';
            const year = date.getFullYear();
            const month = date.getMonth();
            const dayNames = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];

            modalMonthYear.textContent = `${['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'][month]} ${year}`;

            const daysRow = document.createElement('div');
            daysRow.className = 'expanded-day-row';
            dayNames.forEach(day => {
                const el = document.createElement('div');
                el.className = 'expanded-day-name';
                el.textContent = day;
                daysRow.appendChild(el);
            });
            modalContainer.appendChild(daysRow);

            const firstDay = (new Date(year, month, 1).getDay() || 7) - 1;
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const grid = document.createElement('div');
            grid.className = 'expanded-day-grid';

            for (let i = 0; i < firstDay; i++) {
                const empty = document.createElement('div');
                empty.className = 'expanded-day empty';
                grid.appendChild(empty);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const el = document.createElement('div');
                el.className = 'expanded-day';
                el.innerHTML = `<div class="day-number">${day}</div>`;

                // Evidenzia oggi
                if (
                    day === selectedDayInModal.getDate() &&
                    month === selectedDayInModal.getMonth() &&
                    year === selectedDayInModal.getFullYear()
                ) {
                    el.style.background = '#fdfdfd';
                    el.style.border = '2px solid #c71f00';
                }

                const dateStr = `${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const matches = modalBirthdays.filter(b => b.Data_di_Nascita?.slice(5, 10) === dateStr);
                const scadenzeGiorno = modalScadenze.filter(s => s.deadline && s.deadline.slice(5, 10) === dateStr);

                if (scadenzeGiorno.length > 0) {
                    const indicator = document.createElement('div');
                    indicator.className = 'scadenza-indicator';
                    indicator.title = `Scadenze: ${scadenzeGiorno.length}`;
                    // Usa l'icona custom!
                    const img = document.createElement('img');
                    img.src = '/assets/icons/report.png'; // Percorso relativo alla tua icona
                    img.alt = 'Scadenza segnalazione';
                    indicator.appendChild(img);
                    el.appendChild(indicator);
                }

                matches.forEach(b => {
                    const pic = document.createElement('img');
                    pic.className = 'calendar-profile-icon';
                    pic.src = b.profile_picture.startsWith('data:') || b.profile_picture.startsWith('/') ? b.profile_picture : '/' + b.profile_picture;
                    pic.alt = b.username;
                    pic.onerror = () => {
                        if (!pic.classList.contains('error-applied')) {
                            pic.src = '/assets/images/default_profile.png';
                            pic.classList.add('error-applied');
                        }
                    };
                    el.appendChild(pic);
                });

                // CLICK EVENT: mostra eventi nella sidebar
                el.addEventListener('click', function () {
                    renderSidebarEvents(day, month, year, matches);
                });

                grid.appendChild(el);
            }

            modalContainer.appendChild(grid);
        }

        function renderSidebarEvents(day, month, year, events) {
            const sidebar = document.getElementById('calendarModalSidebar');
            const sidebarEvents = document.getElementById('calendarModalEvents');
            const selectedDate = new Date(year, month, day);
            const dateString = selectedDate.toLocaleDateString('it-IT', { day: '2-digit', month: 'long', year: 'numeric' });

            // Titolo
            sidebar.querySelector('h3').textContent = `Eventi per il giorno: ${dateString}`;

            // Trova compleanni (events) e scadenze (tutte)
            const dateStr = `${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const scadenzeGiorno = modalScadenze.filter(s => s.deadline && s.deadline.slice(5, 10) === dateStr);

            let hasEvents = false;
            sidebarEvents.innerHTML = '';

            // Compleanni
            if (events && events.length > 0) {
                events.forEach(ev => {
                    const card = document.createElement('div');
                    card.className = 'scadenza-card scadenza-compleanno';
                    card.innerHTML = `
                <div class="scadenza-icon">
                    <img src="${ev.profile_picture.startsWith('data:') || ev.profile_picture.startsWith('/') ? ev.profile_picture : '/' + ev.profile_picture}" class="birthday-image" alt="${ev.username}">
                </div>
                <div class="scadenza-content">
                    <div class="scadenza-categoria">
                        <span style="background:#f9a825;color:#fff;font-size:11px;padding:2px 8px;border-radius:7px;margin-right:6px;">Compleanno</span>
                    </div>
                    <div class="scadenza-title" data-tooltip="${ev.username}">${ev.username}</div>
                    <div class="scadenza-desc" data-tooltip="Fai gli auguri!">Fai gli auguri!</div>
                </div>
            `;
                    sidebarEvents.appendChild(card);
                    hasEvents = true;
                });
            }

            // Scadenze di segnalazioni e commesse
            if (scadenzeGiorno && scadenzeGiorno.length > 0) {
                scadenzeGiorno.forEach(scad => {
                    let categoriaClass = '';
                    let label = '';
                    let icon = '';
                    let url = '';
                    let titolo = '';
                    let descrizione = '';

                    if (scad._tipo === 'commessa') {
                        categoriaClass = 'scadenza-commessa';
                        label = '<span style="background:#1e90ff;color:#fff;font-size:11px;padding:2px 8px;border-radius:7px;margin-right:6px;">Commessa</span>';
                        icon = '/assets/icons/commessa.png';
                        url = `index.php?section=commesse&page=commessa&tabella=${encodeURIComponent(scad.table_name)}${scad.titolo ? '&titolo=' + encodeURIComponent(scad.titolo) : ''}`;
                        titolo = scad.titolo || '(Senza titolo)';
                        descrizione = scad['argomentazione_field'] || scad['field_argomentazione'] || scad['argomentazione'] || '-';
                    } else if (scad._tipo === 'segnalazione') {
                        categoriaClass = 'scadenza-segnalazione';
                        label = '<span style="background:#ffd1b0;color:#b35403;font-size:11px;padding:2px 8px;border-radius:7px;margin-right:6px;">Segnalazione</span>';
                        icon = '/assets/icons/report.png';
                        url = `index.php?section=collaborazione&page=form_viewer&form_name=${encodeURIComponent(scad.form_name)}&id=${scad.id}`;
                        titolo = scad.form_name || '(Senza nome)';
                        descrizione = scad['argomentazione'] || scad['argomentazione_field'] || scad['field_argomentazione'] || '-';
                    } else {
                        categoriaClass = 'scadenza-altro';
                        label = '<span style="background:#aaa;color:#fff;font-size:11px;padding:2px 8px;border-radius:7px;margin-right:6px;">Altro</span>';
                        icon = '/assets/icons/report.png';
                        url = '#';
                        titolo = scad.titolo || '(Senza titolo)';
                        descrizione = scad['argomentazione'] || '-';
                    }

                    // LOGICA PRIORITÀ SUL CONTENITORE
                    let p = (scad.priority || '').toLowerCase();
                    let prioClass = '';
                    if (p === 'alta') prioClass = 'prio-alta';
                    else if (p === 'bassa') prioClass = 'prio-bassa';
                    else prioClass = 'prio-media';

                    let priorityLabel = scad.priority || 'Media';

                    const card = document.createElement('div');
                    card.className = `scadenza-card ${categoriaClass} ${prioClass}`;
                    card.innerHTML = `
                <div class="scadenza-icon">
                    <img src="${icon}" alt="Scadenza">
                </div>
                <div class="scadenza-content">
                    <div class="scadenza-categoria">${label}</div>
                    <div class="scadenza-title" data-tooltip="${titolo}">${titolo}</div>
                    <div class="scadenza-desc" data-tooltip="${descrizione}">${descrizione}</div>
                </div>
                <div class="scadenza-priority ${prioClass}" data-tooltip="Priorità: ${priorityLabel}"></div>
            `;

                    card.style.cursor = "pointer";
                    card.addEventListener("click", function () {
                        window.location.href = url;
                    });
                    sidebarEvents.appendChild(card);
                    hasEvents = true;
                });
            }

            if (!hasEvents) {
                sidebarEvents.innerHTML = '<p>Nessun evento per questa data.</p>';
            }
        }

        function formatDeadline(deadline) {
            const oggi = new Date();
            const dataScadenza = new Date(deadline);
            oggi.setHours(0, 0, 0, 0);
            dataScadenza.setHours(0, 0, 0, 0);

            if (dataScadenza.getTime() === oggi.getTime()) return 'Scadenza oggi';
            if (dataScadenza.getTime() < oggi.getTime()) return 'Scaduta il ' + dataScadenza.toLocaleDateString('it-IT');
            return 'Scadenza il ' + dataScadenza.toLocaleDateString('it-IT');
        }

        function loadModalCalendar(date = new Date()) {
            Promise.all([
                customFetch('home', 'getAllBirthdays'),
                customFetch('segnalazioni', 'getScadenze'),
                customFetch('commesse', 'getScadenze')
            ]).then(([resBirthdays, resSegn, resComm]) => {
                modalBirthdays = resBirthdays.success ? resBirthdays.birthdays : [];
                modalScadenze = [];

                if (resSegn.success && Array.isArray(resSegn.scadenze)) {
                    modalScadenze = modalScadenze.concat(resSegn.scadenze.map(s => ({ ...s, _tipo: 'segnalazione' })));
                }
                if (resComm.success && Array.isArray(resComm.scadenze)) {
                    modalScadenze = modalScadenze.concat(resComm.scadenze.map(s => ({ ...s, _tipo: 'commessa' })));
                }

                renderModalCalendar(date);

                // Mostra eventi del giorno corrente nella sidebar (solo all'apertura)
                const today = selectedDayInModal;
                const year = today.getFullYear();
                const month = today.getMonth();
                const day = today.getDate();
                const dateStr = `${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const todayEvents = modalBirthdays.filter(b => b.Data_di_Nascita?.slice(5, 10) === dateStr);

                renderSidebarEvents(day, month, year, todayEvents);
            }).catch(err => {
                console.error("Errore caricamento dati calendario:", err);
                modalBirthdays = [];
                modalScadenze = [];
                renderModalCalendar(date);
            });
        }

        prevMonthBtn.addEventListener('click', () => {
            modalDate.setMonth(modalDate.getMonth() - 1);
            loadModalCalendar(modalDate);
        });

        nextMonthBtn.addEventListener('click', () => {
            modalDate.setMonth(modalDate.getMonth() + 1);
            loadModalCalendar(modalDate);
        });

        window.openModalCalendar = function () {
            toggleModal('modalCalendar', 'open');
            loadModalCalendar(modalDate);
        };

        window.closeModalCalendar = function () {
            toggleModal('modalCalendar', 'close');
        };

        document.getElementById("modalCalendar").addEventListener('click', function (event) {
            if (event.target === this) {
                closeModalCalendar();
            }
        });


    })()
</script>