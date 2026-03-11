/**
 * MOM Global Views Module
 * Gestisce le viste globali Kanban e Calendario per l'archivio MOM
 */
window.MomGlobalViews = (function () {
    'use strict';

    // Riferimenti ai moduli esterni (devono essere caricati)
    // - window.Kanban (assets/js/modules/kanban.js)
    // - window.CalendarView (assets/js/modules/calendar_view.js)
    // - window.KanbanRenderer (assets/js/modules/kanban_renderer.js)

    function renderMomKanban(items, container) {
        if (!container) return;
        container.innerHTML = '';

        // Stati corretti per i Verbali
        const statuses = [
            { id: 'bozza', title: 'Bozza', color: '#6c757d', statusKey: 'bozza', className: 'status-bozza' },
            { id: 'in_revisione', title: 'In Revisione', color: '#ffc107', statusKey: 'in_revisione', className: 'status-revisione' },
            { id: 'chiuso', title: 'Chiuso', color: '#28a745', statusKey: 'chiuso', className: 'status-chiuso' }
        ];

        // Container principale Kanban
        const kanbanContainer = document.createElement('div');
        kanbanContainer.className = 'kanban-container';

        statuses.forEach(status => {
            const col = document.createElement('div');
            col.className = 'kanban-column';
            col.dataset.statusId = status.id;

            // Header standard
            const header = document.createElement('div');
            header.className = `kanban-header ${status.className || ''} ${status.id}`;
            header.style.borderBottom = `3px solid ${status.color}`;

            header.innerHTML = `
                <span class="kanban-title">${status.title}</span> 
                <span class="badge badge-light count-badge">0</span>
            `;
            col.appendChild(header);

            // Task Container standard
            const taskContainer = document.createElement('div');
            taskContainer.className = 'task-container';
            taskContainer.id = `kanban-${status.id}`;

            // Filtra MOM per stato
            const statusItems = items.filter(mom => {
                const s = mom.stato || 'bozza';
                return s === status.id;
            });

            // Update badge
            header.querySelector('.count-badge').textContent = statusItems.length;

            statusItems.forEach(mom => {
                // USA KANBAN RENDERER CENTRALIZZATO (Refactoring)
                if (window.KanbanRenderer) {
                    const taskData = {
                        id: mom.id,
                        protocollo: mom.progressivoCompleto,
                        titolo: mom.titolo,
                        data_meeting: mom.dataMeeting,
                        luogo: mom.luogo,
                        creato_da_nome: mom.creatoreNome,
                        color: '#3498DB'
                    };

                    const renderResult = window.KanbanRenderer.renderTask(taskData, {
                        tabella: 'mom',
                        kanbanType: 'mom'
                    });

                    const card = renderResult.taskElement;

                    // Re-attach custom Drag Start (necessario per dynamic elements)
                    card.addEventListener('dragstart', (e) => {
                        e.dataTransfer.setData('text/plain', mom.id);
                        e.dataTransfer.effectAllowed = 'move';
                        card.classList.add('dragging');
                    });
                    card.addEventListener('dragend', () => card.classList.remove('dragging'));

                    // Override Double Click per MOM specifico
                    card.ondblclick = (e) => {
                        e.stopPropagation();
                        if (window.momApp && window.momApp.apriMom) {
                            window.momApp.apriMom(mom.id, 'view');
                        }
                    };

                    taskContainer.appendChild(card);
                } else {
                    console.error('KanbanRenderer non caricato. Impossibile renderizzare card.');
                }
            });

            // --- DRAG AND DROP HANDLERS (Column) ---
            col.addEventListener('dragover', (e) => {
                e.preventDefault();
                col.classList.add('drag-over');
            });

            col.addEventListener('dragleave', (e) => {
                col.classList.remove('drag-over');
            });

            col.addEventListener('drop', async (e) => {
                e.preventDefault();
                col.classList.remove('drag-over');

                const momId = e.dataTransfer.getData('text/plain');
                if (!momId) return;

                const draggedCard = document.querySelector(`.task[data-mom-id="${momId}"]`);
                if (!draggedCard) return;

                const currentStatus = draggedCard.closest('.kanban-column').dataset.statusId;
                if (currentStatus === status.id) return;

                taskContainer.appendChild(draggedCard);

                try {
                    const response = await customFetch('mom', 'updateMomStatus', {
                        momId: momId,
                        stato: status.id
                    });

                    if (response.success) {
                        showToast(`Stato aggiornato a: ${status.title}`, 'success');
                        if (window.momApp && window.momApp.caricaArchivio) {
                            window.momApp.caricaArchivio();
                        }
                    } else {
                        showToast(response.message || 'Errore aggiornamento stato', 'error');
                        if (window.momApp && window.momApp.caricaArchivio) {
                            window.momApp.caricaArchivio();
                        }
                    }
                } catch (err) {
                    console.error('Drop error:', err);
                    showToast('Errore di comunicazione', 'error');
                }
            });

            col.appendChild(taskContainer);
            kanbanContainer.appendChild(col);
        });

        container.appendChild(kanbanContainer);
    }

    function renderGlobalCalendar(items, container) {
        if (!container || !window.CalendarView) return;

        const events = items.map(item => {
            if (!item.dataTarget) return null;

            return {
                id: `momitem_${item.id}`,
                title: `${item.itemCode || ''} ${item.titolo}`,
                start: item.dataTarget,
                end: item.dataTarget,
                color: item.itemType === 'EVE' ? '#28a745' : '#007bff',
                url: `?page=mom&action=view&id=${item.momId}`,
                meta: {
                    kind: 'mom',
                    mom_id: item.momId,
                    raw: item
                }
            };
        }).filter(e => e !== null);

        window.CalendarView.init({
            containerId: container.id,
            provider: async () => events,
            view: 'month'
        });

        window.CalendarView.refresh();
    }

    return {
        renderMomKanban,
        renderGlobalCalendar
    };

})();
