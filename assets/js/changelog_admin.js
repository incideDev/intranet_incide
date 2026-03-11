document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('changelogForm');
    const output = document.getElementById('changelogResults');

    const nextVersionLabel = document.getElementById('nextVersionLabel');
    const nuovaMajorCheckbox = document.getElementById('nuova_major');
    const editVersionBtn = document.getElementById('editVersionBtn');
    const customVersionInput = document.getElementById('customVersionInput');

    if (editVersionBtn && customVersionInput && nextVersionLabel) {
        editVersionBtn.addEventListener('click', function () {
            customVersionInput.style.display = 'inline-block';
            customVersionInput.value = nextVersionLabel.textContent.replace(/^v/i, '');
            customVersionInput.focus();
            editVersionBtn.style.display = 'none';
        });

        customVersionInput.addEventListener('blur', function () {
            if (customVersionInput.value.trim() !== '') {
                nextVersionLabel.textContent = customVersionInput.value.trim();
            }
            customVersionInput.style.display = 'none';
            editVersionBtn.style.display = 'inline-block';
        });

        // (Enter per confermare subito)
        customVersionInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                customVersionInput.blur();
            }
        });
    }

    function fetchNextVersion() {
        if (!nextVersionLabel) return;
        const nuovaMajor = nuovaMajorCheckbox?.checked ? 1 : 0;
        customFetch('changelog', 'getNextVersion', { nuova_major: nuovaMajor })
            .then(res => {
                if (res.success && res.data) {
                    nextVersionLabel.textContent = 'v' + res.data;
                } else {
                    nextVersionLabel.textContent = '-';
                }
            });
    }
    if (nuovaMajorCheckbox) {
        nuovaMajorCheckbox.addEventListener('change', fetchNextVersion);
    }
    fetchNextVersion();

function renderChangelogList(items) {
    output.innerHTML = '';
    if (!items || items.length === 0) {
        output.innerHTML = '<p>Nessun aggiornamento disponibile.</p>';
        return;
    }

    const timeline = document.createElement('div');
    timeline.className = 'changelog-timeline';

    items.forEach(row => {
        const item = document.createElement('div');
        item.className = 'timeline-item';

        const marker = document.createElement('div');
        marker.className = 'timeline-marker';

        const content = document.createElement('div');
        content.className = 'timeline-content';

        // === VERSIONE ===
        let versione = row.versione;
        if (!versione && typeof row.versione_major !== 'undefined' && typeof row.versione_minor !== 'undefined') {
            versione = `${row.versione_major}.${row.versione_minor}`;
        }
        if (versione && !/^v/i.test(versione)) versione = 'v' + versione;

        let versionBadge = '';
        if (versione) {
            versionBadge = `<span class="timeline-badge timeline-badge-versione">${escapeHtml(versione)}</span>`;
        }

        // HEADER: data, sezione a sinistra, versione a destra
        let headerHtml = `
            <div class="timeline-header-left">
                <span class="timeline-date">${escapeHtml(row.data)}</span>
                ${row.sezione ? `<span class="timeline-section">${escapeHtml(row.sezione)}</span>` : ''}
            </div>
            <div class="timeline-header-right">
                ${versionBadge}
            </div>
        `;

        const header = document.createElement('div');
        header.className = 'timeline-header timeline-header-flex';
        header.innerHTML = headerHtml;

        // === Titolo e descrizione ===
        let titleHtml = `<div class="timeline-title">${escapeHtml(row.titolo)}</div>`;
        let descHtml = `<div class="timeline-description">${escapeHtml(row.descrizione).replace(/\n/g, "<br>")}</div>`;

        content.appendChild(header);
        content.innerHTML += titleHtml + descHtml;

        item.appendChild(marker);
        item.appendChild(content);

        timeline.appendChild(item);

        // Menù contestuale su click destro
        item.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            document.querySelectorAll('.changelog-context-menu').forEach(menu => menu.remove());

            const menu = document.createElement('div');
            menu.className = 'changelog-context-menu';
            menu.style.top = `${e.clientY}px`;
            menu.style.left = `${e.clientX}px`;

            const elimina = document.createElement('div');
            elimina.className = 'menu-item';
            elimina.textContent = 'Elimina';
            elimina.addEventListener('click', function() {
                showConfirm('Sei sicuro di voler eliminare questo aggiornamento?', function() {
                    deleteChangelog(row.id);
                });
                menu.remove();
            });

            menu.appendChild(elimina);
            document.body.appendChild(menu);

            document.addEventListener('click', function closeMenu() {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            });
        });
    });

    output.appendChild(timeline);
}

    function fetchChangelog() {
        customFetch('changelog', 'getAll')
            .then(res => {
                if (!res.success || !res.data) {
                    output.innerHTML = '<p>Errore nel recupero dati.</p>';
                    return;
                }
                renderChangelogList(res.data);
            })
            .catch(err => {
                output.innerHTML = '<p>Errore durante la richiesta.</p>';
                console.error("Errore fetch changelog:", err);
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(form);

        const payload = {
            titolo: fd.get('titolo'),
            descrizione: fd.get('descrizione'),
            data: fd.get('data'),
            sezione: fd.get('sezione') || null,
            url: fd.get('url') || null,
            nuova_major: nuovaMajorCheckbox?.checked ? 1 : 0
        };
        if (customVersionInput && customVersionInput.style.display !== 'none' && customVersionInput.value.trim() !== '') {
            payload.versione = customVersionInput.value.trim();
        }

        if (!payload.titolo || !payload.descrizione) {
            showToast('Compila tutti i campi obbligatori.', 'warning');
            return;
        }

        customFetch('changelog', 'addChangelog', payload)
            .then(res => {
                if (res.success) {
                    showToast('Aggiornamento salvato con successo.', 'success');
                    form.reset();
                    form.querySelector('[name="data"]').value = new Date().toISOString().split('T')[0];
                    fetchChangelog();
                    fetchNextVersion();
                } else {
                    showToast(res.message || 'Errore nel salvataggio.', 'error');
                }
            })
            .catch(err => {
                showToast('Errore imprevisto.', 'error');
                console.error("Errore salvataggio changelog:", err);
            });
    });

    function deleteChangelog(id) {
        customFetch('changelog', 'deleteChangelog', { id })
            .then(res => {
                if (res.success) {
                    showToast('Aggiornamento eliminato!', 'success');
                    fetchChangelog();
                } else {
                    showToast(res.message || 'Errore durante eliminazione', 'error');
                }
            })
            .catch(() => showToast('Errore di rete', 'error'));
    }

    fetchChangelog();
    
});
