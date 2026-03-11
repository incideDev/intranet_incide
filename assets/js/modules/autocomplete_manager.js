/**
 * AUTOCOMPLETE MANAGER v2.0 - Generic Autocomplete & Dropdown System
 *
 * A generic, reusable module for all autocomplete and dropdown functionality.
 * Context-agnostic: no references to specific pages or modules.
 *
 * Core Features:
 * - Data loading with intelligent caching
 * - Searchable dropdowns with free-text input support
 * - Dependent field relationships (master → detail)
 * - Auto-population of related fields
 * - Dynamic initialization via MutationObserver
 *
 * Public API (window.autocompleteManager):
 * - registerDataSource(name, config) → Register a new data source
 * - loadData(sourceName, params, callback) → Load data with caching
 * - createDropdown(container, options, config) → Create searchable dropdown
 * - initField(element, config) → Initialize autocomplete on element
 * - initContainer(selector, config) → Initialize all fields in container
 * - linkFields(masterEl, detailEl, config) → Setup master-detail relationship
 * - clearCache(sourceName?) → Clear cache for source or all
 *
 * Utility Functions (window.*):
 * - escapeHtml(str) → Escape HTML to prevent XSS
 * - showSearchableDropdown(container, options, config) → Show dropdown UI
 *
 * @version 2.0.0
 * @license MIT
 */

