let notificheInCaricamento = false;

function caricaNotifiche() {
    if (notificheInCaricamento) return;
    if (!navigator.onLine) return;
    notificheInCaricamento = true;
    customFetch('notifiche', 'get_unread', {}, { showLoader: false })
        .then(res => {
            if (!res || !res.success) {
                nascondiBadgeNotifiche();
                svuotaDropdownNotifiche();
                return;
            }

            const notifiche = res.notifiche || [];
            const container = document.getElementById("notification-list");
            const badge = document.getElementById("notification-badge");

            if (!container || !badge) return;

            container.innerHTML = "";

            if (notifiche.length === 0) {
                badge.style.display = "none";
                container.innerHTML = '<li class="notifica-vuota"><em>Nessuna nuova notifica</em></li>';
            } else {
                badge.textContent = notifiche.length;
                badge.style.display = "inline-block";

                // Mostra al massimo 10 notifiche non lette
                notifiche.slice(0, 10).forEach(notifica => {
                    const li = document.createElement("li");
                    li.className = "notification-item notifica-non-letta";

                    if (notifica.link) {
                        const a = document.createElement("a");
                        a.href = notifica.link;
                        a.innerHTML = notifica.messaggio;

                        a.addEventListener("click", async (e) => {
                            e.preventDefault();

                            const formData = new FormData();
                            formData.append('notifica_id', notifica.id);
                            const r = await customFetch("notifiche", "mark_as_read", formData);

                            if (r.success) {
                                li.classList.remove("notifica-non-letta");
                                li.classList.add("notifica-letta");
                                window.location.href = notifica.link;
                            } else {
                                showToast("Errore nel marcare la notifica come letta", "error");
                            }
                        });

                        li.appendChild(a);
                    } else {
                        li.innerHTML = `<span>${notifica.messaggio}</span>`;
                    }

                    container.appendChild(li);
                });
            }

            const footer = document.createElement("li");
            footer.className = "notification-footer";
            footer.innerHTML = `<a href="index.php?section=notifiche&page=centro_notifiche">Vai al centro notifiche →</a>`;
            container.appendChild(footer);
        })
        .catch(() => {
            nascondiBadgeNotifiche();
            svuotaDropdownNotifiche();
        })
        .finally(() => {
            notificheInCaricamento = false;
        });
}

// Funzioni helper per fallback silenzioso
function nascondiBadgeNotifiche() {
    const badge = document.getElementById("notification-badge");
    if (badge) badge.style.display = "none";
}
function svuotaDropdownNotifiche() {
    const container = document.getElementById("notification-list");
    if (container) container.innerHTML = '<li class="notifica-vuota"><em>Nessuna nuova notifica</em></li>';
}

// Funzione per gestire l'apertura/chiusura del dropdown nella navbar
function toggleDropdownNotifiche(event) {
    event.stopPropagation();
    const dropdown = document.getElementById("notification-dropdown");
    if (!dropdown) return;

    const isVisible = dropdown.classList.contains("visible");

    // Chiude eventuali dropdown aperti
    document.querySelectorAll(".notification-dropdown.visible").forEach(el => {
        el.classList.remove("visible");
    });

    if (!isVisible) {
        dropdown.classList.add("visible");
    }
}

document.addEventListener("click", function (e) {
    const dropdown = document.getElementById("notification-dropdown");
    const bell = document.getElementById("notification-bell");
    if (!dropdown || !bell) return;
    if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
        dropdown.classList.remove("visible");
    }
});

