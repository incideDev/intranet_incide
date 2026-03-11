document.addEventListener('DOMContentLoaded', function() {
    // Gestione menù laterale attivo
    let menuItems = document.querySelectorAll('.gi-menu-item');
    menuItems.forEach(function(item) {
        item.addEventListener('click', function() {
            document.querySelector('.gi-menu-item.active').classList.remove('active');
            this.classList.add('active');
            // In futuro: caricamento dinamico sezione
        });
    });

    // Carica comunicazioni solo se c'è la sezione comunicazioni (impostazioni.php)
    if (document.querySelector('.gi-section-title') && document.getElementById('gi-messaggi-list')) {
        caricaComunicazioni();
    }

    function caricaComunicazioni() {
        customFetch('gestione_intranet', 'getComunicazioni', {})
            .then(res => {
                if (res.success && Array.isArray(res.data)) {
                    renderComunicazioni(res.data);
                } else {
                    document.getElementById('gi-messaggi-list').innerHTML = '<div class="gi-msg-empty">Nessuna comunicazione trovata.</div>';
                }
            });
    }

    function renderComunicazioni(messaggi) {
        const el = document.getElementById('gi-messaggi-list');
        if (!messaggi.length) {
            el.innerHTML = '<div class="gi-msg-empty">Nessuna comunicazione trovata.</div>';
            return;
        }
        let html = '<table class="gi-messaggi-table"><thead><tr><th>Titolo</th><th>Testo</th><th>Visibile dal</th><th>Visibile fino</th></tr></thead><tbody>';
        for (const m of messaggi) {
            html += `<tr>
                <td>${window.escapeHtml(m.titolo)}</td>
                <td>${window.escapeHtml(m.testo)}</td>
                <td>${m.data_inizio ? window.escapeHtml(m.data_inizio) : '-'}</td>
                <td>${m.data_fine ? window.escapeHtml(m.data_fine) : '-'}</td>
            </tr>`;
        }
        html += '</tbody></table>';
        el.innerHTML = html;
    }
});
