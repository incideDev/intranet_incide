<?php
require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/core/session.php');

if (!$Session->logged_in) {
    header("Location: /");
    die();
}

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

$profileImage = $Session->userinfo['profile_picture'] ?? 'assets/images/default_profile.png';
?>

<nav class="navbar">
    <div class="navbar-container">
        <div class="navbar-left">
            <button id="sidebar-toggle" class="sidebar-toggle">
                <img src="/assets/icons/toggle-on.png" alt="Toggle Sidebar" class="icon-toggle">
            </button>
            <div class="logo-container">
                <a href="index.php?section=home&page=home" class="logo">
                    <img src="assets/logo/logo_incide_engineering-min.png" class="logo-img" alt="Logo Incide">
                </a>
                <a href="index.php?section=home&page=home" class="payoff">
                    <img src="assets/logo/logo_shaping_innovation_white.png" class="payoff-img"
                        alt="Shaping Innovation">
                </a>
            </div>
        </div>

        <div class="navbar-right">
            <div class="navbar-menu-links">
                <ul>
                    <?php
                    require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/core/functions.php');

                    // Sezioni che vuoi mostrare nella navbar
                    $navbarSections = ['archivio', 'qualita', 'collaborazione', 'hr', 'commerciale', 'commesse'];
                    $menuSections = getStaticMenu(null, true, false);

                    foreach ($navbarSections as $sectionKey) {
                        // Verifica che la sezione esista e abbia menu items
                        if (!isset($menuSections[$sectionKey]))
                            continue;

                        $sectionData = $menuSections[$sectionKey];

                        // Verifica che la sezione abbia menu items non vuoti
                        if (empty($sectionData['menus']) || !is_array($sectionData['menus']))
                            continue;

                        $defaultLink = null;
                        $hasVisiblePages = false;

                        foreach ($sectionData['menus'] as $menuItem) {
                            if (!empty($menuItem['submenus']) && is_array($menuItem['submenus'])) {
                                // Verifica che ci siano submenus non vuoti
                                foreach ($menuItem['submenus'] as $submenu) {
                                    if (!empty($submenu['link'])) {
                                        $hasVisiblePages = true;
                                        if ($defaultLink === null) {
                                            $defaultLink = $submenu['link'];
                                        }
                                    }
                                }
                            } elseif (isset($menuItem['link']) && !empty($menuItem['link'])) {
                                $hasVisiblePages = true;
                                if ($defaultLink === null) {
                                    $defaultLink = $menuItem['link'];
                                }
                            }
                        }

                        // Mostra la sezione solo se ha almeno una pagina visibile
                        if (!$hasVisiblePages || !$defaultLink)
                            continue;
                        ?>
                        <li>
                            <a href="<?= htmlspecialchars($defaultLink) ?>"
                                data-section="<?= htmlspecialchars($sectionKey) ?>"
                                class="<?= ($Section ?? '') === $sectionKey ? 'active' : '' ?>">
                                <?= htmlspecialchars($sectionData['label']) ?>
                            </a>
                        </li>
                    <?php } ?>
                </ul>
            </div>


            <div class="notification-wrapper">
                <div id="notification-bell" class="notification-bell">
                    <img src="assets/icons/bell.png" alt="Notifiche" class="bell-icon">
                    <span id="notification-badge" class="notification-badge" style="display:none;">0</span>
                </div>
                <div id="notification-dropdown" class="notification-dropdown">
                    <div class="dropdown-header">Notifiche</div>
                    <ul id="notification-list"></ul>
                </div>
            </div>

            <?php if (isset($Session->userinfo['username'])): ?>
                <img id="navbar-profile-image" src="<?= htmlspecialchars($profileImage) ?>" alt="Profile Image"
                    class="navbar-profile-image">
            <?php endif; ?>
        </div>
    </div>
</nav>

<?php if (isset($Session->userinfo['username'])): ?>
    <div id="profileSidebarOverlay" class="profile-sidebar-overlay" onclick="closeProfileSidebar()"></div>
    <div id="profileSidebar" class="profile-sidebar">
        <div class="profile-header">
            <img id="sidebar-profile-image" src="<?= htmlspecialchars($profileImage) ?>" alt="Profile Image"
                class="profile-image">
            <p class="profile-username"><?= htmlspecialchars($Session->userinfo['username']) ?></p>
        </div>
        <ul class="profile-links">
            <li><a href="index.php?section=profilo&page=gestione_profilo"><img src="assets/icons/user.png" alt=""
                        class="link-icon">Gestione Profilo</a></li>
            <?php
            $isAdmin = isAdmin();
            if ($isAdmin || in_array('view_gestione_intranet', $_SESSION['role_permissions'] ?? [])):
                ?>
                <li>
                    <a href="index.php?section=gestione_intranet&page=gestione_intranet">
                        <img src="assets/icons/settings.png" alt="" class="link-icon">
                        Gestione Intranet
                    </a>
                </li>
            <?php endif; ?>
            <li><a href="index.php?section=home&page=logout"><img src="assets/icons/logout.png" alt=""
                        class="link-icon">Logout</a></li>
        </ul>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const profileMenuBtn = document.getElementById("navbar-profile-image");
        const profileSidebar = document.getElementById('profileSidebar');
        const overlay = document.getElementById('profileSidebarOverlay');

        if (profileMenuBtn) {
            profileMenuBtn.addEventListener('click', function () {
                profileSidebar.style.width = "250px";
                overlay.style.display = "block";
            });
        }

        window.closeProfileSidebar = function () {
            profileSidebar.style.width = "0";
            overlay.style.display = "none";
        };

        const allLinks = document.querySelectorAll('.navbar-right a');
        const currentSection = new URLSearchParams(window.location.search).get('section');
        allLinks.forEach(function (link) {
            if (link.getAttribute('data-section') === currentSection) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    });
</script>

<script src="assets/js/modules/notifications.js"></script>