(function () {
    'use strict';

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Default styling configuration (can be overridden)
     * @type {Object}
     */
    const defaultStyles = {
        dropdown: {
            zIndex: 99999,
            minWidth: 250,
            maxWidth: 400,
            maxHeight: 220,
            background: '#fff',
            border: '1px solid #ccc',
            borderRadius: '4px',
            boxShadow: '0 4px 16px rgba(0,0,0,0.2)'
        },
        searchInput: {
            padding: '10px 12px',
            fontSize: '14px',
            borderBottom: '1px solid #e0e0e0'
        },
        option: {
            padding: '10px 12px',
            hoverBackground: '#f5f5f5',
            selectedBackground: '#e3f2fd',
            borderBottom: '1px solid #f0f0f0'
        },
        freeInput: {
            color: '#108b3c',
            hoverBackground: '#e8f5e9'
        },
        noResults: {
            padding: '12px',
            color: '#999',
            textAlign: 'center'
        }
    };

    /**
     * Minimum characters required to enable free-text input option
     * @type {number}
     */
    const FREE_INPUT_MIN_CHARS = 3;

    // =========================================================================
    // DATA CACHE
    // =========================================================================

    /**
     * Cache storage for loaded data
     * Structure: { sourceName: data } or { sourceName: { paramKey: data } }
     * @type {Object}
     */
    const dataCache = {};

    /**
     * Pending requests to prevent duplicate fetches
     * @type {Object}
     */
    const pendingRequests = {};

    // =========================================================================
    // DATA SOURCES REGISTRY
    // =========================================================================

    /**
     * Registry of data sources with their configurations
     * @type {Object}
     */
    const dataSources = {
        // Built-in: Personnel (from 'personale' table)
        personnel: {
            service: 'commesse',
            action: 'getPersonale',
            mapResponse: (data) => data.map(item => ({
                value: item.nominativo,
                label: item.nominativo,
                role: item.ruolo || '',
                _raw: item
            })),
            cacheKey: () => 'personnel'
        },

        // Built-in: Companies (from 'anagrafiche' table)
        companies: {
            service: 'protocollo_email',
            action: 'caricaAziende',
            mapResponse: (data) => data.map(item => ({
                value: item.id,
                label: item.ragionesociale || item.label || '',
                _raw: item
            })),
            cacheKey: () => 'companies'
        },

        // Built-in: Jobs/Projects (from 'elenco_commesse' and 'gar_comprovanti_progetti')
        jobs: {
            service: 'commesse',
            action: 'searchCommesse',
            mapResponse: (data) => data.map(item => ({
                value: item.value || item.codice,
                label: item.label || item.value,
                oggetto: item.oggetto || '',
                cliente: item.cliente || '',
                _raw: item
            })),
            cacheKey: (params) => `jobs_${params.q || ''}`
        },

        // Built-in: All contacts (from 'anagrafiche_contatti' table)
        contacts: {
            service: 'protocollo_email',
            action: 'getTuttiContatti',
            mapResponse: (data) => data.map(item => ({
                value: item.id,
                label: item.cognome_e_nome || item.email || '',
                email: item.email || '',
                _raw: item
            })),
            cacheKey: () => 'contacts'
        },

        // Built-in: Contacts by company (parameterized)
        // Built-in: Contacts by company (parameterized)
        // Built-in: Contacts by company (Unified Service)
        contactsByCompany: {
            service: 'contacts',
            action: 'getCompanyContacts',
            mapResponse: (data) => data.map(item => ({
                value: item.id,
                label: item.nomeCompleto || item.cognome_e_nome || item.label || item.email || '',
                email: item.email || '',
                _raw: item
            })),
            cacheKey: (params) => `contacts_company_${params.companyId || params.azienda_id || params.azienda || ''}`
        }
    };

    // Legacy aliases for backward compatibility
    const legacyAliases = {
        'personale': 'personnel',
        'aziende': 'companies',
        'contatti': 'contacts',
        'contattiByAzienda': 'contactsByCompany'
    };

    // =========================================================================
    // UTILITY FUNCTIONS
    // =========================================================================

    /**
     * Escape HTML to prevent XSS attacks
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Deep merge two objects
     * @param {Object} target - Target object
     * @param {Object} source - Source object
     * @returns {Object} Merged object
     */
    function deepMerge(target, source) {
        const result = { ...target };
        for (const key in source) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                result[key] = deepMerge(target[key] || {}, source[key]);
            } else {
                result[key] = source[key];
            }
        }
        return result;
    }

    /**
     * Generate unique ID for dropdown instances
     * @returns {string} Unique ID
     */
    function generateId() {
        return 'dropdown_' + Math.random().toString(36).substring(2, 11);
    }

    /**
     * Resolve data source name (handles legacy aliases)
     * @param {string} name - Source name or legacy alias
     * @returns {string} Resolved source name
     */
    function resolveSourceName(name) {
        return legacyAliases[name] || name;
    }

    // =========================================================================
    // DATA LOADING
    // =========================================================================

    /**
     * Register a new data source
     * @param {string} name - Unique name for the data source
     * @param {Object} config - Data source configuration
     * @param {string} config.service - Service name for API call
     * @param {string} config.action - Action name for API call
     * @param {Function} config.mapResponse - Function to map API response to options
     * @param {Function} [config.cacheKey] - Function to generate cache key from params
     * @example
     * autocompleteManager.registerDataSource('projects', {
     *     service: 'commesse',
     *     action: 'getProjects',
     *     mapResponse: (data) => data.map(p => ({ value: p.id, label: p.nome })),
     *     cacheKey: (params) => `projects_${params.status || 'all'}`
     * });
     */
    function registerDataSource(name, config) {
        if (!name || typeof name !== 'string') {
            console.error('[AutocompleteManager] registerDataSource: invalid name');
            return;
        }
        if (!config || !config.service || !config.action || !config.mapResponse) {
            console.error('[AutocompleteManager] registerDataSource: missing required config (service, action, mapResponse)');
            return;
        }

        dataSources[name] = {
            service: config.service,
            action: config.action,
            mapResponse: config.mapResponse,
            cacheKey: config.cacheKey || (() => name)
        };
    }

    /**
     * Load data from a registered source with caching
     */
    function loadData(sourceName, params = {}, callback) {
        // Handle legacy 2-param call: loadData(source, callback)
        if (typeof params === 'function') {
            callback = params;
            params = {};
        }

        const resolvedName = resolveSourceName(sourceName);
        const config = dataSources[resolvedName];

        if (!config) {
            console.error('[AutocompleteManager] loadData: unknown source', sourceName);
            if (callback) callback([], new Error('Unknown data source: ' + sourceName));
            return;
        }

        // Normalize params for backward compatibility
        const normalizedParams = { ...params };
        if (normalizedParams.azienda_id && !normalizedParams.companyId) {
            normalizedParams.companyId = normalizedParams.azienda_id;
        }
        if (normalizedParams.azienda && !normalizedParams.companyId) {
            normalizedParams.companyId = normalizedParams.azienda;
        }

        const cacheKey = config.cacheKey(normalizedParams);

        // Check cache
        if (dataCache[cacheKey]) {
            if (callback) callback(dataCache[cacheKey], null);
            return;
        }

        // Check for pending request
        if (pendingRequests[cacheKey]) {
            pendingRequests[cacheKey].push(callback);
            return;
        }

        // Start new request
        pendingRequests[cacheKey] = [callback];

        // Build request params
        const requestParams = { ...normalizedParams };
        if (normalizedParams.companyId) {
            requestParams.azienda_id = normalizedParams.companyId;
            requestParams.azienda = normalizedParams.companyId;
        }

        // Use window.customFetch (Standard Project Ajax Handler)
        const fetchFn = window.customFetch || async function (service, action, params) {
            console.warn('[AutocompleteManager] window.customFetch missing, using fallback implementation');
            const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';

            try {
                const response = await fetch('ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Csrf-Token': csrfToken
                    },
                    body: JSON.stringify({ section: service, action: action, ...params, csrf_token: csrfToken })
                });
                return await response.json();
            } catch (err) {
                console.error('[AutocompleteManager] Fetch Error:', err);
                return { success: false, message: err.message };
            }
        };

        // Call fetch function (no loader: autocomplete is silent/inline)
        fetchFn(config.service, config.action, requestParams, { showLoader: false })
            .then(response => {
                let options = [];

                if (response && response.success && response.data) {
                    options = config.mapResponse(response.data);
                    dataCache[cacheKey] = options;
                } else {
                    console.warn('[AutocompleteManager] Load failed for', sourceName, response);
                }

                // Notify all waiting callbacks
                const callbacks = pendingRequests[cacheKey] || [];
                delete pendingRequests[cacheKey];
                callbacks.forEach(cb => {
                    if (cb) cb(options, null);
                });
            })
            .catch(err => {
                console.error('[AutocompleteManager] Load error:', sourceName, err);
                const callbacks = pendingRequests[cacheKey] || [];
                delete pendingRequests[cacheKey];
                callbacks.forEach(cb => {
                    if (cb) cb([], err);
                });
            });
    }

    /**
     * Clear cache for a specific source or all sources
     * @param {string} [sourceName] - Source name to clear, or omit to clear all
     * @example
     * autocompleteManager.clearCache('companies'); // Clear only companies
     * autocompleteManager.clearCache(); // Clear all cache
     */
    function clearCache(sourceName) {
        if (sourceName) {
            const resolvedName = resolveSourceName(sourceName);
            // Clear exact match
            if (dataCache[resolvedName]) {
                delete dataCache[resolvedName];
            }
            // Clear parameterized caches (e.g., contacts_company_123)
            const prefix = resolvedName + '_';
            Object.keys(dataCache).forEach(key => {
                if (key.startsWith(prefix) || key === resolvedName) {
                    delete dataCache[key];
                }
            });
        } else {
            // Clear all
            Object.keys(dataCache).forEach(key => delete dataCache[key]);
        }
    }

    // =========================================================================
    // DROPDOWN UI
    // =========================================================================

    /**
     * Show a searchable dropdown with optional free-text input
     * @param {HTMLElement} container - Container element (custom-select-box)
     * @param {Array} options - Array of options: [{value, label, ...extraFields}]
     * @param {Object} [config={}] - Configuration options
     * @param {string} [config.placeholder='Cerca...'] - Search input placeholder
     * @param {string|number} [config.initialValue] - Initially selected value
     * @param {Function} [config.onSelect] - Selection callback: (option, searchText, isFreeInput) => void
     * @param {boolean} [config.allowFreeInput=true] - Allow free-text input when no matches
     * @param {number} [config.freeInputMinChars=3] - Minimum chars for free-text option
     * @param {Function} [config.filterFn] - Custom filter function: (option, searchText) => boolean
     * @param {Function} [config.renderOption] - Custom option renderer: (option) => HTMLElement|string
     * @param {Object} [config.styles] - Custom styles (merged with defaults)
     * @example
     * showSearchableDropdown(containerEl, [
     *     { value: 1, label: 'Option 1' },
     *     { value: 2, label: 'Option 2' }
     * ], {
     *     placeholder: 'Search...',
     *     onSelect: (opt, text, isFree) => console.log('Selected:', opt || text)
     * });
     */
    function showSearchableDropdown(container, options, config = {}) {
        // Close any existing dropdowns
        document.querySelectorAll('.autocomplete-dropdown-global').forEach(dd => dd.remove());
        document.querySelectorAll('.custom-select-box.open').forEach(b => b.classList.remove('open'));

        const styles = deepMerge(defaultStyles, config.styles || {});
        const allowFreeInput = config.allowFreeInput !== false;
        const freeInputMinChars = config.freeInputMinChars || FREE_INPUT_MIN_CHARS;

        // Calculate position
        const rect = container.getBoundingClientRect();

        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown-global';
        dropdown.id = generateId();
        dropdown.style.cssText = `
            position: fixed;
            top: ${rect.bottom + 2}px;
            left: ${rect.left}px;
            min-width: ${Math.max(rect.width, styles.dropdown.minWidth)}px;
            max-width: ${styles.dropdown.maxWidth}px;
            z-index: ${styles.dropdown.zIndex};
            background: ${styles.dropdown.background};
            border: ${styles.dropdown.border};
            border-radius: ${styles.dropdown.borderRadius};
            box-shadow: ${styles.dropdown.boxShadow};
        `;
        document.body.appendChild(dropdown);
        container.classList.add('open');

        // Search input
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = config.placeholder || 'Cerca...';
        searchInput.style.cssText = `
            width: 100%;
            padding: ${styles.searchInput.padding};
            border: none;
            border-bottom: ${styles.searchInput.borderBottom};
            font-size: ${styles.searchInput.fontSize};
            box-sizing: border-box;
            outline: none;
        `;
        dropdown.appendChild(searchInput);

        // Options list
        const listContainer = document.createElement('div');
        listContainer.style.cssText = `max-height: ${styles.dropdown.maxHeight}px; overflow-y: auto;`;
        dropdown.appendChild(listContainer);

        /**
         * Default filter function
         */
        function defaultFilter(opt, searchText) {
            const search = searchText.toLowerCase();
            return (opt.label || '').toLowerCase().includes(search) ||
                (opt.value || '').toString().toLowerCase().includes(search) ||
                (opt.code && opt.code.toLowerCase().includes(search));
        }

        /**
         * Render the options list
         */
        function renderList(searchText = '') {
            listContainer.innerHTML = '';

            const filterFn = config.filterFn || defaultFilter;
            const filtered = options.filter(opt => filterFn(opt, searchText));

            filtered.forEach(opt => {
                const optionEl = document.createElement('div');
                optionEl.className = 'dropdown-option';
                optionEl.style.cssText = `
                    padding: ${styles.option.padding};
                    cursor: pointer;
                    border-bottom: ${styles.option.borderBottom};
                `;

                // Custom or default rendering
                if (config.renderOption) {
                    const rendered = config.renderOption(opt);
                    if (typeof rendered === 'string') {
                        optionEl.innerHTML = rendered;
                    } else if (rendered instanceof HTMLElement) {
                        optionEl.appendChild(rendered);
                    }
                } else {
                    optionEl.innerHTML = `<span>${escapeHtml(opt.label)}</span>`;
                }

                // Highlight if initially selected
                if (config.initialValue !== undefined && config.initialValue == opt.value) {
                    optionEl.style.background = styles.option.selectedBackground;
                }

                // Hover effects
                optionEl.addEventListener('mouseenter', () => {
                    optionEl.style.background = styles.option.hoverBackground;
                });
                optionEl.addEventListener('mouseleave', () => {
                    const isSelected = config.initialValue !== undefined && config.initialValue == opt.value;
                    optionEl.style.background = isSelected ? styles.option.selectedBackground : '';
                });

                // Selection
                optionEl.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    if (config.onSelect) config.onSelect(opt, searchInput.value, false);
                    closeDropdown();
                });

                listContainer.appendChild(optionEl);
            });

            // Free input option (when no matches and enough characters)
            if (allowFreeInput && filtered.length === 0 && searchText.trim().length >= freeInputMinChars) {
                const freeInputEl = document.createElement('div');
                freeInputEl.className = 'dropdown-option dropdown-option-free';
                freeInputEl.style.cssText = `
                    padding: ${styles.option.padding};
                    cursor: pointer;
                    color: ${styles.freeInput.color};
                    font-weight: 500;
                `;
                freeInputEl.innerHTML = `<b>+ Aggiungi:</b> ${escapeHtml(searchText)}`;

                freeInputEl.addEventListener('mouseenter', () => {
                    freeInputEl.style.background = styles.freeInput.hoverBackground;
                });
                freeInputEl.addEventListener('mouseleave', () => {
                    freeInputEl.style.background = '';
                });
                freeInputEl.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    if (config.onSelect) config.onSelect(null, searchText, true);
                    closeDropdown();
                });

                listContainer.appendChild(freeInputEl);
            }

            // No results message
            if (filtered.length === 0 && listContainer.innerHTML === '') {
                const noResults = document.createElement('div');
                noResults.style.cssText = `
                    padding: ${styles.noResults.padding};
                    color: ${styles.noResults.color};
                    text-align: ${styles.noResults.textAlign};
                `;
                noResults.textContent = 'Nessun risultato';
                listContainer.appendChild(noResults);
            }
        }

        /**
         * Close and cleanup dropdown
         */
        function closeDropdown() {
            dropdown.remove();
            container.classList.remove('open');
            document.removeEventListener('mousedown', handleClickOutside);
            window.removeEventListener('scroll', handleScroll, true);
            window.removeEventListener('resize', closeDropdown);
        }

        /**
         * Handle click outside dropdown
         */
        function handleClickOutside(e) {
            if (!dropdown.contains(e.target) && !container.contains(e.target)) {
                closeDropdown();
            }
        }

        /**
         * Handle scroll - close only if scroll is outside dropdown
         */
        function handleScroll(e) {
            // Ignora scroll interno al dropdown (lista opzioni)
            if (dropdown.contains(e.target)) {
                return;
            }
            closeDropdown();
        }

        // Event listeners
        searchInput.addEventListener('input', () => renderList(searchInput.value));
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDropdown();
            } else if (e.key === 'Enter') {
                const firstOption = listContainer.querySelector('.dropdown-option');
                if (firstOption) {
                    firstOption.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                }
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                const firstOption = listContainer.querySelector('.dropdown-option');
                if (firstOption) firstOption.focus();
            }
        });

        // Initial render
        renderList('');
        setTimeout(() => searchInput.focus(), 40);

        // Setup close handlers
        setTimeout(() => {
            document.addEventListener('mousedown', handleClickOutside);
            window.addEventListener('scroll', handleScroll, true);
            window.addEventListener('resize', closeDropdown);
        }, 10);

        return {
            close: closeDropdown,
            refresh: () => renderList(searchInput.value)
        };
    }

    // =========================================================================
    // FIELD INITIALIZATION
    // =========================================================================

    /**
     * Initialize autocomplete on a custom-select-box element
     * @param {HTMLElement} element - The custom-select-box element
     * @param {Object} config - Configuration options
     * @param {string} config.source - Data source name (e.g., 'companies', 'contacts')
     * @param {Object} [config.params={}] - Parameters for data loading
     * @param {string} [config.placeholder] - Dropdown search placeholder
     * @param {Function} [config.onSelect] - Selection callback: (option, text, isFree) => void
     * @param {Function} [config.onChange] - Change callback: (option) => void
     * @param {Object} [config.autoPopulate] - Fields to auto-populate: {fieldName: selector|element|function}
     * @param {boolean} [config.allowFreeInput=true] - Allow free-text input
     * @example
     * autocompleteManager.initField(boxElement, {
     *     source: 'companies',
     *     placeholder: 'Search company...',
     *     onSelect: (opt) => console.log('Selected:', opt),
     *     autoPopulate: {
     *         email: '#email-field',
     *         role: (box) => box.closest('tr').querySelector('.role-input')
     *     }
     * });
     */
    function initField(element, config = {}) {
        if (!element || !element.classList || !element.classList.contains('custom-select-box')) {
            console.warn('[AutocompleteManager] initField: invalid element (must be .custom-select-box)', element);
            return;
        }

        // Support legacy 'type' parameter
        const sourceName = config.source || config.type;
        if (!sourceName) {
            console.error('[AutocompleteManager] initField: missing source/type');
            return;
        }

        // Find the hidden input
        const hiddenInput = element.querySelector('input[type="hidden"]') ||
            element.querySelector('input[name*="nome"], input[name*="azienda"], input[name*="contatto"], input[name*="societa"]');

        if (!hiddenInput) {
            console.warn('[AutocompleteManager] initField: no input found in element', element);
            return;
        }

        // Load data and setup dropdown
        loadData(sourceName, config.params || {}, (options, error) => {
            if (error || options.length === 0) {
                console.warn('[AutocompleteManager] initField: no options for', sourceName, error);
                return;
            }

            // Remove previous handlers
            element.onclick = null;

            // Ensure element is clickable
            element.style.cursor = 'pointer';
            element.style.pointerEvents = 'auto';

            // Setup click handler
            element.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();

                const currentValue = hiddenInput.value || '';

                showSearchableDropdown(element, options, {
                    placeholder: config.placeholder || 'Cerca...',
                    initialValue: currentValue,
                    allowFreeInput: config.allowFreeInput !== false,
                    onSelect: (opt, searchText, isFreeInput) => {
                        if (opt) {
                            // Update placeholder text
                            const placeholder = element.querySelector('.custom-select-placeholder');
                            if (placeholder) placeholder.textContent = opt.label;

                            // Update hidden input
                            hiddenInput.value = opt.value !== undefined ? opt.value : opt.label;

                            // Auto-populate related fields
                            if (config.autoPopulate) {
                                Object.keys(config.autoPopulate).forEach(field => {
                                    const target = config.autoPopulate[field];
                                    let targetInput = null;

                                    if (typeof target === 'string') {
                                        targetInput = document.querySelector(target);
                                    } else if (typeof target === 'function') {
                                        targetInput = target(element);
                                    } else if (target && target.nodeType === 1) {
                                        targetInput = target;
                                    }

                                    if (targetInput && opt[field] !== undefined) {
                                        targetInput.value = opt[field];
                                    }
                                });
                            }

                            // Callbacks
                            if (config.onSelect) config.onSelect(opt, searchText, isFreeInput);
                            if (config.onChange) config.onChange(opt);

                            // Dispatch events for form validation etc.
                            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                        } else if (isFreeInput && searchText) {
                            // Free input handling
                            const placeholder = element.querySelector('.custom-select-placeholder');
                            if (placeholder) placeholder.textContent = searchText;
                            hiddenInput.value = searchText;

                            if (config.onSelect) config.onSelect(null, searchText, true);

                            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                });
            };
        });
    }

    /**
     * Initialize all autocomplete fields in a container
     * Supports data-autocomplete attribute for automatic type detection
     * Also sets up MutationObserver for dynamically added elements
     * @param {string} containerSelector - CSS selector for the container
     * @param {Object} [config={}] - Default configuration for all fields
     * @param {string} [config.fieldSelector='.custom-select-box[data-autocomplete]'] - Selector for fields
     * @example
     * autocompleteManager.initContainer('#my-form', {
     *     source: 'companies', // Default source
     *     placeholder: 'Search...'
     * });
     */
    function initContainer(containerSelector, config = {}) {
        const container = document.querySelector(containerSelector);
        if (!container) {
            console.warn('[AutocompleteManager] initContainer: container not found', containerSelector);
            return;
        }

        const fieldSelector = config.fieldSelector || config.boxSelector || '.custom-select-box[data-autocomplete]';

        /**
         * Initialize a single field
         */
        function initSingleField(field) {
            const fieldSource = field.getAttribute('data-autocomplete') || config.source || config.type;
            if (fieldSource) {
                initField(field, { ...config, source: fieldSource });
            }
        }

        // Initialize existing fields
        container.querySelectorAll(fieldSelector).forEach(initSingleField);

        // Watch for dynamically added fields
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        // Check if node itself is a field
                        if (node.classList && node.classList.contains('custom-select-box') && node.getAttribute('data-autocomplete')) {
                            initSingleField(node);
                        }
                        // Check children
                        if (node.querySelectorAll) {
                            node.querySelectorAll(fieldSelector).forEach(initSingleField);
                        }
                    }
                });
            });
        });

        observer.observe(container, { childList: true, subtree: true });

        // Return observer for cleanup if needed
        return observer;
    }

    /**
     * Link two fields in a master-detail relationship
     * When master field changes, detail field is updated with filtered data
     * @param {HTMLElement} masterElement - Master field element
     * @param {HTMLElement} detailElement - Detail field element
     * @param {Object} [config={}] - Configuration options
     * @param {string} [config.masterSource='companies'] - Data source for master
     * @param {string} [config.detailSource='contactsByCompany'] - Data source for detail
     * @param {Function} [config.getDetailParams] - Get params for detail from master selection: (opt) => params
     * @param {boolean} [config.resetOnChange=true] - Reset detail when master changes
     * @param {string} [config.masterPlaceholder] - Master field placeholder
     * @param {string} [config.detailPlaceholder] - Detail field placeholder
     * @param {Function} [config.onMasterSelect] - Callback when master selected
     * @param {Function} [config.onDetailSelect] - Callback when detail selected
     * @param {Object} [config.detailAutoPopulate] - Auto-populate config for detail
     * @example
     * autocompleteManager.linkFields(companyBox, contactBox, {
     *     masterSource: 'companies',
     *     detailSource: 'contactsByCompany',
     *     getDetailParams: (companyOpt) => ({ companyId: companyOpt.value }),
     *     detailAutoPopulate: { email: '.email-input' }
     * });
     */
    function linkFields(masterElement, detailElement, config = {}) {
        const masterSource = config.masterSource || config.sourceType || 'companies';
        const detailSource = config.detailSource || config.targetType || 'contactsByCompany';
        const getDetailParams = config.getDetailParams || config.getSourceId || ((opt) => ({
            companyId: opt.value || opt.id,
            azienda_id: opt.value || opt.id,
            azienda: opt.value || opt.id
        }));
        const resetOnChange = config.resetOnChange !== false;

        // Initialize master field
        initField(masterElement, {
            source: masterSource,
            placeholder: config.masterPlaceholder || config.sourcePlaceholder || 'Cerca...',
            onSelect: (opt) => {
                if (!opt) return;

                const detailParams = getDetailParams(opt);

                // Reset detail field if configured
                if (resetOnChange && detailElement) {
                    const detailPlaceholder = detailElement.querySelector('.custom-select-placeholder');
                    const detailInput = detailElement.querySelector('input[type="hidden"]');
                    if (detailPlaceholder) {
                        detailPlaceholder.textContent = config.detailPlaceholder || config.targetPlaceholder || 'Seleziona...';
                    }
                    if (detailInput) detailInput.value = '';
                }

                // Reinitialize detail field with new params
                if (detailElement && detailParams) {
                    initField(detailElement, {
                        source: detailSource,
                        params: detailParams,
                        placeholder: config.detailPlaceholder || config.targetPlaceholder || 'Cerca...',
                        onSelect: config.onDetailSelect || config.onTargetSelect,
                        autoPopulate: config.detailAutoPopulate || config.targetAutoFields
                    });
                }

                // Master selection callback
                if (config.onMasterSelect || config.onSourceSelect) {
                    (config.onMasterSelect || config.onSourceSelect)(opt, detailParams);
                }
            }
        });
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY - Legacy API aliases
    // =========================================================================

    /**
     * Legacy: load() - Use loadData() instead
     * @deprecated Use loadData() instead
     */
    function legacyLoad(type, params, callback) {
        // Handle 2-param signature: load(type, callback)
        if (typeof params === 'function') {
            callback = params;
            params = {};
        }
        loadData(type, params, callback);
    }

    /**
     * Legacy: init() - Use initField() instead
     * @deprecated Use initField() instead
     */
    function legacyInit(box, config = {}) {
        initField(box, config);
    }

    /**
     * Legacy: setup() - Use initContainer() instead
     * @deprecated Use initContainer() instead
     */
    function legacySetup(containerSelector, config = {}) {
        return initContainer(containerSelector, config);
    }

    /**
     * Legacy: setupDependent() - Use linkFields() instead
     * @deprecated Use linkFields() instead
     */
    function legacySetupDependent(sourceBox, targetBox, config = {}) {
        linkFields(sourceBox, targetBox, config);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    const publicAPI = {
        // New API (v2.0)
        registerDataSource,
        loadData,
        clearCache,
        createDropdown: showSearchableDropdown,
        initField,
        initContainer,
        linkFields,

        // Legacy API (for backward compatibility)
        load: legacyLoad,
        init: legacyInit,
        setup: legacySetup,
        setupDependent: legacySetupDependent,

        // Expose internals for debugging/extension
        _cache: dataCache,
        _sources: dataSources,
        _styles: defaultStyles,

        // Legacy compatibility
        cache: dataCache,
        types: dataSources
    };

    // =========================================================================
    // GLOBAL EXPORTS
    // =========================================================================

    if (typeof window !== 'undefined') {
        // Main API
        window.autocompleteManager = publicAPI;

        // Utility functions
        if (!window.escapeHtml) {
            window.escapeHtml = escapeHtml;
        }

        // Dropdown function (both new and legacy names)
        window.showSearchableDropdown = showSearchableDropdown;
        if (!window.showCustomDropdownInputLibero) {
            window.showCustomDropdownInputLibero = showSearchableDropdown;
        }
    }

})();