// Funzione per caricare il centro notifiche
function caricaCentroNotifiche() {
    const container = document.getElementById("notifiche-lista");
    if (!container) return;

    customFetch("notifiche", "get_all")
        .then(res => {
            if (!res || !res.success || !Array.isArray(res.notifiche)) {
                container.innerHTML = "<p>Nessuna notifica disponibile.</p>";
                return;
            }
            let notifiche = res.notifiche;
            const filtroTesto = document.getElementById('cerca-notifiche')?.value.toLowerCase() || '';
            const soloNonLette = window.__soloNonLette || false;

            // Filtri
            if (filtroTesto) notifiche = notifiche.filter(n => n.messaggio.toLowerCase().includes(filtroTesto));
            if (soloNonLette) notifiche = notifiche.filter(n => n.letto != 1);

            if (notifiche.length === 0) {
                container.innerHTML = "<p>Nessuna notifica disponibile.</p>";
                return;
            }

            container.innerHTML = "";
            let ultimoGiorno = "";

            notifiche.forEach(n => {
                const dataNotifica = new Date(n.creato_il);
                const giornoCorrente = formattaDataSeparatore(dataNotifica);

                if (giornoCorrente !== ultimoGiorno) {
                    ultimoGiorno = giornoCorrente;
                    const separatore = document.createElement("div");
                    separatore.className = "notifiche-separatore";
                    separatore.textContent = giornoCorrente;
                    container.appendChild(separatore);
                }

                const box = document.createElement("div");
                box.className = "notifica-box " + (parseInt(n.letto) === 1 ? "letta" : "non-letta");
                if (parseInt(n.pinned) === 1) box.classList.add("pinned");

                // Evidenzia notifiche Intranet News
                if (n.messaggio && n.messaggio.includes('notifica-intranet-news')) {
                    box.classList.add('notifica-intranet-news-box');
                }
                if (n.messaggio && n.messaggio.includes('notifica-categoria-segnalazioni')) {
                    box.classList.add('notifica-bordo-segnalazioni');
                }

                // --- PIN ICONA
                const pinIcon = document.createElement("span");
                pinIcon.className = "notifica-pin";
                pinIcon.title = n.pinned == 1 ? "Non fissare più" : "Fissa in alto";
                pinIcon.innerHTML = n.pinned == 1 ? "&#9733;" : "&#9734;";
                pinIcon.style.cursor = "pointer";
                pinIcon.onclick = async (e) => {
                    e.stopPropagation();
                    const formData = new FormData();
                    formData.append('notifica_id', n.id);
                    await customFetch("notifiche", "toggle_pin", formData);
                    caricaCentroNotifiche();
                };
                
                // --- DELETE ICONA
                const deleteIcon = document.createElement("span");
                deleteIcon.className = "notifica-delete";
                deleteIcon.title = "Elimina notifica";
                deleteIcon.innerHTML = "&times;";
                deleteIcon.style.cursor = "pointer";
                deleteIcon.onclick = (e) => {
                    e.stopPropagation();
                    showConfirm("Vuoi davvero eliminare questa notifica?", async () => {
                        const formData = new FormData();
                        formData.append('notifica_id', n.id);
                        const res = await customFetch("notifiche", "delete", formData);
                        if (res.success) {
                            showToast("Notifica eliminata");
                            caricaCentroNotifiche();
                        } else {
                            showToast("Errore nell'eliminazione", "error");
                        }
                    });
                };
                
                // --- CONTENUTO ---
                const contenuto = document.createElement("div");
                contenuto.className = "notifica-contenuto";

                const messaggio = document.createElement("span");
                messaggio.className = "messaggio";
                messaggio.innerHTML = n.messaggio;

                const orario = document.createElement("span");
                orario.className = "orario";
                orario.textContent = dataNotifica.toLocaleTimeString('it-IT', {
                    hour: '2-digit',
                    minute: '2-digit'
                });

                contenuto.appendChild(messaggio);
                contenuto.appendChild(orario);

                if (n.link) {
                    const link = document.createElement("a");
                    link.href = n.link;
                    link.appendChild(contenuto);

                    link.addEventListener("click", async (e) => {
                        e.preventDefault();
                        const formData = new FormData();
                        formData.append('notifica_id', n.id);
                        const r = await customFetch("notifiche", "mark_as_read", formData);
                        if (r.success) {
                            box.classList.remove("non-letta");
                            box.classList.add("letta");
                            window.location.href = n.link;
                        }
                    });
                    
                    box.appendChild(pinIcon);
                    box.appendChild(link);
                    box.appendChild(deleteIcon);
                } else {
                    box.appendChild(pinIcon);
                    box.appendChild(contenuto);
                    box.appendChild(deleteIcon);
                }

                container.appendChild(box);
            });
        })
        .catch(() => {
            container.innerHTML = "<p>Nessuna notifica disponibile.</p>";
        });
}

