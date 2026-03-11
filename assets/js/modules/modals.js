window.toggleModal = function (modalId, action = 'toggle') {
    const modal = document.getElementById(modalId);
    if (!modal) {
        console.error(`❌ Modal con ID "${modalId}" non trovato.`);
        return;
    }

    switch (action) {
        case 'open':
            modal.style.display = 'block';
            break;
        case 'close':
            modal.style.display = 'none';
            break;
        case 'toggle':
            modal.style.display = (modal.style.display === 'block') ? 'none' : 'block';
            break;
        default:
            console.warn(`⚠️ Azione "${action}" non valida.`);
    }
};

// ✅ Chiude il modale cliccando fuori
document.addEventListener('click', function (event) {
    document.querySelectorAll('.modal').forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
