/**
 * GLOBAL RIGHT DRAWER COMPONENT
 * 
 * Replaces and extends the old SidePanel component.
 * Supports multiple views via registration.
 * 
 * API:
 * - GlobalRightDrawer.openView(viewName, payload)
 * - GlobalRightDrawer.close()
 * - GlobalRightDrawer.registerView(viewName, factoryFn)
 * - GlobalRightDrawer.setContent({ title, contentHtml })
 */

window.GlobalDrawer = (function () {
    'use strict';

    let state = {
        panel: null,
        isOpen: false,
        onCloseCallback: null,
        views: {},
        currentView: null
    };

    function init() {
        if (state.panel) return;

        // Create or reuse panel
        let panel = document.getElementById('global-drawer-panel');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'global-drawer-panel';
            panel.className = 'global-drawer-panel';
            panel.style.zIndex = '1100';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');

            // Initial Structure
            panel.innerHTML = `
                <div class="global-drawer-content">
                    <div class="global-drawer-header">
                        <h2 class="global-drawer-title"></h2>
                        <button class="global-drawer-close" aria-label="Chiudi">✖</button>
                    </div>
                    <div class="global-drawer-body"></div>
                </div>
            `;
            document.body.appendChild(panel);
        }

        state.panel = panel;

        // Basic Event Listeners
        const closeBtn = panel.querySelector('.global-drawer-close');
        if (closeBtn) closeBtn.addEventListener('click', close);

        // Integration with KeyboardManager is handled in main_core.js
        // but we keep a fail-safe local listener if KeyboardManager is not present
        if (!window.KeyboardManager) {
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && state.isOpen) {
                    if (!panel.hasAttribute('data-esc-close') || panel.getAttribute('data-esc-close') !== '0') {
                        close();
                    }
                }
            });
        }
    }

    /**
     * Registers a view factory function
     * factoryFn(payload) should return { title, html, onReady, onClose }
     */
    function registerView(name, factoryFn) {
        state.views[name] = factoryFn;
    }

    /**
     * Opens a registered view
     */
    function openView(viewName, payload = {}) {
        if (!state.panel) init();

        // If another view is open, close it first (or just swap content)
        if (state.isOpen && state.currentView !== viewName) {
            // Optional: call previous view's onClose if needed
        }

        const factory = state.views[viewName];
        if (!factory) {
            console.error(`GlobalDrawer: View "${viewName}" not registered.`);
            return;
        }

        const viewConfig = factory(payload);
        state.currentView = viewName;

        open({
            title: viewConfig.title,
            contentHtml: viewConfig.html,
            onClose: viewConfig.onClose,
            escClose: viewConfig.escClose !== false
        });

        if (typeof viewConfig.onReady === 'function') {
            // Wait for DOM to be updated
            requestAnimationFrame(() => {
                viewConfig.onReady(state.panel.querySelector('.global-drawer-body'), payload);
            });
        }
    }

    function open(config = {}) {
        if (!state.panel) init();

        const { title, contentHtml, onClose, escClose = true } = config;

        // Update content
        setContent({ title, contentHtml });

        // Update settings
        state.onCloseCallback = onClose;
        if (escClose) {
            state.panel.removeAttribute('data-esc-close');
        } else {
            state.panel.setAttribute('data-esc-close', '0');
        }

        // Show
        state.isOpen = true;
        state.panel.classList.add('open');
    }

    function setContent({ title, contentHtml }) {
        if (!state.panel) init();

        if (title !== undefined) {
            const titleEl = state.panel.querySelector('.global-drawer-title');
            if (titleEl) titleEl.textContent = title;
        }

        if (contentHtml !== undefined) {
            const bodyEl = state.panel.querySelector('.global-drawer-body');
            if (bodyEl) bodyEl.innerHTML = contentHtml;
        }
    }

    function close() {
        if (!state.isOpen) return;

        state.isOpen = false;
        if (state.panel) state.panel.classList.remove('open');

        if (typeof state.onCloseCallback === 'function') {
            state.onCloseCallback();
            state.onCloseCallback = null;
        }

        state.currentView = null;
    }

    function getPanel() {
        if (!state.panel) init();
        return state.panel;
    }

    // BACKWARD COMPATIBILITY WRAPPER (DEPRECATED)
    window.SidePanel = {
        open: function (config) {
            console.warn("SidePanel is DEPRECATED. Use GlobalDrawer.openView instead.");
            // Manual open for non-registered views if needed, or map to openView if possible
            open(config);
        },
        close: close,
        setContent: setContent,
        getPanel: getPanel
    };

    return {
        registerView,
        openView,
        open, // exposed for flexibility
        close,
        setContent,
        getPanel
    };

})();