// Funzione per etichette "Ieri", "Lunedì", ecc.
function formattaDataSeparatore(data) {
    const oggi = new Date();
    const diffGiorni = Math.floor((oggi - data) / (1000 * 60 * 60 * 24));

    if (diffGiorni === 0) return "Oggi";
    if (diffGiorni === 1) return "Ieri";
    if (diffGiorni <= 6) {
        return data.toLocaleDateString('it-IT', { weekday: 'long' });
    }
    return data.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

document.addEventListener("DOMContentLoaded", () => {
    // Se esiste la campanella nella navbar, carica il dropdown
    if (document.getElementById("notification-bell") && document.getElementById("notification-list")) {
        caricaNotifiche();
        const campanella = document.getElementById("notification-bell");
        if (campanella) {
            campanella.addEventListener("click", toggleDropdownNotifiche);
        }
    }

    // Se esiste il contenitore del centro notifiche, caricalo
    if (document.getElementById("notifiche-lista")) {
        caricaCentroNotifiche();
    }
});

function filtraSoloNonLette() {
    window.__soloNonLette = !window.__soloNonLette;
    caricaCentroNotifiche();
}

function segnaTutteComeLette() {
    showConfirm("Vuoi segnare tutte le notifiche come lette?", async () => {
        const res = await customFetch("notifiche", "mark_all_as_read");
        if (res.success) {
            showToast("Tutte le notifiche sono state segnate come lette");
            caricaCentroNotifiche();
        } else {
            showToast("Errore nell'operazione", "error");
        }
    });
}

function eliminaTutteNotifiche() {
    showConfirm("Vuoi eliminare tutte le notifiche?", async () => {
        const res = await customFetch("notifiche", "delete_all");
        if (res.success) {
            showToast("Tutte le notifiche sono state eliminate");
            caricaCentroNotifiche();
        } else {
            showToast("Errore nell'operazione", "error");
        }
    });
}

window.loadNotificationsCount = caricaNotifiche;

setInterval(() => { 
  if (window.loadNotificationsCount) window.loadNotificationsCount();
}, 30000);

// --- ULTIME NOTIFICHE HOME SIDEBAR --- //
function caricaNotificheSidebarHome() {
    customFetch('notifiche', 'getUltime', { limit: 5 }, { showLoader: false })
        .then(res => {
            const list = document.getElementById('notifiche-sidebar-list');
            if (!list) return;
            list.innerHTML = '';
            if (!res || !res.success || !Array.isArray(res.data) || res.data.length === 0) {
                list.innerHTML = '<li class="notifica-sidebar-item">Nessuna notifica recente</li>';
                return;
            }
            function stripHtml(html) {
                var tmp = document.createElement("div");
                tmp.innerHTML = html;
                return tmp.textContent || tmp.innerText || "";
            }

            res.data.forEach(notifica => {
                let li = document.createElement('li');
                li.className = "notifica-sidebar-item" + (notifica.letto == 0 ? " notifica-unread" : "");
                let msg = stripHtml(notifica.messaggio);

                let meta = document.createElement('div');
                meta.className = "notifica-meta";
                let dataSpan = document.createElement('span');
                dataSpan.className = "notifica-data";
                dataSpan.textContent = formatDataNotificaSidebar(notifica.creato_il);
                meta.appendChild(dataSpan);

                if (notifica.link) {
                    let freccia = document.createElement('a');
                    freccia.href = notifica.link;
                    freccia.className = "notifica-link";
                    freccia.setAttribute('data-tooltip', "Apri notifica");
                    freccia.innerHTML = '↗';

                    freccia.addEventListener('click', async function (e) {
                        e.preventDefault();
                        const formData = new FormData();
                        formData.append('notifica_id', notifica.id);
                        const r = await customFetch("notifiche", "mark_as_read", formData);
                        if (r.success) {
                            li.classList.remove("notifica-unread");
                            li.classList.add("notifica-letta");
                            window.location.href = notifica.link;
                        } else {
                            showToast("Errore nel marcare la notifica come letta", "error");
                        }
                    });

                    meta.appendChild(freccia);
                }

                li.innerHTML = `
                    <div class="notifica-messaggio">${msg}</div>
                `;
                li.appendChild(meta);
                list.appendChild(li);
            });
        })
        .catch(() => {
            const list = document.getElementById('notifiche-sidebar-list');
            if (list) list.innerHTML = '<li class="notifica-sidebar-item">Nessuna notifica recente</li>';
        });

}

function formatDataNotificaSidebar(data) {
    var d = new Date(data);
    return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById('notifiche-sidebar-list')) {
        caricaNotificheSidebarHome();
    }
});
