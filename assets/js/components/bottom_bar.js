/**
 * BOTTOM BAR COMPONENT
 *
 * Barra azioni fissa in basso al viewport, riutilizzabile da qualsiasi pagina.
 * Se nessuna pagina chiama setConfig(), la barra non appare.
 *
 * API:
 * - BottomBar.setConfig({ actions[], statusText? })
 * - BottomBar.updateAction(actionId, { label?, disabled?, hidden?, tooltip? })
 * - BottomBar.setStatus(text)
 * - BottomBar.show()
 * - BottomBar.hide()
 * - BottomBar.isVisible()
 * - BottomBar.destroy()
 *
 * Evento emesso su document:
 *   'bottomBar:action' -> { detail: { actionId: string } }
 */
window.BottomBar = (function () {
    'use strict';

    var state = {
        bar: null,
        visible: false,
        actions: [],
        statusText: ''
    };

    var BAR_ID = 'bottom-bar';
    var CLS_BAR = 'bottom-bar';
    var CLS_VISIBLE = 'bottom-bar--visible';
    var CLS_STATUS = 'bottom-bar__status';
    var CLS_ACTIONS = 'bottom-bar__actions';
    var BODY_CLS = 'has-bottom-bar';
    var EVENT_NAME = 'bottomBar:action';

    /* ---- Creazione DOM (lazy, una sola volta) ---- */
    function init() {
        if (state.bar) return;

        // Force right alignment styles as per user request
        var styleId = 'bottom-bar-styles-forced';
        if (!document.getElementById(styleId)) {
            var css =
                '.bottom-bar { display: flex !important; align-items: center !important; justify-content: space-between !important; } ' +
                '.bottom-bar__status { flex-grow: 1; text-align: left; } ' +
                '.bottom-bar__actions { display: flex !important; gap: 10px !important; margin-left: auto !important; justify-content: flex-end !important; }';
            var style = document.createElement('style');
            style.id = styleId;
            style.type = 'text/css';
            style.appendChild(document.createTextNode(css));
            document.head.appendChild(style);
        }

        var bar = document.createElement('div');
        bar.id = BAR_ID;
        bar.className = CLS_BAR;
        bar.innerHTML =
            '<div class="' + CLS_STATUS + '"></div>' +
            '<div class="' + CLS_ACTIONS + '"></div>';

        // Event delegation: un solo listener per tutti i bottoni
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action-id]');
            if (!btn || btn.disabled) return;
            document.dispatchEvent(new CustomEvent(EVENT_NAME, {
                detail: { actionId: btn.dataset.actionId }
            }));
        });

        document.body.appendChild(bar);
        state.bar = bar;
    }

    /* ---- Render bottoni ---- */
    function renderActions() {
        if (!state.bar) return;
        var container = state.bar.querySelector('.' + CLS_ACTIONS);
        container.innerHTML = '';

        for (var i = 0; i < state.actions.length; i++) {
            var action = state.actions[i];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button' + (action.className ? ' ' + action.className : '');
            btn.dataset.actionId = action.id;
            btn.textContent = action.label || '';

            if (action.disabled) btn.disabled = true;
            if (action.hidden) btn.style.display = 'none';
            if (action.tooltip) btn.setAttribute('data-tooltip', action.tooltip);

            container.appendChild(btn);
        }
    }

    /* ---- Render status ---- */
    function renderStatus() {
        if (!state.bar) return;
        var el = state.bar.querySelector('.' + CLS_STATUS);
        el.textContent = state.statusText || '';
        el.style.display = state.statusText ? '' : 'none';
    }

    /* ============ API PUBBLICA ============ */

    /**
     * Configura e mostra la barra.
     * @param {Object} config
     * @param {Array}  config.actions - [{ id, label, className?, disabled?, hidden?, tooltip? }]
     * @param {string} [config.statusText] - Testo di stato (opzionale)
     */
    function setConfig(config) {
        if (!state.bar) init();

        state.actions = (config.actions || []).map(function (a) {
            return {
                id: a.id,
                label: a.label || '',
                className: a.className || '',
                disabled: !!a.disabled,
                hidden: !!a.hidden,
                tooltip: a.tooltip || ''
            };
        });
        state.statusText = config.statusText || '';

        renderActions();
        renderStatus();
        show();
    }

    /**
     * Aggiorna una singola azione senza ri-renderizzare tutto.
     * @param {string} actionId
     * @param {Object} patch - { label?, disabled?, hidden?, tooltip? }
     */
    function updateAction(actionId, patch) {
        if (!state.bar) return;

        // Aggiorna stato interno
        var actionObj = null;
        for (var i = 0; i < state.actions.length; i++) {
            if (state.actions[i].id === actionId) {
                actionObj = state.actions[i];
                break;
            }
        }
        if (!actionObj) return;

        if ('label' in patch) actionObj.label = patch.label;
        if ('disabled' in patch) actionObj.disabled = patch.disabled;
        if ('hidden' in patch) actionObj.hidden = patch.hidden;
        if ('tooltip' in patch) actionObj.tooltip = patch.tooltip;

        // Aggiorna il bottone nel DOM
        var btn = state.bar.querySelector('[data-action-id="' + actionId + '"]');
        if (!btn) return;
        if ('label' in patch) btn.textContent = patch.label;
        if ('disabled' in patch) btn.disabled = !!patch.disabled;
        if ('hidden' in patch) btn.style.display = patch.hidden ? 'none' : '';
        if ('tooltip' in patch) {
            if (patch.tooltip) btn.setAttribute('data-tooltip', patch.tooltip);
            else btn.removeAttribute('data-tooltip');
        }
    }

    /**
     * Aggiorna solo il testo di stato.
     * @param {string} text
     */
    function setStatus(text) {
        state.statusText = text || '';
        renderStatus();
    }

    /** Mostra la barra. */
    function show() {
        if (!state.bar) init();
        state.bar.classList.add(CLS_VISIBLE);
        document.body.classList.add(BODY_CLS);
        state.visible = true;
    }

    /** Nasconde la barra. */
    function hide() {
        if (!state.bar) return;
        state.bar.classList.remove(CLS_VISIBLE);
        document.body.classList.remove(BODY_CLS);
        state.visible = false;
    }

    /** @returns {boolean} */
    function isVisible() {
        return state.visible;
    }

    /** Rimuove il DOM e resetta lo stato. */
    function destroy() {
        hide();
        if (state.bar && state.bar.parentNode) {
            state.bar.parentNode.removeChild(state.bar);
        }
        state.bar = null;
        state.actions = [];
        state.statusText = '';
    }

    return {
        setConfig: setConfig,
        updateAction: updateAction,
        setStatus: setStatus,
        show: show,
        hide: hide,
        isVisible: isVisible,
        destroy: destroy
    };

})();
