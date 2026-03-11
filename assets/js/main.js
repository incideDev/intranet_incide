document.addEventListener('DOMContentLoaded', function() {
    // Gestione del profilo sidebar
    var profileMenuBtn = document.querySelector('.profile-picture img');
    var profileSidebar = document.getElementById("profile-sidebar");

    if (profileMenuBtn) {
        profileMenuBtn.addEventListener('click', function() {
            if (profileSidebar) {
                profileSidebar.style.display = 'block';
            }
        });
    }

    if (profileSidebar) {
        profileSidebar.querySelector('.closebtn').addEventListener('click', function() {
            profileSidebar.style.display = 'none';
        });
    }
}); 
