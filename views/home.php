<?php
if (!$Session->logged_in) {
    header("Location: login.php");
    exit;
}
?>

<div class="home-container">
    <div class="main-content">
        <div class="welcome-section" id="featured-news-section">
            <div id="featured-news-admin-overlay" style="display: none;"></div>
            <div id="featured-news-content"></div>
            <!-- Admin menu per cambiare news -->
            <div id="featured-news-context-menu" class="custom-context-menu"
                style="display:none;position:absolute;z-index:50;">
                <ul style="margin:0;padding:0;list-style:none;">
                    <li id="context-edit-featured" style="padding:8px 14px;cursor:pointer;">Gestisci contenuto</li>
                </ul>
            </div>
        </div>
        <div class="news-section">
            <h2 class="section-title">News</h2>
            <div class="news-container" id="news-container"></div>
        </div>

        <div class="communications-section" id="communications-section">
            <h2 class="section-title">Comunicazioni</h2>
            <div class="communications-container" id="communications-container"
                oncontextmenu="openNewsletterContextMenu(event)">
                <p class="communication-message">Nessuna comunicazione recente.</p>
            </div>
        </div>

        <ul id="newsletter-context-menu" class="custom-context-menu" style="display: none;">
            <li id="context-upload"> Carica Newsletter</li>
            <li id="context-delete"> Elimina Comunicazione</li>
        </ul>

        <input type="file" id="newsletterFileInput" accept=".html" hidden>
    </div>

    <div class="home-sidebar">
        <div class="item-calendar">
            <div class="item-header">
                <div class="calendar-controls">
                    <button id="prevMonth" class="month-nav">&laquo;</button>
                    <h3 id="monthYear" class="month-year"></h3>
                    <button id="nextMonth" class="month-nav">&raquo;</button>
                </div>
            </div>

            <div id="calendarWrapper">
                <div id="calendarContainer" class="calendar"></div>
                <div id="eventDetailsContainer" class="event-details" style="display: none;">
                    <button id="backToCalendar">← Calendario</button>
                    <h4 id="eventDateTitle">Eventi per il giorno:</h4>
                    <div id="eventDetails"></div>
                </div>
            </div>

            <div id="toggleCalendarSize" class="toggle-icon">
                <img src="/assets/icons/toggle-expand.png" alt="Espandi/Riduci Calendario" class="icon">
            </div>
        </div>

        <?php
        $showSegnalazioni = userHasPermission('view_segnalazioni');
        $showContatti = userHasPermission('view_contatti');
        $showMappa = userHasPermission('view_mappa');
        $showProtocolloEmail = userHasPermission('view_protocollo_email');
        $showArchivio = userHasPermission('view_archivio');
        $showMom = userHasPermission('view_mom');

        if ($showSegnalazioni || $showContatti || $showMappa || $showProtocolloEmail || $showArchivio || $showMom):
            ?>
            <div class="internal-links">
                <h2 class="section-title">App aziendali</h2>
                <ul>
                    <!--
                <li>
                    <a href="index.php?section=protocollo&page=mail" class="internal-link universal-link">
                        <img src="/assets/icons/mail.png" alt="Protocollo" class="link-icon">Protocollo
                    </a>
                </li>
                <li>
                    <a href="index.php?section=task&page=task_dashboard" class="internal-link universal-link">
                        <img src="/assets/icons/task-management.png" alt="Gestione Task" class="link-icon">Gestione Task
                    </a>
                </li>
                <li>
                    <a href="index.php?section=hr&page=hr_area" class="internal-link universal-link">
                        <img src="/assets/icons/users.png" alt="Area HR" class="link-icon">Area HR
                    </a>
                </li>
                <li>
                    <a href="index.php?section=office&page=office_map_public" class="internal-link universal-link">
                        <img src="/assets/icons/map.png" alt="Mappa Ufficio" class="link-icon">Mappa Ufficio
                    </a>
                </li>  
                -->

                    <?php if ($showMom): ?>
                        <li>
                            <a href="index.php?section=collaborazione&page=mom" class="internal-link universal-link">
                                <img src="/assets/icons/mom.png" alt="MOM" class="link-icon">MOM
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($showArchivio): ?>
                        <li>
                            <a href="index.php?section=archivio&page=archivio" class="internal-link universal-link">
                                <img src="/assets/icons/folder.png" alt="Archivio Documenti" class="link-icon">Archivio
                                Documenti
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($showSegnalazioni): ?>
                        <li>
                            <a href="index.php?section=collaborazione&page=segnalazioni_dashboard"
                                class="internal-link universal-link">
                                <img src="/assets/icons/form_icon.png" alt="Segnalazioni" class="link-icon">Segnalazioni
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($showContatti): ?>
                        <li>
                            <a href="index.php?section=hr&page=contacts" class="internal-link universal-link">
                                <img src="/assets/icons/contact.png" alt="Contatti" class="link-icon">Contatti
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($showMappa): ?>
                        <li>
                            <a href="index.php?section=hr&page=office_map_public" class="internal-link universal-link">
                                <img src="/assets/icons/map.png" alt="Mappa Ufficio" class="link-icon">Mappa Ufficio
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if ($showProtocolloEmail): ?>
                        <li>
                            <a href="index.php?section=collaborazione&page=protocollo_email"
                                class="internal-link universal-link">
                                <img src="/assets/icons/mail.png" alt="Protocollo Email" class="link-icon">Protocollo Email
                            </a>
                        </li>
                    <?php endif; ?>

                </ul>
            </div>
        <?php endif; ?>

        <div class="external-links">
            <h2 class="section-title">Link utili</h2>
            <div class="external-links-grid">
                <a href="https://cloud.incide.it:10443/index.php/login?redirect_url=/index.php/apps/files/?dir%3D/%26fileid%3D1119"
                    class="external-link universal-link" target="_blank"><img src="/assets/icons/next-cloud-min.svg"
                        alt="Next Cloud" class="external-link-icon"><span class="link-text">Next-cloud</span></a>
                <a href="https://incideengineering-me.akeroncloud.com/" class="external-link universal-link"
                    target="_blank"><img src="/assets/icons/akeron-min.svg" alt="Akeron"
                        class="external-link-icon"><span class="link-text">Akeron</span></a>
                <a href="https://www.incide.it/" class="external-link universal-link" target="_blank"><img
                        src="/assets/icons/incide.svg" alt="Incide Engineering" class="external-link-icon"><span
                        class="link-text">Incide.it</span></a>
                <a href="https://app.clickup.com/login" class="external-link universal-link" target="_blank"><img
                        src="/assets/icons/clickup.svg" alt="Clickup" class="external-link-icon"><span
                        class="link-text">Clickup</span></a>
                <a href="https://app.goto.com/landing" class="external-link universal-link" target="_blank"><img
                        src="/assets/icons/go-to.svg" alt="GoTo" class="external-link-icon"><span
                        class="link-text">GoTo</span></a>
            </div>
        </div>

        <div class="home-notifiche-box sidebar-block">
            <div class="notifiche-title-row">
                <h2 class="section-title">Ultime notifiche</h2>
                <a href="index.php?section=notifiche&page=centro_notifiche" class="sidebar-section-link"
                    data-tooltip="Vedi tutte le notifiche">
                    Vedi tutte <span class="arrow">→</span>
                </a>
            </div>
            <div class="notifiche-mini-box">
                <ul class="notifiche-list" id="notifiche-sidebar-list"></ul>
            </div>
        </div>

        <div class="home-changelog-box sidebar-block">
            <div class="changelog-title-row">
                <h2 class="section-title">Intranet News</h2>
                <a href="index.php?section=changelog&page=changelog" class="sidebar-section-link"
                    data-tooltip="Vai alla sezione">
                    Vedi tutte <span class="arrow">→</span>
                </a>
            </div>
            <div class="changelog-mini-box" id="home-changelog-box">
                <p class="communication-message">Caricamento...</p>
            </div>
        </div>

    </div>

    <div id="newsletterModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close">&times;</span>
            <iframe id="newsletterFrame" src="" width="100%" height="600px" style="border:none;"
                sandbox="allow-same-origin allow-scripts"></iframe>
        </div>
    </div>

</div>

<?php include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/components/modal_calendar.php'); ?>