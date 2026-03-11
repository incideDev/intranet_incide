/**
 * MOM DETAILS PANEL
 * Using SidePanel component
 * 
 * USO:
 * MomDetails.open(momId);
 */

window.MomDetails = (function () {
    'use strict';

    let currentMomId = null;

    function getHtmlTemplate() {
        return `
            <div class="mom-details-content">
                <div class="mom-loading" style="padding: 20px; text-align: center;">Caricamento dettaglio MOM...</div>
            </div>
        `;
    }

    async function open(momId) {
        if (!window.SidePanel) return;

        currentMomId = momId;

        // Open panel with loading state
        window.SidePanel.open({
            title: 'Dettaglio Verbale',
            contentHtml: getHtmlTemplate(),
            onClose: () => {
                currentMomId = null;
            }
        });

        // Load data
        await loadMomData(momId);
    }

    async function loadMomData(momId) {
        try {
            const res = await customFetch('mom', 'getDettaglio', { momId: momId });
            if (res.success && res.data) {
                render(res.data);
            } else {
                renderError(res.message || 'Errore caricamento MOM');
            }
        } catch (e) {
            renderError('Errore di rete');
            console.error(e);
        }
    }

    function render(data) {
        const mom = data.mom || {};
        const items = data.items || [];
        const part = data.partecipanti || [];

        const dateStr = mom.data_meeting ? new Date(mom.data_meeting).toLocaleDateString('it-IT') : 'N/D';
        const linkUrl = `index.php?section=${mom.section || 'commerciale'}&page=mom&action=view&id=${mom.id}`;

        // Items HTML
        let itemsHtml = '';
        if (items.length === 0) {
            itemsHtml = '<div style="color:#666; font-style:italic;">Nessun punto di discussione.</div>';
        } else {
            itemsHtml = items.map(item => {
                const isEve = item.item_type === 'EVE';
                const style = isEve ? 'border-left: 3px solid #28a745; background-color: #f9fff9;' : 'border-left: 3px solid #ccc;';
                const typeLabel = isEve ? '<span style="color:#28a745; font-weight:bold; font-size: 0.8em; text-transform:uppercase;">Evento</span>' : '';

                return `
                    <div class="mom-detail-item" style="margin-bottom: 12px; padding: 10px; border: 1px solid #eee; border-radius: 4px; ${style}">
                        <div style="display:flex; justify-content:space-between; margin-bottom: 4px;">
                            <strong>${escapeHtml(item.descrizione || '')}</strong>
                            ${typeLabel}
                        </div>
                        ${item.item_type === 'OBS'
                        ? `<div style="font-size: 0.85em; color: #666;" data-tooltip="Osservazione: non prevede scadenza">Scadenza/Data: —</div>`
                        : (item.data_target ? `<div style="font-size: 0.85em; color: #666;">Scadenza/Data: ${new Date(item.data_target).toLocaleDateString('it-IT')}</div>` : '')}
                        ${item.responsabile ? `<div style="font-size: 0.85em; color: #666;">Resp: ${escapeHtml(item.responsabile)}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        const html = `
            <div>
                <div style="margin-bottom: 20px;">
                    <div style="font-size: 1.2em; font-weight: 500; margin-bottom: 8px;">${escapeHtml(mom.titolo || 'Senza Titolo')}</div>
                    <div style="color: #666; margin-bottom: 4px;">📅 Data: <strong>${dateStr}</strong></div>
                    <div style="color: #666; margin-bottom: 4px;">📍 Luogo: ${escapeHtml(mom.luogo || '—')}</div>
                    <div style="color: #666; margin-bottom: 12px;">Protocollo: ${escapeHtml(mom.codice_protocollo || mom.progressivo_completo || '—')}</div>
                    
                    <a href="${linkUrl}" class="task-details-primary-action">Apri Verbale Completo</a>
                </div>

                <div class="task-details-section-title">Punti discussi</div>
                <div>${itemsHtml}</div>
            </div>
        `;

        window.SidePanel.setContent({
            title: `Dettaglio Verbale ${mom.progressivo_completo || ''}`,
            contentHtml: html
        });
    }

    function renderError(msg) {
        window.SidePanel.setContent({
            title: 'Errore',
            contentHtml: `<div style="padding: 20px; color: red;">${escapeHtml(msg)}</div>`
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    return { open };

})();
