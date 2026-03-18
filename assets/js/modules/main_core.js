// === DISCIPLINE COMMESSE (badge globali) ===
window.DISCIPLINE_COMMESSE = [
    { code: "GEN", label: "Generale", color: "#a0a0a0" },
    { code: "ARC", label: "Architettura", color: "#c27ba0" },
    { code: "CIV", label: "Civile", color: "#88aad4" },
    { code: "STR", label: "Strutture", color: "#c88f29" },
    { code: "ELE", label: "Elettrico", color: "#6fc48a" },
    { code: "MEC", label: "Meccanico", color: "#6eb2b9" },
    { code: "VVF", label: "Antincendio", color: "#e16c53" },
    { code: "SIC", label: "Sicurezza", color: "#af4a68" }
];

// === HTML ESCAPE (protezione XSS per innerHTML)
window.escapeHtml = function (text) {
    if (typeof text !== 'string') return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};



window.safeUrl = function (url) {
    return url && /^https?:\/\//i.test(url) ? url : '#';
};

window.capitalize = function (str) {
    if (!str || typeof str !== 'string') return '';
    str = str.toLowerCase();
    return str.charAt(0).toUpperCase() + str.slice(1);
};

window.generateInitialsAvatar = function(name, size = 200) {
    let cleanName = (name || '').trim();
    if (!cleanName || cleanName.toLowerCase() === 'sconosciuto') {
        return '/assets/images/default_profile.png';
    }
    const words = cleanName.split(/[\s\-]+/);
    let initials = words.slice(0, 2).map(w => w.charAt(0)).join('').toUpperCase();
    if (!initials) initials = '?';
    
    const colors = [
        '#f56a00', '#7265e6', '#ffbf00', '#00a2ae', '#1890ff', 
        '#d41c1c', '#13c2c2', '#eb2f96', '#2f54eb', '#a0d911', 
        '#52c41a', '#faad14', '#f5222d', '#7cb305', '#1677ff', 
        '#531dab', '#c41d7f', '#d4380d', '#08979c', '#0958d9'
    ];
    let sum = 0;
    for (let i = 0; i < initials.length; i++) {
        sum += initials.charCodeAt(i) * (i + 1);
    }
    const bgColor = colors[sum % colors.length];
    
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}">
        <rect width="${size}" height="${size}" fill="${bgColor}"/>
        <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Arial, sans-serif" font-weight="bold" font-size="${size * 0.4}">${window.escapeHtml(initials)}</text>
    </svg>`;
    
    return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg)));
};

/**
 * Funzione globale riutilizzabile per comprimere immagini lato client
 * Ridimensiona e converte a WebP (o altro formato) usando Canvas API
 * 
 * @param {File} file File immagine da comprimere
 * @param {Object} options Opzioni di compressione:
 *   - maxWidth (number, default 900): larghezza massima
 *   - maxHeight (number, default 900): altezza massima
 *   - quality (number 0-1, default 0.78): qualità per WebP
 *   - outputType (string, default 'image/webp'): tipo MIME output
 *   - keepName (boolean, default false): mantiene nome originale
 *   - outputNameSuffix (string, default '_opt'): suffisso nome se keepName=false
 * @returns {Promise<File>} Promise che risolve con File compresso o originale se fallisce
 */
window.compressImageFile = async function (file, options = {}) {
    // Se non è un'immagine, ritorna immutato
    if (!file || !file.type || !file.type.startsWith('image/')) {
        return file;
    }

    // Default options
    const maxWidth = options.maxWidth || 900;
    const maxHeight = options.maxHeight || 900;
    const quality = typeof options.quality === 'number' ? Math.max(0, Math.min(1, options.quality)) : 0.78;
    const outputType = options.outputType || 'image/webp';
    const keepName = options.keepName || false;
    const outputNameSuffix = options.outputNameSuffix || '_opt';

    try {
        // Carica immagine
        let image;
        if (typeof createImageBitmap !== 'undefined') {
            image = await createImageBitmap(file);
        } else {
            // Fallback per browser più vecchi
            image = await new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = URL.createObjectURL(file);
            });
        }

        // Calcola nuove dimensioni mantenendo aspect ratio, no upscaling
        let newWidth = image.width;
        let newHeight = image.height;

        if (image.width > maxWidth || image.height > maxHeight) {
            const ratio = Math.min(maxWidth / image.width, maxHeight / image.height);
            newWidth = Math.round(image.width * ratio);
            newHeight = Math.round(image.height * ratio);
        }

        // Crea canvas
        const canvas = document.createElement('canvas');
        canvas.width = newWidth;
        canvas.height = newHeight;
        const ctx = canvas.getContext('2d');

        if (!ctx) {
            throw new Error('Canvas context non disponibile');
        }

        // Disegna immagine ridimensionata
        ctx.drawImage(image, 0, 0, newWidth, newHeight);

        // Converti a Blob
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('Conversione canvas fallita'));
                }
            }, outputType, quality);
        });

        // Prepara nome file
        let fileName;
        if (keepName) {
            const baseName = file.name.replace(/\.[^/.]+$/, '');
            const ext = outputType === 'image/webp' ? '.webp' :
                outputType === 'image/jpeg' ? '.jpg' :
                    outputType === 'image/png' ? '.png' : '.webp';
            fileName = baseName + ext;
        } else {
            const baseName = file.name.replace(/\.[^/.]+$/, '');
            const ext = outputType === 'image/webp' ? '.webp' :
                outputType === 'image/jpeg' ? '.jpg' :
                    outputType === 'image/png' ? '.png' : '.webp';
            fileName = baseName + outputNameSuffix + ext;
        }

        // Crea nuovo File
        const compressedFile = new File([blob], fileName, {
            type: outputType,
            lastModified: Date.now()
        });

        // Cleanup se usato URL.createObjectURL
        if (image.src && image.src.startsWith('blob:')) {
            URL.revokeObjectURL(image.src);
        }

        return compressedFile;

    } catch (error) {
        // Fail-safe: ritorna file originale se compressione fallisce
        console.warn('[compressImageFile] Errore durante compressione, ritorno file originale:', error);
        return file;
    }
};

// === UTILS GLOBALI ===
window.utils = window.utils || {};

(function registerParseDateToISO(global) {
    // Mappa mesi IT (anche abbreviazioni comuni)
    const MONTHS = Object.freeze({
        'gennaio': '01', 'gen': '01',
        'febbraio': '02', 'feb': '02',
        'marzo': '03', 'mar': '03',
        'aprile': '04', 'apr': '04',
        'maggio': '05', 'mag': '05',
        'giugno': '06', 'giu': '06',
        'luglio': '07', 'lug': '07',
        'agosto': '08', 'ago': '08',
        'settembre': '09', 'set': '09', 'sett': '09',
        'ottobre': '10', 'ott': '10',
        'novembre': '11', 'nov': '11',
        'dicembre': '12', 'dic': '12'
    });

    function parseDateToISO(input) {
        if (!input) return '';
        const s = String(input).trim()
            .replace(/[.,]/g, ' ')
            .replace(/\s+/g, ' ')
            .toLowerCase();

        // yyyy-mm-dd / yyyy/mm/dd / yyyy.mm.dd (tollerante)
        let m = s.match(/(\d{4})[-/\.](\d{1,2})[-/\.](\d{1,2})/);
        if (m) {
            const yyyy = m[1], mm = m[2].padStart(2, '0'), dd = m[3].padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        }

        // dd/mm/yyyy o dd-mm-yyyy o dd.mm.yyyy (anche yy)
        m = s.match(/(\d{1,2})[-/\.](\d{1,2})[-/\.](\d{2,4})/);
        if (m) {
            let dd = m[1].padStart(2, '0');
            let mm = m[2].padStart(2, '0');
            let yyyy = m[3];
            if (yyyy.length === 2) yyyy = (parseInt(yyyy, 10) >= 70 ? '19' : '20') + yyyy;
            return `${yyyy}-${mm}-${dd}`;
        }

        // "9 giugno 2025" / "09 giu 25"
        m = s.match(/(\d{1,2})\s+([a-zà]+)\s+(\d{2,4})/i);
        if (m) {
            const dd = String(m[1]).padStart(2, '0');
            const mm = MONTHS[m[2]] || '';
            if (mm) {
                let yyyy = m[3];
                if (yyyy.length === 2) yyyy = (parseInt(yyyy, 10) >= 70 ? '19' : '20') + yyyy;
                return `${yyyy}-${mm}-${dd}`;
            }
        }

        // Fallback prudente: Date.parse (può fallire su alcune stringhe locali)
        const ts = Date.parse(input);
        if (!isNaN(ts)) {
            const d = new Date(ts);
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        }
        return '';
    }

    // Esponi come sola lettura per evitare manomissioni accidentali
    Object.defineProperty(global, 'parseDateToISO', {
        value: parseDateToISO,
        writable: false,
        configurable: false,
        enumerable: true
    });
})(window.utils || (window.utils = {}));

// === Calendar Provider Registry (riutilizzabile ovunque) ===
(function initCalendarRegistry() {
    // Evita doppie init
    if (window.calendarProviders && window.registerCalendarProvider && window.pickCalendarProviderForPage) return;

    // Mappa pagina -> provider (fn async che ritorna Array<Event>)
    window.calendarProviders = Object.create(null);

    // Helper robusto per capire la pagina corrente
    function getCurrentPage() {
        try {
            const url = new URL(window.location.href);
            // es: https://.../index.php?section=commerciale&page=estrazione_bandi
            const page = url.searchParams.get('page');
            if (page) return page;
            // fallback sul path (es: /estrazione_bandi.php)
            const last = (window.location.pathname.split('/').pop() || '').replace('.php', '');
            return last || '';
        } catch { return ''; }
    }

    // Espone un picker: sceglie il provider registrato per la pagina
    window.pickCalendarProviderForPage = function pickCalendarProviderForPage() {
        const page = getCurrentPage();
        return window.calendarProviders[page] || null;
    };

    // API per registrare provider della pagina (es. 'gare', 'gestione_segnalazioni', ecc.)
    // Se la pagina corrente coincide, esponi SUBITO calendarDataProvider ed emetti un evento.
    window.registerCalendarProvider = function registerCalendarProvider(page, fn) {
        if (!page || typeof fn !== 'function') return;
        const key = String(page);
        window.calendarProviders[key] = fn;

        // Se sono già sulla pagina per cui sto registrando, set immediato (niente race)
        if (getCurrentPage() === key) {
            window.calendarDataProvider = fn; // compat con calendar-view.js
            try {
                window.dispatchEvent(new CustomEvent('calendar-provider-ready', { detail: { page: key } }));
            } catch { }
        }
    };

    // Fallback su DOMContentLoaded / navigazioni history
    function ensureProvider() {
        const provider = window.pickCalendarProviderForPage();
        if (typeof provider === 'function') {
            window.calendarDataProvider = provider;
            try {
                window.dispatchEvent(new CustomEvent('calendar-provider-ready', { detail: { page: getCurrentPage() } }));
            } catch { }
        }
    }

    document.addEventListener('DOMContentLoaded', ensureProvider);
    window.addEventListener('pageshow', ensureProvider);
    window.addEventListener('popstate', ensureProvider);
})();


// === TOAST
window.showToast = function (message, type = "success") {
    let container = document.getElementById("toast-container");

    if (!container) {
        container = document.createElement("div");
        container.id = "toast-container";
        document.body.appendChild(container);
    }

    if (!message || String(message).trim().length === 0) return;

    const toast = document.createElement("div");
    toast.className = "toast";

    // Wrapper per il contenuto (non tocchiamo la struttura base del toast)
    const content = document.createElement("div");
    content.style.cssText = "display: flex; align-items: center; gap: 10px; width: 100%;";

    // Messaggio
    const messageSpan = document.createElement("span");
    messageSpan.textContent = String(message);
    messageSpan.style.flex = "1";
    content.appendChild(messageSpan);

    // Pulsante chiusura
    const closeBtn = document.createElement("button");
    closeBtn.innerHTML = "&times;";
    closeBtn.className = "toast-close-btn";
    closeBtn.style.cssText = `
        background: transparent;
        border: none;
        color: #fff;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        padding: 0;
        width: 28px;
        height: 28px;
        line-height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
        flex-shrink: 0;
    `;
    closeBtn.onmouseover = () => closeBtn.style.background = "rgba(255,255,255,0.2)";
    closeBtn.onmouseout = () => closeBtn.style.background = "transparent";
    closeBtn.onclick = () => {
        toast.style.animation = "toastSlideOut 0.3s ease-out forwards";
        setTimeout(() => toast.remove(), 300);
    };
    content.appendChild(closeBtn);

    toast.appendChild(content);

    // Colori in base al tipo
    if (type === "error") toast.style.backgroundColor = "#c0392b";
    if (type === "info") toast.style.backgroundColor = "#2980b9";

    container.appendChild(toast);

    // Auto-rimozione dopo 20 secondi
    setTimeout(() => {
        toast.style.animation = "toastSlideOut 0.3s ease-out forwards";
        setTimeout(() => toast.remove(), 300);
    }, 20000);
};

// === MODAL
window.toggleModal = function (modalId, action = 'toggle') {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    switch (action) {
        case 'open':
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            break;
        case 'close':
            modal.classList.add('hidden');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            break;
        case 'toggle':
            if (modal.classList.contains('hidden') || modal.style.display === 'none') {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
            } else {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }
            break;
    }
};

// Chiudi modale cliccando fuori
document.addEventListener('click', function (event) {
    document.querySelectorAll('.modal').forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});

document.addEventListener("DOMContentLoaded", function () {
    // Chiusura modali con classe .close e attributo data-close-target
    document.body.addEventListener("click", function (e) {
        if (e.target.classList.contains("close")) {
            const modal = e.target.closest(".modal");
            if (modal) modal.style.display = "none";
        }
    });
});

// === CONTEXT MENU
(function initContextMenuContainer() {
    if (!document.getElementById("custom-context-menu")) {
        const menu = document.createElement("ul");
        menu.id = "custom-context-menu";
        menu.className = "custom-context-menu hidden";
        document.body.appendChild(menu);
    }
})();

document.addEventListener("click", () => {
    document.getElementById("custom-context-menu")?.classList.add("hidden");
});

window.registerContextMenu = function (selector, options = []) {
    const menu = document.getElementById("custom-context-menu");

    // Event delegation: intercetta contextmenu su document e verifica il selector
    // Supporta sia elementi statici che dinamici (creati dopo la registrazione)
    document.addEventListener("contextmenu", (e) => {
        const el = e.target.closest(selector);
        if (!el) return;

        e.preventDefault();
        menu.innerHTML = "";

        options.forEach(opt => {
            if (typeof opt.visible === "function" && !opt.visible(el)) return;

            const li = document.createElement("li");
            li.textContent = opt.label;
            li.addEventListener("click", () => {
                opt.action(el);
                menu.classList.add("hidden");
            });
            menu.appendChild(li);
        });

        if (!menu.children.length) {
            menu.classList.add("hidden");
            return;
        }

        menu.style.top = `${e.pageY}px`;
        menu.style.left = `${e.pageX}px`;
        menu.classList.remove("hidden");
    });
};

// === CONFIRM MODAL
window.showConfirm = function (message, onConfirm, options) {
    options = options || {};
    const displayMsg = options.allowHtml ? message : escapeHtml(String(message || ''));

    document.getElementById("global-confirm-modal")?.remove();

    const overlay = document.createElement("div");
    overlay.id = "global-confirm-modal";
    overlay.className = "custom-confirm-overlay";

    overlay.innerHTML = `
        <div class="custom-confirm-box">
            <p>${displayMsg}</p>
            <div class="custom-confirm-buttons">
                <button class="button confirm-ok">Conferma</button>
                <button class="button confirm-cancel">Annulla</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelector(".confirm-ok").addEventListener("click", () => {
        overlay.remove();
        if (typeof onConfirm === "function") onConfirm();
    });

    overlay.querySelector(".confirm-cancel").addEventListener("click", () => {
        overlay.remove();
    });
};

// === PROMPT MODAL (input con testo)
window.showPrompt = function (message, defaultValue, onConfirm, options) {
    options = options || {};
    const displayMsg = options.allowHtml ? message : escapeHtml(String(message || ''));

    document.getElementById("global-prompt-modal")?.remove();

    const overlay = document.createElement("div");
    overlay.id = "global-prompt-modal";
    overlay.className = "custom-confirm-overlay";

    overlay.innerHTML = `
        <div class="custom-confirm-box">
            <p>${displayMsg}</p>
            <input type="text" class="prompt-input" style="width: 100%; padding: 8px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;">
            <div class="custom-confirm-buttons">
                <button class="button confirm-ok">Conferma</button>
                <button class="button confirm-cancel">Annulla</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const input = overlay.querySelector(".prompt-input");
    input.value = defaultValue || '';
    input.focus();
    input.select();

    // ENTER su input conferma (gestito localmente per avere accesso al valore)
    // ESC è gestito dal KeyboardManager globale
    input.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
            overlay.remove();
            if (typeof onConfirm === "function") onConfirm(input.value);
        }
    });

    overlay.querySelector(".confirm-ok").addEventListener("click", () => {
        overlay.remove();
        if (typeof onConfirm === "function") onConfirm(input.value);
    });

    overlay.querySelector(".confirm-cancel").addEventListener("click", () => {
        overlay.remove();
    });
};

// === RENAME MODAL (titolo + descrizione)
window.showRenameModal = function (titolo, descrizione, onConfirm, options) {
    options = options || {};
    document.getElementById("global-rename-modal")?.remove();

    const overlay = document.createElement("div");
    overlay.id = "global-rename-modal";
    overlay.className = "custom-confirm-overlay";

    overlay.innerHTML = `
        <div class="custom-confirm-box" style="min-width: 350px;">
            <h3 style="margin: 0 0 15px 0; font-size: 16px;">Modifica documento</h3>
            <div style="margin-bottom: 12px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">Titolo</label>
                <input type="text" class="rename-titolo" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 4px; font-weight: 500;">Descrizione</label>
                <textarea class="rename-descrizione" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; resize: vertical; box-sizing: border-box;"></textarea>
            </div>
            <div class="custom-confirm-buttons">
                <button class="button confirm-ok">Salva</button>
                <button class="button confirm-cancel">Annulla</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const inputTitolo = overlay.querySelector(".rename-titolo");
    const inputDescrizione = overlay.querySelector(".rename-descrizione");

    inputTitolo.value = titolo || '';
    inputDescrizione.value = descrizione || '';

    inputTitolo.focus();
    inputTitolo.select();

    // ESC è gestito dal KeyboardManager globale (custom-confirm-overlay)

    overlay.querySelector(".confirm-ok").addEventListener("click", () => {
        overlay.remove();
        if (typeof onConfirm === "function") onConfirm(inputTitolo.value, inputDescrizione.value);
    });

    overlay.querySelector(".confirm-cancel").addEventListener("click", () => {
        overlay.remove();
    });
};

// === SHOW PERMISSION MODAL
window.showPermissionModal = function (message, onHome, onStay) {
    document.getElementById("global-confirm-modal")?.remove();

    const overlay = document.createElement("div");
    overlay.id = "global-confirm-modal";
    overlay.className = "custom-confirm-overlay";

    overlay.innerHTML = `
        <div class="custom-confirm-box">
            <p>${message}</p>
            <div class="custom-confirm-buttons">
                <button class="button confirm-ok">Torna alla Home</button>
                <button class="button confirm-cancel">Resta Qui</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelector(".confirm-ok").addEventListener("click", () => {
        overlay.remove();
        if (typeof onHome === "function") onHome();
    });

    overlay.querySelector(".confirm-cancel").addEventListener("click", () => {
        overlay.remove();
        if (typeof onStay === "function") onStay();
    });
};

// === TOOLTIP ===
(function () {
    let tooltipEl = null;
    let tooltipTimeout = null;

    function showTooltip(target, content) {
        if (tooltipTimeout) clearTimeout(tooltipTimeout);

        if (!tooltipEl) {
            tooltipEl = document.createElement("div");
            tooltipEl.className = "tooltip";
            document.body.appendChild(tooltipEl);
        }

        tooltipEl.textContent = content;

        // Posizionamento
        const rect = target.getBoundingClientRect();
        const scrollY = window.scrollY || document.documentElement.scrollTop;
        const scrollX = window.scrollX || document.documentElement.scrollLeft;
        let left = rect.left + scrollX + rect.width / 2;
        let top = rect.bottom + scrollY + 0;

        tooltipTimeout = setTimeout(() => {
            tooltipEl.style.left = (left - tooltipEl.offsetWidth / 2) + "px";
            tooltipEl.style.top = top + "px";
            tooltipEl.classList.add("visible");
        }, 500);
    }

    function hideTooltip() {
        if (tooltipTimeout) clearTimeout(tooltipTimeout);
        if (tooltipEl) tooltipEl.classList.remove("visible");
    }

    document.addEventListener("mouseover", function (e) {
        const el = e.target.closest("[data-tooltip]");
        if (!el) return;
        showTooltip(el, el.getAttribute("data-tooltip"));
    });

    document.addEventListener("mouseout", function (e) {
        const el = e.target.closest("[data-tooltip]");
        if (!el) return;
        hideTooltip();
    });

    document.addEventListener("click", function (e) {
        const el = e.target.closest("[data-tooltip]");
        if (!el) return;
        showTooltip(el, el.getAttribute("data-tooltip"));
        setTimeout(hideTooltip, 1700);
    });

    window.showTooltip = showTooltip;
    window.hideTooltip = hideTooltip;
})();


/*---TABELLA RIUTILIZZABILE---*/
// === TABLE UTILITIES (shared by Filters and Pagination) ===
window.getTableCellValue = function (cell, colIdx, tableId) {
    if (!cell) return '';
    const select = cell.querySelector('select');
    if (select) {
        return select.options[select.selectedIndex]?.textContent?.toLowerCase() || '';
    }
    // Caso speciale per colonna "Ruolo" nella tabella user-role-table
    if (tableId === 'user-role-table' && colIdx === 3) { // Colonna Ruolo (indice 3)
        const checkedCheckboxes = cell.querySelectorAll('.user-roles-container input[type="checkbox"]:checked');
        return Array.from(checkedCheckboxes).map(checkbox => {
            const span = checkbox.parentElement.querySelector('span');
            return span ? span.textContent.trim().toLowerCase() : '';
        });
    }
    return cell.textContent?.toLowerCase() || '';
};

window.rowMatchesFilters = function (row, filters, mapping) {
    if (!filters || filters.length === 0) return true;
    const tableId = row.closest('table')?.id;

    for (let i = 0; i < filters.length; i++) {
        const query = filters[i];
        if (!query) continue;

        const colIdx = mapping[i];
        if (colIdx === undefined || colIdx === null) continue;

        const cell = row.cells[colIdx];
        const cellValue = window.getTableCellValue(cell, colIdx, tableId);

        if (Array.isArray(cellValue)) {
            // Caso multi-valore (es. ruoli)
            if (!cellValue.some(item => item.includes(query))) return false;
        } else {
            if (!cellValue.includes(query)) return false;
        }
    }
    return true;
};

window.initTableFilters = function initTableFilters(tableId, tableSource = null, columns = null, pageSize = 25, defaultSort = '', defaultDir = 'asc') {
    const table = document.getElementById(tableId);
    if (!table) return;

    // Se non specificatamente indicato come remote tramite attributo o parametro,
    // evitiamo di eseguire il caricamento dati AJAX se è una tabella statica
    const isRemote = tableSource !== null || table.getAttribute('data-remote') === '1';

    // Override pageSize da attributo se presente
    const attrPageSize = table.getAttribute('data-page-size');
    if (attrPageSize) pageSize = parseInt(attrPageSize, 10);

    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;

    // Crea wrapper per scroll orizzontale se non esiste già
    if (!table.parentElement.classList.contains('table-filterable-wrapper') &&
        !table.closest('.table-filterable-wrapper')) {
        const wrapper = document.createElement('div');
        wrapper.className = 'table-filterable-wrapper';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    }

    // Applica le larghezze salvate IMMEDIATAMENTE per evitare il flash visivo
    // Questo deve essere fatto prima di qualsiasi altra modifica alla tabella
    // La tabella è nascosta inizialmente con CSS e verrà mostrata quando è pronta
    // Logic for loading widths is now handled centrally by assets/js/modules/table_resize.js
    // to ensure consistency and correct key generation.

    // ========== MODALITÀ REMOTE (SERVER-SIDE) ==========
    if (isRemote) {
        initRemoteTable(table, thead, tbody);
        return;
    }


    // ========== MODALITÀ LOCALE (CLIENT-SIDE) - CODICE ESISTENTE ==========

    // Trova la riga header effettiva, skippando righe decorative (es. th-groups)
    function getHeaderRow(thead) {
        for (let i = 0; i < thead.rows.length; i++) {
            if (!thead.rows[i].classList.contains('th-groups') &&
                !thead.rows[i].classList.contains('filter-row')) {
                return thead.rows[i];
            }
        }
        return thead.rows[0]; // fallback
    }

    const headerRow = getHeaderRow(thead);

    for (let i = 0; i < headerRow.cells.length - 1; i++) {
        headerRow.cells[i].classList.add('th-bordered-right');
    }

    let colToInput = [];
    let inputToCol = [];

    if (!thead.querySelector('.filter-row')) {
        const headerRowIndex = Array.from(thead.rows).indexOf(headerRow);
        const filterRow = thead.insertRow(headerRowIndex + 1);
        filterRow.className = 'filter-row';

        // Mappa e inputIdx
        let inputIdx = 0;
        for (let i = 0; i < headerRow.cells.length; i++) {
            const th = document.createElement('th');

            if (i < headerRow.cells.length - 1) th.classList.add('th-bordered-right');

            const cell = headerRow.cells[i];
            const isAzioni = cell.classList.contains('azioni-colonna') || cell.innerText.trim().toLowerCase() === 'azioni';

            if (!isAzioni) {
                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Cerca...';
                input.className = 'table-col-search';
                input.oninput = function () {
                    filterTable();
                };
                th.appendChild(input);
                colToInput[i] = inputIdx;
                inputToCol[inputIdx] = i;
                inputIdx++;
            } else {
                colToInput[i] = null;
            }
            filterRow.appendChild(th);
        }

        for (let i = 0; i < headerRow.cells.length; i++) {
            const cell = headerRow.cells[i];
            const isAzioni = cell.classList.contains('azioni-colonna') || cell.innerText.trim().toLowerCase() === 'azioni';
            cell.querySelector('.filter-icon')?.remove();

            const icon = document.createElement('span');
            icon.className = 'filter-icon';
            icon.innerHTML = '<img src="/assets/icons/filter.png" style="width:15px;height:15px;vertical-align:middle;">';

            if (!isAzioni) {
                const inputIdx = colToInput[i];
                icon.onclick = function (e) {
                    e.stopPropagation();
                    const inputs = thead.querySelectorAll('.table-col-search');
                    const input = inputs[inputIdx];
                    if (input && input.value.trim()) {
                        input.value = '';
                        icon.classList.remove('filter-active');
                        filterTable();
                    } else {
                        showDropdown(i, inputIdx, icon);
                    }
                };
            } else {
                icon.classList.add('hidden');
            }
            cell.appendChild(icon);
        }
    } else {
        let inputIdx = 0;
        for (let i = 0; i < headerRow.cells.length; i++) {
            const cell = headerRow.cells[i];
            const isAzioni = cell.classList.contains('azioni-colonna') || cell.innerText.trim().toLowerCase() === 'azioni';
            if (!isAzioni) {
                colToInput[i] = inputIdx;
                inputToCol[inputIdx] = i;
                inputIdx++;
            } else {
                colToInput[i] = null;
            }
        }
    }

    // dropdown container
    let dropdown = document.getElementById('column-filter-dropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'column-filter-dropdown';
        dropdown.style.position = 'absolute';
        dropdown.style.display = 'none';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid #ccc';
        dropdown.style.zIndex = '1000';
        document.body.appendChild(dropdown);
    }

    // === Filtro Principale ===
    function filterTable() {
        const trs = tbody.querySelectorAll('tr');
        const theadInputs = thead.querySelectorAll('.table-col-search');

        // Raccolta valori filtri e aggiornamento icone
        const filterValues = [];
        theadInputs.forEach((input, idx) => {
            const val = input.value.trim().toLowerCase();
            filterValues[idx] = val;

            const colIdx = inputToCol[idx];
            const icon = headerRow.cells[colIdx].querySelector('.filter-icon');
            if (icon) {
                if (val) icon.classList.add('filter-active');
                else icon.classList.remove('filter-active');
            }
        });

        // Se c'è paginazione attiva, lascia che updateView() gestisca tutto
        if (table._paginationUpdateView) {
            setTimeout(() => {
                table._paginationUpdateView();
            }, 50);
        } else {
            // Applicazione locale filtri
            trs.forEach(tr => {
                const show = window.rowMatchesFilters(tr, filterValues, inputToCol);
                tr.style.display = show ? '' : 'none';
            });
        }
    }

    // === Dropdown con voci uniche (allineato!) ===
    function showDropdown(colIdx, inputIdx, icon) {
        const vals = new Set();
        tbody.querySelectorAll('tr').forEach(tr => {
            if (tr.cells[colIdx]) {
                const cell = tr.cells[colIdx];
                const select = cell.querySelector('select');
                if (select) {
                    Array.from(select.options).forEach(opt => {
                        if (opt.value && opt.text) vals.add(opt.text.trim());
                    });
                } else {
                    // Caso speciale per colonna "Ruolo" nella tabella user-role-table
                    if (table.id === 'user-role-table' && colIdx === 3) { // Colonna Ruolo (indice 3)
                        const checkedCheckboxes = cell.querySelectorAll('.user-roles-container input[type="checkbox"]:checked');
                        checkedCheckboxes.forEach(checkbox => {
                            const span = checkbox.parentElement.querySelector('span');
                            if (span) {
                                const roleName = span.textContent.trim();
                                if (roleName) vals.add(roleName);
                            }
                        });
                    } else {
                        vals.add(cell.textContent.trim());
                    }
                }
            }
        });

        dropdown.innerHTML = '';
        vals.forEach(val => {
            const item = document.createElement('div');
            item.textContent = val;
            item.className = 'dropdown-filter-item';
            item.style.padding = '5px 10px';
            item.style.cursor = 'pointer';
            item.onclick = function () {
                const inputs = thead.querySelectorAll('.table-col-search');
                const input = inputs[inputIdx];
                if (input) {
                    input.value = val;
                    filterTable();
                    dropdown.style.display = 'none';
                }
            };
            dropdown.appendChild(item);
        });
        // Reset voce filtro
        const reset = document.createElement('div');
        reset.textContent = 'Mostra tutti';
        reset.className = 'dropdown-filter-item';
        reset.style.padding = '5px 10px';
        reset.style.background = '#eee';
        reset.onclick = function () {
            const inputs = thead.querySelectorAll('.table-col-search');
            const input = inputs[inputIdx];
            if (input) {
                input.value = '';
                filterTable();
                dropdown.style.display = 'none';
            }
        };
        dropdown.insertBefore(reset, dropdown.firstChild);

        // POSIZIONA
        // POSIZIONA (allinea il dropdown ESATTAMENTE sopra la colonna)
        const thCell = icon.closest('th');
        const rect = thCell.getBoundingClientRect();
        const scrollY = window.scrollY || document.documentElement.scrollTop;
        const scrollX = window.scrollX || document.documentElement.scrollLeft;

        dropdown.style.left = (rect.left + scrollX) + 'px';
        dropdown.style.top = (rect.bottom + scrollY + 2) + 'px';
        dropdown.style.width = rect.width + 'px';
        dropdown.style.minWidth = '110px'; // imposta una larghezza minima per sicurezza
        dropdown.style.maxWidth = '350px'; // facoltativo, se vuoi limitare la larghezza massima
        dropdown.style.display = 'block';

    }

    // Chiudi dropdown su click fuori
    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
}

/**
 * Inizializza tabella in modalità REMOTE (server-side)
 * Gestisce filtri, paginazione, ordinamento e facets completamente lato server
 */
function initRemoteTable(table, thead, tbody) {
    // Leggi configurazione da data-attributes
    const tableSource = table.getAttribute('data-source') || '';
    const columnsJson = table.getAttribute('data-columns') || '[]';
    const defaultSort = table.getAttribute('data-default-sort') || '';
    const defaultDir = table.getAttribute('data-default-dir') || 'asc';
    const pageSize = parseInt(table.getAttribute('data-page-size') || '25', 10);

    if (!tableSource) {
        console.error('Tabella remote richiede data-source');
        return;
    }

    let columns = [];
    try {
        columns = JSON.parse(columnsJson);
    } catch (e) {
        console.error('Errore parsing data-columns:', e);
        return;
    }

    // Verifica e allinea il numero di celle nell'header con il numero di colonne
    // Trova la riga header effettiva, skippando righe decorative (es. th-groups)
    let headerRow = thead.rows[0];
    for (let i = 0; i < thead.rows.length; i++) {
        if (!thead.rows[i].classList.contains('th-groups') &&
            !thead.rows[i].classList.contains('filter-row')) {
            headerRow = thead.rows[i];
            break;
        }
    }
    if (!headerRow) {
        console.error('Header row non trovato');
        return;
    }

    const currentHeaderCells = headerRow.cells.length;
    const expectedColumns = columns.length;

    // Se ci sono più celle nell'header rispetto alle colonne configurate, rimuovi quelle extra
    if (currentHeaderCells > expectedColumns) {
        for (let i = currentHeaderCells - 1; i >= expectedColumns; i--) {
            headerRow.cells[i].remove();
        }
    }
    // Se ci sono meno celle nell'header rispetto alle colonne configurate, aggiungi quelle mancanti
    else if (currentHeaderCells < expectedColumns) {
        for (let i = currentHeaderCells; i < expectedColumns; i++) {
            const th = document.createElement('th');
            const col = columns[i];
            th.textContent = col.label || col.key || '';
            headerRow.appendChild(th);
        }
    }

    // Stato interno
    const state = {
        page: 1,
        pageSize: pageSize,
        filters: {},
        sort: defaultSort || (columns.find(c => c.sortable !== false)?.key || columns[0]?.key || ''),
        dir: defaultDir,
        search: '',
        loading: false,
    };

    // Debounce helper
    let debounceTimer = null;
    function debounce(func, delay) {
        return function (...args) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Crea riga filtri se non esiste o se ha un numero errato di celle
    let filterRow = thead.querySelector('.filter-row');
    const headerCellCount = headerRow.cells.length;

    // Se la filterRow esiste ma ha un numero diverso di celle rispetto all'header, ricreala
    if (filterRow && filterRow.cells.length !== headerCellCount) {
        filterRow.remove();
        filterRow = null;
    }

    if (!filterRow) {
        const headerRowIndex = Array.from(thead.rows).indexOf(headerRow);
        filterRow = thead.insertRow(headerRowIndex + 1);
        filterRow.className = 'filter-row';

        columns.forEach((col, idx) => {
            const th = document.createElement('th');
            th.classList.add('filter-header-cell');
            if (idx < columns.length - 1) th.classList.add('th-bordered-right');

            if (col.filter !== false) {
                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Cerca...';
                input.className = 'table-col-search';
                input.dataset.column = col.key;

                // Debounce input (350ms)
                input.addEventListener('input', debounce(() => {
                    const value = input.value.trim();
                    if (value) {
                        state.filters[col.key] = value;
                    } else {
                        delete state.filters[col.key];
                    }
                    state.page = 1; // Reset pagina
                    loadTableData();
                }, 350));

                th.appendChild(input);
            }
            filterRow.appendChild(th);
        });
    }

    // Aggiungi icone filtro agli header
    columns.forEach((col, idx) => {
        const cell = headerRow.cells[idx];
        if (!cell) return;

        cell.querySelector('.filter-icon')?.remove();

        if (col.filter !== false) {
            const icon = document.createElement('span');
            icon.className = 'filter-icon';
            icon.innerHTML = '<img src="/assets/icons/filter.png" style="width:15px;height:15px;vertical-align:middle;">';
            icon.onclick = async (e) => {
                e.stopPropagation();
                const input = filterRow.cells[idx].querySelector('.table-col-search');
                if (input && input.value.trim()) {
                    // Reset filtro
                    input.value = '';
                    delete state.filters[col.key];
                    state.page = 1;
                    loadTableData();
                } else {
                    // Mostra dropdown con facets
                    await showFacetsDropdown(col.key, icon, idx);
                }
            };
            cell.appendChild(icon);
        }
    });

    // Dropdown container
    let dropdown = document.getElementById('column-filter-dropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'column-filter-dropdown';
        dropdown.style.position = 'absolute';
        dropdown.style.display = 'none';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid #ccc';
        dropdown.style.borderRadius = '4px';
        dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '300px';
        dropdown.style.overflowY = 'auto';
        document.body.appendChild(dropdown);
    }

    // Mostra dropdown con facets (valori distinti dal server)
    async function showFacetsDropdown(columnKey, icon, colIdx) {
        try {
            const result = await window.customFetch('table_api', 'facets', {
                table: tableSource,
                column: columnKey,
                filters: state.filters,
                limit: 200,
            });

            if (!result.ok || !Array.isArray(result.values)) {
                console.error('Errore caricamento facets:', result);
                return;
            }

            dropdown.innerHTML = '';

            // Reset voce
            const reset = document.createElement('div');
            reset.textContent = 'Mostra tutti';
            reset.className = 'dropdown-filter-item';
            reset.style.padding = '8px 12px';
            reset.style.cursor = 'pointer';
            reset.style.borderBottom = '1px solid #eee';
            reset.style.fontWeight = '600';
            reset.onclick = () => {
                const input = filterRow.cells[colIdx].querySelector('.table-col-search');
                if (input) {
                    input.value = '';
                    delete state.filters[columnKey];
                    state.page = 1;
                    loadTableData();
                }
                dropdown.style.display = 'none';
            };
            dropdown.appendChild(reset);

            // Valori
            result.values.forEach(val => {
                const item = document.createElement('div');
                item.textContent = val;
                item.className = 'dropdown-filter-item';
                item.style.padding = '6px 12px';
                item.style.cursor = 'pointer';
                item.onmouseover = () => item.style.background = '#f5f5f5';
                item.onmouseout = () => item.style.background = '';
                item.onclick = () => {
                    const input = filterRow.cells[colIdx].querySelector('.table-col-search');
                    if (input) {
                        input.value = val;
                        state.filters[columnKey] = val;
                        state.page = 1;
                        loadTableData();
                    }
                    dropdown.style.display = 'none';
                };
                dropdown.appendChild(item);
            });

            // Posiziona dropdown
            const thCell = icon.closest('th');
            const rect = thCell.getBoundingClientRect();
            const scrollY = window.scrollY || document.documentElement.scrollTop;
            const scrollX = window.scrollX || document.documentElement.scrollLeft;

            dropdown.style.left = (rect.left + scrollX) + 'px';
            dropdown.style.top = (rect.bottom + scrollY + 2) + 'px';
            dropdown.style.width = Math.max(rect.width, 150) + 'px';
            dropdown.style.display = 'block';
        } catch (error) {
            console.error('Errore caricamento facets:', error);
        }
    }

    // Chiudi dropdown su click fuori
    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target) && !e.target.closest('.filter-icon')) {
            dropdown.style.display = 'none';
        }
    });

    // Carica dati dal server
    async function loadTableData() {
        if (state.loading) return;
        state.loading = true;

        // Mostra loading
        tbody.innerHTML = '<tr><td colspan="' + columns.length + '" style="text-align:center;padding:20px;">Caricamento...</td></tr>';

        try {
            // Estrai solo le chiavi delle colonne da passare al server
            const columnKeys = columns.map(col => col.key);

            const result = await window.customFetch('table_api', 'query', {
                table: tableSource,
                columns: columnKeys, // Passa solo le colonne da visualizzare
                page: state.page,
                pageSize: state.pageSize,
                filters: state.filters,
                sort: state.sort,
                dir: state.dir,
                search: state.search,
            });

            if (!result.ok) {
                tbody.innerHTML = '<tr><td colspan="' + columns.length + '" style="text-align:center;padding:20px;color:red;">Errore: ' + (result.message || 'Errore sconosciuto') + '</td></tr>';
                return;
            }

            // Aggiorna tbody
            tbody.innerHTML = result.rowsHtml || '';

            // Aggiorna paginazione SEMPRE, anche se totalRows è 0
            updatePagination(result);

            // Aggiorna icone filtri attivi
            updateFilterIcons();

            // Mostra la tabella quando è pronta (larghezze applicate e dati caricati)
            table.classList.add('table-ready');
        } catch (error) {
            console.error('Errore caricamento dati:', error);
            tbody.innerHTML = '<tr><td colspan="' + columns.length + '" style="text-align:center;padding:20px;color:red;">Errore nel caricamento</td></tr>';
        } finally {
            state.loading = false;
        }
    }

    // Aggiorna icone filtri attivi
    function updateFilterIcons() {
        columns.forEach((col, idx) => {
            const icon = headerRow.cells[idx]?.querySelector('.filter-icon');
            const input = filterRow.cells[idx]?.querySelector('.table-col-search');
            if (icon && input) {
                if (state.filters[col.key]) {
                    icon.classList.add('filter-active');
                } else {
                    icon.classList.remove('filter-active');
                }
            }
        });
    }

    // Crea/aggiorna paginazione
    function updatePagination(result) {
        // Prima rimuovi eventuali container duplicati esistenti
        const scrollableWrapper = table.closest('.gare-table-wrapper, .table-container, .table-wrapper, .table-filterable-wrapper');
        const parentContainer = scrollableWrapper ? scrollableWrapper.parentElement : table.parentElement;

        if (parentContainer) {
            const existingPaginationContainers = parentContainer.querySelectorAll('.table-pagination');
            // Rimuovi tutti tranne il primo (se ce ne sono più di uno)
            if (existingPaginationContainers.length > 1) {
                for (let i = 1; i < existingPaginationContainers.length; i++) {
                    existingPaginationContainers[i].remove();
                }
            }
        }

        // Cerca il container paginazione - prova diversi parent
        let paginationContainer = table.parentElement.querySelector('.table-pagination');

        // Se non trovato, cerca nel parent container del wrapper scrollabile
        if (!paginationContainer && parentContainer) {
            // Cerca nel parent del wrapper (dove abbiamo inserito la paginazione)
            paginationContainer = parentContainer.querySelector('.table-pagination');
        }

        // Se ancora non trovato, cerca anche come sibling del wrapper
        if (!paginationContainer && scrollableWrapper) {
            if (scrollableWrapper.nextElementSibling &&
                scrollableWrapper.nextElementSibling.classList.contains('table-pagination')) {
                paginationContainer = scrollableWrapper.nextElementSibling;
            }
        }

        if (!paginationContainer) {
            paginationContainer = document.createElement('div');
            paginationContainer.className = 'table-pagination';

            // Inserisci la paginazione dopo il wrapper scrollabile, non dentro
            if (scrollableWrapper && parentContainer) {
                // Inserisci dopo il wrapper scrollabile
                parentContainer.insertBefore(paginationContainer, scrollableWrapper.nextSibling);
            } else {
                // Fallback: inserisci dopo la tabella
                table.parentElement.appendChild(paginationContainer);
            }

        }

        const totalPages = result.totalPages || 1;
        const currentPage = result.page || 1;
        const totalRows = result.totalRows || 0;


        paginationContainer.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;">
                <button class="pagination-btn" data-action="prev" ${currentPage <= 1 ? 'disabled' : ''} style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:6px;cursor:pointer;font-size:13px;transition:all 0.2s ease;">‹ Prev</button>
                <span class="pagination-info" style="font-size:13px;color:#6b7280;font-weight:500;">Pagina ${currentPage} di ${totalPages} (${totalRows} risultati)</span>
                <button class="pagination-btn" data-action="next" ${currentPage >= totalPages ? 'disabled' : ''} style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:6px;cursor:pointer;font-size:13px;transition:all 0.2s ease;">Next ›</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <label class="pagination-rows-label">Righe per pagina:</label>
                <select class="pagination-page-size" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:13px;cursor:pointer;">
                    <option value="10" ${state.pageSize === 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${state.pageSize === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${state.pageSize === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${state.pageSize === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        `;


        // Event listeners paginazione
        paginationContainer.querySelector('[data-action="prev"]')?.addEventListener('click', () => {
            if (currentPage > 1) {
                state.page = currentPage - 1;
                loadTableData();
            }
        });

        paginationContainer.querySelector('[data-action="next"]')?.addEventListener('click', () => {
            if (currentPage < totalPages) {
                state.page = currentPage + 1;
                loadTableData();
            }
        });

        paginationContainer.querySelector('.pagination-page-size')?.addEventListener('change', (e) => {
            state.pageSize = parseInt(e.target.value, 10);
            state.page = 1;
            loadTableData();
        });
    }

    // Ordinamento su click header
    columns.forEach((col, idx) => {
        const cell = headerRow.cells[idx];
        if (!cell || col.sortable === false) return;

        cell.style.cursor = 'pointer';
        cell.addEventListener('click', () => {
            if (state.sort === col.key) {
                state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sort = col.key;
                state.dir = 'asc';
            }
            state.page = 1;
            loadTableData();
        });
    });

    // Caricamento iniziale solo se remote
    // Caricamento iniziale
    loadTableData();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table.table-filterable').forEach(table => {
        // Evita doppia inizializzazione
        if (table.dataset.filtersInitialized === 'true') {
            return;
        }
        table.dataset.filtersInitialized = 'true';

        initTableFilters(table.id);

        // Se la tabella NON è in modalità remote, aggiungi paginazione client-side
        // dopo un breve delay per permettere il popolamento dinamico
        if (table.getAttribute('data-remote') !== '1' && table.getAttribute('data-no-pagination') !== 'true') {
            // Per tabelle popolate dinamicamente (come gare-table), 
            // la paginazione verrà inizializzata da renderTable() in gare_list.js
            // Non inizializziamo qui per evitare doppie chiamate
            if (!table.id || table.id !== 'gare-table') {
                // NASCONDI IMMEDIATAMENTE tutte le righe per evitare di vedere tutti i record
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    const allRows = Array.from(tbody.querySelectorAll('tr'));
                    allRows.forEach(row => {
                        row.style.display = 'none';
                    });
                }

                setTimeout(() => {
                    if (typeof window.initClientSidePagination === 'function') {
                        window.initClientSidePagination(table);
                    } else {
                        console.error('window.initClientSidePagination non definita');
                    }
                    // La tabella verrà mostrata quando initClientSidePagination chiama updateView()
                    // (gestito dentro initClientSidePagination dopo updateView())
                }, 500);
            }
        } else {
            // Per tabelle remote o senza paginazione, mostra la tabella direttamente
            if (table.getAttribute('data-no-pagination') === 'true') {
                // Mostra tutte le righe se la paginazione è disabilitata
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    const allRows = Array.from(tbody.querySelectorAll('tr'));
                    allRows.forEach(row => {
                        row.style.display = '';
                    });
                }
                // Mostra la tabella
                if (!table.classList.contains('table-ready')) {
                    table.classList.add('table-ready');
                }
            } else {
                // Per tabelle remote, la tabella verrà mostrata quando i dati sono caricati
                // (gestito in loadTableData dopo updatePagination)
            }
        }
    });
});

/**
 * Inizializza paginazione client-side per tabelle popolate dinamicamente
 * Funziona su tutte le righe già presenti nel DOM
 */
window.initClientSidePagination = function initClientSidePagination(table) {
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    // Verifica se la paginazione è già stata inizializzata
    if (table.dataset.paginationInitialized === 'true') {
        // Se già inizializzata, aggiorna solo la visualizzazione
        if (table._paginationUpdateView) {
            table._paginationUpdateView();
        }
        return;
    }
    table.dataset.paginationInitialized = 'true';

    // NASCONDI IMMEDIATAMENTE tutte le righe finché la paginazione non è pronta
    // Questo evita di vedere tutti i record prima che la paginazione sia applicata
    const allRows = Array.from(tbody.querySelectorAll('tr'));
    allRows.forEach(row => {
        row.style.display = 'none';
    });

    // Flag per evitare chiamate multiple simultanee (devono essere nello scope della funzione)
    let isUpdatingView = false;
    let isObserving = false;

    // Funzione per ottenere solo le righe visibili (non nascoste dai filtri)
    // IMPORTANTE: questa funzione deve essere chiamata PRIMA che la paginazione nasconda le righe
    function getVisibleRows() {
        const allRows = Array.from(tbody.querySelectorAll('tr'));
        // Per determinare se una riga è visibile, dobbiamo verificare se corrisponde ai filtri
        // Ma questo è complesso, quindi usiamo un approccio diverso:
        // Salviamo lo stato originale delle righe prima che la paginazione le modifichi
        return allRows.filter(row => {
            // Se la riga ha un attributo data-visible="false", è nascosta dai filtri
            // Altrimenti, controlla lo style.display originale
            const wasHiddenByFilter = row.dataset.hiddenByFilter === 'true';
            return !wasHiddenByFilter && row.style.display !== 'none';
        });
    }

    // Mapping colonne per filtri (pre-calcolato una volta)
    let paginationMapping = null;
    function getPaginationMapping() {
        if (paginationMapping) return paginationMapping;
        const thead = table.querySelector('thead');
        if (!thead) return [];
        // Trova la riga header effettiva, skippando righe decorative (es. th-groups)
        let headerRow = thead.rows[0];
        for (let i = 0; i < thead.rows.length; i++) {
            if (!thead.rows[i].classList.contains('th-groups') &&
                !thead.rows[i].classList.contains('filter-row')) {
                headerRow = thead.rows[i];
                break;
            }
        }
        const filterRow = thead.querySelector('.filter-row');
        if (!filterRow) return [];

        const mapping = [];
        const theadInputs = filterRow.querySelectorAll('.table-col-search');
        theadInputs.forEach((_, inputIdx) => {
            let colIdx = null;
            let currentInputCount = 0;
            for (let i = 0; i < headerRow.cells.length; i++) {
                const cell = headerRow.cells[i];
                const isAzioni = cell.classList.contains('azioni-colonna') || cell.innerText.trim().toLowerCase() === 'azioni';
                if (!isAzioni) {
                    if (currentInputCount === inputIdx) {
                        colIdx = i;
                        break;
                    }
                    currentInputCount++;
                }
            }
            mapping.push(colIdx);
        });
        paginationMapping = mapping;
        return mapping;
    }

    // Stato paginazione
    const state = {
        currentPage: 1,
        pageSize: parseInt(table.getAttribute('data-page-size') || '10', 10),
    };

    // Funzione per aggiornare il conteggio delle righe visibili
    function updateRowCount() {
        const visibleRows = getVisibleRows();
        state.totalRows = visibleRows.length;
        state.totalPages = Math.max(1, Math.ceil(state.totalRows / state.pageSize));
        if (state.currentPage > state.totalPages) {
            state.currentPage = Math.max(1, state.totalPages);
        }
        return visibleRows;
    }


    // Crea container paginazione
    // Prima rimuovi eventuali container duplicati esistenti
    const scrollableWrapper = table.closest('.gare-table-wrapper, .table-container, .table-wrapper');
    const parentContainer = scrollableWrapper ? scrollableWrapper.parentElement : table.parentElement;

    if (parentContainer) {
        const existingPaginationContainers = parentContainer.querySelectorAll('.table-pagination');
        // Rimuovi tutti tranne il primo (se ce ne sono più di uno)
        if (existingPaginationContainers.length > 1) {
            for (let i = 1; i < existingPaginationContainers.length; i++) {
                existingPaginationContainers[i].remove();
            }
        }
    }

    // Cerca prima nel parent della tabella, poi nel parent del wrapper
    let paginationContainer = table.parentElement.querySelector('.table-pagination');

    // Se non trovato, cerca nel parent container del wrapper scrollabile
    if (!paginationContainer && parentContainer) {
        // Cerca nel parent del wrapper (dove abbiamo inserito la paginazione)
        paginationContainer = parentContainer.querySelector('.table-pagination');
    }

    // Se ancora non trovato, cerca anche come sibling del wrapper
    if (!paginationContainer && scrollableWrapper) {
        if (scrollableWrapper.nextElementSibling &&
            scrollableWrapper.nextElementSibling.classList.contains('table-pagination')) {
            paginationContainer = scrollableWrapper.nextElementSibling;
        }
    }

    if (!paginationContainer) {
        paginationContainer = document.createElement('div');
        paginationContainer.className = 'table-pagination';

        // Inserisci la paginazione dopo il wrapper scrollabile, non dentro
        if (scrollableWrapper && parentContainer) {
            // Inserisci dopo il wrapper scrollabile
            parentContainer.insertBefore(paginationContainer, scrollableWrapper.nextSibling);
        } else {
            // Fallback: inserisci dopo la tabella
            table.parentElement.appendChild(paginationContainer);
        }
    }

    // Funzione per aggiornare la visualizzazione
    let updateViewTimeout = null;
    function updateView() {
        // Debounce per evitare chiamate multiple rapide
        if (updateViewTimeout) {
            clearTimeout(updateViewTimeout);
        }

        updateViewTimeout = setTimeout(() => {
            isUpdatingView = true;
            // Ottieni tutte le righe dal DOM
            const allRows = Array.from(tbody.querySelectorAll('tr'));

            // Prima, applica i filtri per determinare quali righe sono visibili
            const thead = table.querySelector('thead');
            const filterRow = thead?.querySelector('.filter-row');
            const filters = filterRow ? Array.from(filterRow.querySelectorAll('.table-col-search')).map(i => i.value.trim().toLowerCase()) : [];
            const mapping = getPaginationMapping();

            const visibleRows = [];
            allRows.forEach(row => {
                const matches = window.rowMatchesFilters(row, filters, mapping);
                if (matches) {
                    visibleRows.push(row);
                    row.dataset.hiddenByFilter = 'false';
                } else {
                    row.dataset.hiddenByFilter = 'true';
                    row.style.display = 'none';
                }
            });

            // Aggiorna il conteggio basato sulle righe visibili
            state.totalRows = visibleRows.length;
            state.totalPages = Math.max(1, Math.ceil(state.totalRows / state.pageSize));
            if (state.currentPage > state.totalPages) {
                state.currentPage = Math.max(1, state.totalPages);
            }

            const start = (state.currentPage - 1) * state.pageSize;
            const end = start + state.pageSize;

            // Applica filtri e paginazione:
            // 1. Nascondi tutte le righe che non corrispondono ai filtri
            // 2. Mostra solo le righe della pagina corrente tra quelle visibili
            allRows.forEach(row => {
                const isVisible = visibleRows.includes(row);
                if (!isVisible) {
                    // Nascondi se non corrisponde ai filtri
                    row.style.display = 'none';
                } else {
                    // Se è visibile, mostra solo se è nella pagina corrente
                    const indexInVisible = visibleRows.indexOf(row);
                    if (indexInVisible >= start && indexInVisible < end) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            updatePaginationUI();

            // Mostra la tabella quando la paginazione è completamente applicata
            if (!table.classList.contains('table-ready')) {
                requestAnimationFrame(() => {
                    table.classList.add('table-ready');
                });
            }
            // Sblocca dopo un tick per evitare che l'observer ri-triggeri updateView
            requestAnimationFrame(() => { isUpdatingView = false; });
        }, 50);
    }

    // Funzione per aggiornare UI paginazione
    function updatePaginationUI() {
        paginationContainer.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;">
                <button class="pagination-btn" data-action="prev" ${state.currentPage <= 1 ? 'disabled' : ''} style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:6px;cursor:pointer;font-size:13px;transition:all 0.2s ease;">‹ Prev</button>
                <span class="pagination-info" style="font-size:13px;color:#6b7280;font-weight:500;">Pagina ${state.currentPage} di ${state.totalPages} (${state.totalRows} risultati)</span>
                <button class="pagination-btn" data-action="next" ${state.currentPage >= state.totalPages ? 'disabled' : ''} style="padding:6px 12px;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:6px;cursor:pointer;font-size:13px;transition:all 0.2s ease;">Next ›</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <label class="pagination-rows-label">Righe per pagina:</label>
                <select class="pagination-page-size" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:13px;cursor:pointer;">
                    <option value="10" ${state.pageSize === 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${state.pageSize === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${state.pageSize === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${state.pageSize === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        `;

        // Event listeners
        paginationContainer.querySelector('[data-action="prev"]')?.addEventListener('click', () => {
            if (state.currentPage > 1) {
                state.currentPage--;
                updateView();
            }
        });

        paginationContainer.querySelector('[data-action="next"]')?.addEventListener('click', () => {
            if (state.currentPage < state.totalPages) {
                state.currentPage++;
                updateView();
            }
        });

        paginationContainer.querySelector('.pagination-page-size')?.addEventListener('change', (e) => {
            state.pageSize = parseInt(e.target.value, 10);
            state.currentPage = 1;
            state.totalPages = Math.max(1, Math.ceil(state.totalRows / state.pageSize));
            updateView();
        });
    }

    // Inizializza visualizzazione - usa setTimeout per assicurarsi che tutte le righe siano state aggiunte
    setTimeout(() => {
        // Applica la paginazione (nasconde/mostra le righe corrette)
        updateView();
        // Mostra la tabella SOLO dopo che updateView() ha applicato la paginazione
        requestAnimationFrame(() => {
            table.classList.add('table-ready');
        });
    }, 100);

    // Osserva cambiamenti nel DOM e negli stili delle righe
    // Usa un flag per evitare loop infiniti durante l'inizializzazione
    const observer = new MutationObserver(() => {
        // Ignora se stiamo già aggiornando o se la tabella non è ancora pronta
        if (isUpdatingView || isObserving || !table.classList.contains('table-ready')) {
            return;
        }

        // Usa setTimeout per evitare chiamate multiple durante il rendering
        setTimeout(() => {
            isObserving = true;
            const allRows = Array.from(tbody.querySelectorAll('tr'));
            const thead = table.querySelector('thead');
            const filterRow = thead?.querySelector('.filter-row');
            const filters = filterRow ? Array.from(filterRow.querySelectorAll('.table-col-search')).map(i => i.value.trim().toLowerCase()) : [];
            const mapping = getPaginationMapping();
            const visibleRows = allRows.filter(row => window.rowMatchesFilters(row, filters, mapping));

            // Aggiorna se il numero di righe totali o visibili è cambiato
            if (allRows.length !== (table._lastTotalRows || 0) ||
                visibleRows.length !== (table._lastVisibleRows || 0)) {
                // Aggiorna i contatori
                table._lastTotalRows = allRows.length;
                table._lastVisibleRows = visibleRows.length;

                // Re-inizializza la paginazione se necessario
                if (table._paginationUpdateView && !isUpdatingView) {
                    updateView();
                }
            }

            // Reset flag dopo un breve delay
            setTimeout(() => {
                isObserving = false;
            }, 100);
        }, 50);
    });

    // Osserva cambiamenti nelle righe (childList) e negli attributi style (per display dai filtri)
    observer.observe(tbody, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });

    // Osserva anche i cambiamenti negli input di filtro per aggiornare la paginazione
    const thead = table.querySelector('thead');
    if (thead) {
        const filterInputs = thead.querySelectorAll('.table-col-search');
        filterInputs.forEach(input => {
            // Usa input invece di change per catturare anche cancellazioni
            input.addEventListener('input', () => {
                // Reset alla prima pagina quando cambia il filtro
                state.currentPage = 1;
                setTimeout(() => {
                    updateView();
                }, 100);
            });
        });
    }

    // Salva riferimento alla funzione updateView nello stato per accesso esterno
    table._paginationState = state;
    table._paginationUpdateView = updateView;
    table._lastTotalRows = Array.from(tbody.querySelectorAll('tr')).length;

    const initialThead = table.querySelector('thead');
    const initialFilterRow = initialThead?.querySelector('.filter-row');
    const initialFilters = initialFilterRow ? Array.from(initialFilterRow.querySelectorAll('.table-col-search')).map(i => i.value.trim().toLowerCase()) : [];
    const initialMapping = getPaginationMapping();
    table._lastVisibleRows = Array.from(tbody.querySelectorAll('tr')).filter(row => window.rowMatchesFilters(row, initialFilters, initialMapping)).length;
};

/* ============================================================
   ASSIGNEE COMPONENT (Canonical)
   Multi-user picker + avatar rendering utilities
   ============================================================ */

/* === Core UI Styles Injection === */
(function injectCoreStyles() {
    const styleId = 'main-core-styles';
    if (document.getElementById(styleId)) return;
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
        .assignee-avatars-group { display: flex; align-items: center; cursor: pointer; min-height: 24px; }
        .assignee-avatar { width: 24px; height: 24px; border-radius: 50%; border: 2px solid #fff; margin-left: -8px; object-fit: cover; transition: transform 0.2s; }
        .assignee-avatar:first-child { margin-left: 0; }
        .assignee-avatar:hover { transform: translateY(-2px); z-index: 10 !important; }
        .assignee-overflow-badge { width: 24px; height: 24px; border-radius: 50%; background: #e0e0e0; color: #666; font-size: 10px; font-weight: bold; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; margin-left: -8px; z-index: 0; }
        .assignee-empty { color: #ccc; font-style: italic; font-size: 0.9em; }
        
        .multi-user-picker-box { background: #fff; border-radius: 8px; border: 1px solid #ddd; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px; min-width: 280px; z-index: 10001; }
        .mup-list { max-height: 300px; overflow-y: auto; margin: 10px 0; border: 1px solid #eee; border-radius: 4px; }
        .mup-user { display: flex; align-items: center; padding: 6px 10px; cursor: pointer; transition: background 0.2s; border-bottom: 1px solid #f9f9f9; }
        .mup-user:hover { background: #f0f7ff; }
        .mup-user.selected { background: #eef6ff; }
        .mup-user-label { display: flex; align-items: center; gap: 10px; cursor: pointer; width: 100%; margin: 0; font-weight: 400; }
        .mup-user-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
        .mup-user-name { font-size: 0.95em; color: #333; }
        .mup-cb { width: 16px; height: 16px; cursor: pointer; }
        .mup-count { font-size: 0.85em; color: #666; font-weight: 500; }
        .mup-count-limit { color: #d9534f; }
        .mup-search { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; outline: none; transition: border-color 0.2s; }
        .mup-search:focus { border-color: #007bff; }
    `;
    document.head.appendChild(style);
})();

// === UTILITÀ ID LIST ===
window.normalizeIdList = function (value, max) {
    max = max || 6;
    if (!value) return [];
    var arr = Array.isArray(value) ? value : String(value).split(',');
    var seen = {};
    var out = [];
    for (var i = 0; i < arr.length; i++) {
        var n = parseInt(String(arr[i]).trim(), 10);
        if (!isNaN(n) && n > 0 && !seen[n]) {
            seen[n] = true;
            out.push(n);
        }
    }
    return out.slice(0, max);
};

// === RENDER AVATAR SOVRAPPOSTI (globale, riusabile) ===
window.renderAvatarsOverlap = function (container, users, opts) {
    opts = opts || {};
    var maxVisible = opts.maxVisible || 3;
    container.innerHTML = '';
    if (!users || !users.length) {
        container.innerHTML = '<span class="assignee-empty">\u2014</span>';
        return;
    }
    var wrapper = document.createElement('div');
    wrapper.className = 'assignee-avatars-group';
    var allNames = users.map(function (u) { return u.nominativo || u.Nominativo || u.nome_completo || u.nome || u.name || ''; }).filter(Boolean).join(', ');
    wrapper.setAttribute('data-tooltip', allNames);

    var visible = users.slice(0, maxVisible);
    var overflow = users.length - maxVisible;

    visible.forEach(function (u, i) {
        var name = u.nominativo || u.Nominativo || u.nome_completo || u.nome || u.name || '';
        var img = document.createElement('img');
        img.src = u.imagePath || u.img || u.image || window.generateInitialsAvatar(name);
        img.alt = name;
        img.title = name;
        img.className = 'assignee-avatar overlap';
        img.style.zIndex = maxVisible - i; // Garantisce overlap stack corretto (da sinistra verso destra sotto)
        wrapper.appendChild(img);
    });

    if (overflow > 0) {
        var badge = document.createElement('span');
        badge.className = 'assignee-overflow-badge';
        badge.textContent = '+' + overflow;
        var extraNames = users.slice(maxVisible).map(function (u) { return u.nominativo || u.Nominativo || u.nome_completo || u.nome || u.name || ''; }).filter(Boolean).join(', ');
        badge.setAttribute('data-tooltip', extraNames);
        wrapper.appendChild(badge);
    }
    container.appendChild(wrapper);
};

// === HELPER: NORMALIZE ID LIST ===
window.normalizeIdList = function (value, max) {
    if (!value) return [];
    if (Array.isArray(value)) return value.map(String);
    const ids = String(value).split(',').map(v => String(v).trim()).filter(v => v !== '' && v !== '0');
    return max ? ids.slice(0, max) : ids;
};

// === MULTI-USER PICKER (globale, riusabile) ===
// === MULTI-USER PICKER (via GlobalDrawer) ===
// === MULTI-USER PICKER (via GlobalDrawer) ===
function registerAssigneesManagerView() {
    if (!window.GlobalDrawer) {
        return;
    }
    window.GlobalDrawer.registerView("assigneesManager", (opts) => {
        let users = opts.users || [];
        const selectedIds = opts.selectedIds || [];
        const max = opts.max || 6;
        const resolve = opts.resolve;

        // Normalizzazione dati utenti (use UserManager for consistency)
        users = users.map(u => {
            const id = Number(u.id || u.user_id || u.ID);
            // Prova a recuperare da UserManager se disponibile, altrimenti usa i dati grezzi
            const cached = window.UserManager ? window.UserManager.getUser(id) : null;

            const name = (cached && cached.nome) ? cached.nome : (u.Nominativo || u.nominativo || u.nome_completo || u.nome || u.username || u.name || ('Utente ' + id));

            // Logica immagine robusta: cache > prop diretta > default
            let img = (cached && cached.img) ? cached.img : (u.imagePath || u.profile_img || u.img || u.src || u.image);
            if (!img || img === 'null') img = '/assets/images/default_profile.png';

            return { id, name, img };
        }).filter(u => u.id > 0); // Filtra ID validi (il nome potrebbe essere ancora incerto ma l'ID è fondamentale)

        let currentSelected = [...selectedIds];

        const html = `
            <div class="multi-user-picker-box-inline">
                <input type="text" class="mup-search" placeholder="Cerca utenti..." style="width: 100%; margin-bottom: 10px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <div class="mup-count" style="margin-bottom: 10px; font-size: 0.9em; color: #666;">
                    <span class="mup-count-num">${currentSelected.length}</span> / ${max} selezionati
                </div>
                <div class="mup-list" style="max-height: calc(100vh - 350px); overflow-y: auto; border: 1px solid #eee; border-radius: 4px; padding: 5px;">
                    ${users.length === 0 ? '<div style="padding:10px;text-align:center;color:#999;">Nessun utente trovato</div>' : ''}
                    ${users.map(u => {
            const isSelected = currentSelected.includes(u.id);
            return `
                            <div class="mup-user ${isSelected ? 'selected' : ''}" data-id="${u.id}" style="display: flex; align-items: center; padding: 8px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.2s;">
                                <input type="checkbox" class="mup-cb" ${isSelected ? 'checked' : ''} style="margin-right: 10px; pointer-events: none;">
                                <img src="${u.img}" alt="" style="width: 30px; height: 30px; border-radius: 50%; margin-right: 10px; object-fit: cover; border:1px solid #eee;">
                                <span class="mup-user-name" style="flex: 1; font-size:14px; font-weight:500;">${u.name}</span>
                            </div>
                        `;
        }).join('')}
                </div>
                <div class="mup-buttons" style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: flex-end; padding-top:10px; border-top:1px solid #eee;">
                    <button class="button mup-cancel" style="background: #eee; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; color:#333;">Annulla</button>
                    <button class="button mup-apply" style="background: var(--primary-color, #007bff); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">Applica</button>
                </div>
            </div>
        `;

        return {
            title: opts.title || 'Seleziona assegnatari',
            html: html,
            onReady: (container) => {
                const list = container.querySelector('.mup-list');
                const countEl = container.querySelector('.mup-count-num');
                const searchInput = container.querySelector('.mup-search');

                const updateCount = () => {
                    countEl.textContent = container.querySelectorAll('.mup-user.selected').length;
                };

                container.querySelectorAll('.mup-user').forEach(row => {
                    row.onclick = () => {
                        const cb = row.querySelector('.mup-cb');
                        const isSelected = row.classList.contains('selected');
                        const currentCount = container.querySelectorAll('.mup-user.selected').length;

                        if (!isSelected && currentCount >= max) {
                            if (window.showToast) window.showToast(`Massimo ${max} assegnatari consentiti.`, 'error');
                            return;
                        }

                        cb.checked = !isSelected;
                        row.classList.toggle('selected', !isSelected);
                        updateCount();
                    };
                });

                searchInput.oninput = (e) => {
                    const val = e.target.value.toLowerCase();
                    container.querySelectorAll('.mup-user').forEach(row => {
                        const name = row.querySelector('.mup-user-name').textContent.toLowerCase();
                        row.style.display = name.includes(val) ? 'flex' : 'none';
                    });
                };

                const closePicker = () => {
                    if (window.GlobalDrawer) window.GlobalDrawer.close();
                };

                container.querySelector('.mup-apply').onclick = () => {
                    const selected = Array.from(container.querySelectorAll('.mup-user.selected')).map(r => parseInt(r.dataset.id, 10));
                    if (opts.onConfirm) opts.onConfirm(selected);
                    if (resolve) resolve(selected);
                    closePicker();
                };

                container.querySelector('.mup-cancel').onclick = () => {
                    if (resolve) resolve(null);
                    closePicker();
                };

                setTimeout(() => searchInput.focus(), 100);
            }
        };
    });
}
document.addEventListener('DOMContentLoaded', registerAssigneesManagerView);

window.openMultiUserPicker = function (opts) {
    return new Promise(async function (resolve) {
        if (!window.GlobalDrawer) {
            console.error("GlobalDrawer non caricato.");
            resolve(null);
            return;
        }

        // Auto-fetch utenti se non forniti dal chiamante
        if (!opts.users || !opts.users.length) {
            try {
                const res = await window.customFetch('forms', 'getUtentiList');
                if (res && res.success && Array.isArray(res.data)) {
                    opts.users = res.data;
                    // Popola anche UserManager per coerenza
                    if (window.UserManager) window.UserManager.populate(res.data);
                }
            } catch (e) {
                console.warn('openMultiUserPicker: errore caricamento utenti', e);
            }
        }

        // Normalizza selectedIds a numeri (il drawer usa Number() per confronto)
        if (Array.isArray(opts.selectedIds)) {
            opts.selectedIds = opts.selectedIds.map(function (id) { return Number(id); }).filter(function (n) { return !isNaN(n) && n > 0; });
        }

        // Just-in-time registration if not already there
        registerAssigneesManagerView();

        window.GlobalDrawer.openView("assigneesManager", { ...opts, resolve });
    });
};

// === SELETTORE UTENTI UNIVERSALE (legacy, backward-compatible) ===
window.showUserSelector = async function (triggerButton, selectedIds = [], onConfirm, preloadedUsers) {
    // Delega a openMultiUserPicker
    let utenti;
    if (Array.isArray(preloadedUsers) && preloadedUsers.length > 0) {
        utenti = preloadedUsers;
    } else {
        const res = await customFetch("user", "getAllMinified");
        if (!res.success || !Array.isArray(res.data)) {
            if (window.showToast) window.showToast('Errore caricamento utenti.', 'error');
            return;
        }
        utenti = res.data;
    }
    window.openMultiUserPicker({
        users: utenti,
        selectedIds: selectedIds,
        onConfirm: onConfirm
    });
};

// === AUTO-CAPITALIZE ===
function capitalizeSentence(text) {
    if (!text || typeof text !== 'string') return '';
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

function capitalizeAllTexts() {
    document.querySelectorAll('p, span, label, .sentence, .auto-capitalize').forEach(function (el) {
        // Escludi sidebar (tutti i figli di .fixed-sidebar)
        if (el.closest('.fixed-sidebar')) return;
        el.textContent = capitalizeSentence(el.textContent);
    });
    document.querySelectorAll('input[type="text"], textarea').forEach(function (el) {
        if (el.closest('.fixed-sidebar')) return;
        el.value = capitalizeSentence(el.value);
    });
}

// Delegation globale per capitalizzazione OVUNQUE
document.addEventListener('blur', function (e) {
    // SOLO input text e textarea
    if (
        (e.target.tagName === 'INPUT' && e.target.type === 'text') ||
        e.target.tagName === 'TEXTAREA'
    ) {
        // Escluso quelli sensibili
        if (
            e.target.name === 'email' ||
            e.target.name === 'password' ||
            e.target.name === 'username' ||
            e.target.classList.contains('no-capitalize')
        ) return;

        if (typeof e.target.value === 'string' && e.target.value.length > 0) {
            e.target.value = e.target.value.charAt(0).toUpperCase() + e.target.value.slice(1);
        }
    }
}, true);

window.renderUserAvatarsGroup = function (containerSelector, avatarList, responsabileId = null) {
    const container = typeof containerSelector === 'string' ? document.querySelector(containerSelector) : containerSelector;
    if (!container) return;
    container.innerHTML = '';
    if (!Array.isArray(avatarList) || !avatarList.length) {
        container.innerHTML = '<span style="color:#aaa;">Nessun membro</span>';
        return;
    }

    // Separa responsabile e membri
    const responsabile = avatarList.find(u => Number(u.user_id ?? u.id) === Number(responsabileId));
    const membri = avatarList.filter(u => Number(u.user_id ?? u.id) !== Number(responsabileId));

    // Aggiungi prima il responsabile (se presente)
    if (responsabile) {
        const wrapper = document.createElement("span");
        wrapper.className = "mini-avatar-wrapper responsabile";
        wrapper.style.position = "relative";
        wrapper.style.display = "inline-block";
        wrapper.style.marginRight = "6px";
        wrapper.style.verticalAlign = "middle";
        wrapper.style.width = "32px";
        wrapper.style.height = "32px";

        const img = document.createElement("img");
        img.src = responsabile.img || responsabile.imagePath || window.generateInitialsAvatar(responsabile.nome || responsabile.nominativo);
        img.alt = responsabile.nome || responsabile.nominativo || '';
        img.className = "mini-avatar";
        img.style.width = "28px";
        img.style.height = "28px";
        img.style.borderRadius = "50%";
        img.style.objectFit = "cover";
        img.style.boxShadow = "0 0 0 1.5px #E6B600";
        img.setAttribute('data-tooltip', "Responsabile: " + (responsabile.nome || responsabile.nominativo || ''));
        img.removeAttribute('title');

        // CORONA SVG
        const crown = document.createElement("span");
        crown.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="19" height="15" viewBox="0 0 72 72">
              <path fill="#ffb636" d="M68.4 10.8c-.6 1.4-1 3.3-1.1 4.5c-1.6 0-2.9 1.3-2.9 2.9c0 1.6 1.3 2.9 2.9 2.9c.2 0 .4 0 .6-.1c-.2 4.3-3.7 7.7-8.1 7.7c-4.4 0-7.9-3.4-8.1-7.7c1.3-.2 2.4-1.4 2.4-2.8c0-1.6-1.3-2.9-2.9-2.9h-.3c-.4-2-1.5-5.2-2.8-5.2c-1.3 0-2.4 3.1-2.8 5.2c-.2 0-.4-.1-.6-.1c-1.6 0-2.9 1.3-2.9 2.9c0 1.4 1 2.6 2.4 2.9c-.2 4.3-3.7 7.7-8.1 7.7c-4.4 0-7.9-3.5-8.1-7.8c1.3-.3 2.2-1.4 2.2-2.8c0-1.6-1.3-2.9-2.9-2.9H27c-.4-2-1.5-5.2-2.8-5.2c-1.3 0-2.4 3.1-2.8 5.2c-.2 0-.4-.1-.6-.1c-1.6 0-2.9 1.3-2.9 2.9c0 1.5 1.1 2.7 2.6 2.9c-.2 4.3-3.8 7.7-8.1 7.7c-4.4 0-7.9-3.4-8.1-7.7c.2 0 .4.1.6.1c1.6 0 2.9-1.3 2.9-2.9c0-1.6-1.3-2.9-2.9-2.9h-.2c-.2-1.3-.5-3-.9-4.4c-.3-1.1-1.8-.9-1.8.3v46.8h68.4V11.3c-.2-1.2-1.5-1.5-2-.5z"/>
              <path fill="#ffd469" d="M70.8 43.6H1.2c-.7 0-1.2-.5-1.2-1.2V39c0-.7.5-1.2 1.2-1.2h69.5c.7 0 1.2.5 1.2 1.2v3.4c.1.7-.4 1.2-1.1 1.2zm1.2 17v-3.4c0-.7-.5-1.2-1.2-1.2H1.2c-.7 0-1.2.5-1.2 1.2v3.4c0 .7.5 1.2 1.2 1.2h69.5c.8 0 1.3-.5 1.3-1.2z"/>
              <path fill="#ffc7ef" d="M64.4 50c0 1.8-1.4 3.2-3.2 3.2S58 51.8 58 50s1.4-3.2 3.2-3.2s3.2 1.4 3.2 3.2zM36 46.8c-1.8 0-3.2 1.4-3.2 3.2s1.4 3.2 3.2 3.2s3.2-1.4 3.2-3.2s-1.4-3.2-3.2-3.2zm-25.2 0c-1.8 0-3.2 1.4-3.2 3.2s1.4 3.2 3.2 3.2S14 51.7 14 50s-1.4-3.2-3.2-3.2z"/>
            </svg>
        `;
        crown.style.position = "absolute";
        crown.style.left = "7px";
        crown.style.top = "-14px";
        crown.style.width = "28px";
        crown.style.height = "14px";
        crown.style.pointerEvents = "none";
        crown.style.zIndex = 2;
        wrapper.appendChild(crown);

        wrapper.appendChild(img);
        container.appendChild(wrapper);
    }

    // Ora avatar gruppo di lavoro (sovrapposti tra loro)
    membri.forEach((u, i) => {
        const wrapper = document.createElement("span");
        wrapper.className = "mini-avatar-wrapper";
        wrapper.style.position = "relative";
        wrapper.style.display = "inline-block";
        wrapper.style.marginRight = "-12px"; // SOVRAPPOSIZIONE
        wrapper.style.verticalAlign = "middle";
        wrapper.style.width = "32px";
        wrapper.style.height = "32px";
        wrapper.style.zIndex = 1 + i;

        const img = document.createElement("img");
        img.src = u.img || u.imagePath || window.generateInitialsAvatar(u.nome || u.nominativo);
        img.alt = u.nome || u.nominativo || '';
        img.className = "mini-avatar";
        img.style.width = "28px";
        img.style.height = "28px";
        img.style.borderRadius = "50%";
        img.style.objectFit = "cover";
        img.style.boxShadow = "0 0 0 1.5px #ccc";
        img.setAttribute('data-tooltip', u.nome || u.nominativo || '');
        img.removeAttribute('title');

        wrapper.appendChild(img);
        container.appendChild(wrapper);
    });
};

/**
 * Inizializza un campo upload con anteprima, drag&drop, click e incolla (ctrl+v).
 * @param {string|HTMLElement} dropzoneSelector - id, classe o elemento dom del contenitore .dropzone-upload
 * @param {object} [opts] - Opzioni: {multiple: true/false, accepted: 'image/jpeg,image/png', maxFiles: 5}
 * @returns {function} - Funzione reset (per azzerare file e anteprima)
 */
window.initFileUploadDropzone = function (dropzoneSelector, opts = {}) {
    const dropzone = typeof dropzoneSelector === 'string' ? document.querySelector(dropzoneSelector) : dropzoneSelector;
    if (!dropzone) return;
    const input = dropzone.querySelector('input[type="file"]');
    const preview = dropzone.querySelector('.upload-preview');
    const removeBtn = dropzone.querySelector('.upload-remove-btn');
    const accepted = opts.accepted || input?.accept || "image/jpeg,image/png";
    const multiple = opts.multiple ?? input?.hasAttribute('multiple');
    const maxFiles = opts.maxFiles || 5;

    if (input) input.accept = accepted;

    // Click apre file picker
    dropzone.addEventListener('click', e => {
        if (e.target === removeBtn) return;
        // Se il click è su un bottone, lascia che il bottone gestisca il click
        if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
        input.click();
    });

    // Drag and drop con classi coerenti
    dropzone.addEventListener('dragover', e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', e => {
        dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            showPreview(input.files);
        }
    });

    // Paste (incolla screenshot)
    window.addEventListener('paste', function (e) {
        if (
            document.activeElement.tagName === 'INPUT' ||
            document.activeElement.tagName === 'TEXTAREA'
        ) return;
        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
        for (let idx in items) {
            const item = items[idx];
            if (item.kind === 'file') {
                const blob = item.getAsFile();
                const dt = new DataTransfer();
                dt.items.add(blob);
                input.files = dt.files;
                showPreview(input.files);
            }
        }
    });

    // Cambia input
    input.addEventListener('change', function () {
        if (input.files && input.files.length) showPreview(input.files);
    });

    // Rimuovi tutto
    if (removeBtn) {
        removeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            input.value = '';
            preview.innerHTML = '';
            removeBtn.style.display = "none";
        });
    }

    function showPreview(files) {
        preview.innerHTML = '';
        if (!files || !files.length) return;
        if (!multiple && files.length > 1) {
            showToast('Puoi caricare solo un file.', 'error');
            return;
        }
        Array.from(files).forEach(file => {
            if (!file.type.match(/^image\/(jpeg|png)$/)) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style = "max-width: 120px; max-height: 90px; border-radius:5px; box-shadow:0 0 4px #0002; margin:3px;";
                preview.appendChild(img);
            }
            reader.readAsDataURL(file);
        });
        if (removeBtn) removeBtn.style.display = "inline-block";
    }

    // Reset programmabile
    return function resetUpload() {
        input.value = '';
        preview.innerHTML = '';
        if (removeBtn) removeBtn.style.display = "none";
    }
}

window.resetFileUploadDropzone = function (dropzoneSelector, opts = {}) {
    const dropzone = typeof dropzoneSelector === 'string' ? document.querySelector(dropzoneSelector) : dropzoneSelector;
    if (!dropzone) return;

    // Rimuovi completamente la dropzone dal DOM e ricreala da zero!
    const parent = dropzone.parentNode;
    const old = dropzone;
    if (!parent) return;

    // Ricostruisci da template iniziale
    const nuovo = document.createElement("div");
    nuovo.className = "dropzone-upload";
    nuovo.id = old.id; // mantieni lo stesso id

    nuovo.innerHTML = `
        <div class="upload-preview" id="commessa-preview"></div>
        <div style="color:#6d6d6d;">Trascina qui, clicca o incolla uno screenshot (CTRL+V)</div>
        <input type="file" name="screenshots" accept="image/jpeg,image/png" id="screenshot-upload">
        <button type="button" class="upload-remove-btn" id="remove-upload-btn" style="display:none;margin:8px auto 0;background:#e74c3c;color:#fff;border:none;border-radius:4px;padding:3px 11px;cursor:pointer;font-size:13px;">Rimuovi</button>
        <div class="upload-info" style="font-size:10px; color:#555; margin-top:6px;">Formati: JPG, PNG. Max 5MB per file.</div>
    `;

    parent.replaceChild(nuovo, old);

    // Reinizializza i listener
    setTimeout(() => {
        window.initFileUploadDropzone(nuovo, opts);
    }, 0);
}

// === MODALE IMMAGINE GLOBALE ===
window.showImageModal = function (src) {
    let modaleImg = document.getElementById("img-modal-global");
    if (!modaleImg) {
        modaleImg = document.createElement("div");
        modaleImg.id = "img-modal-global";
        modaleImg.style.cssText = `
            display: none;
            position: fixed;
            z-index: 99999;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background-color: rgba(0,0,0,0.54);
            justify-content: center;
            align-items: center;
            cursor: zoom-out;
        `;
        modaleImg.innerHTML = `
            <img id="img-modal-content-global"
                src=""
                style="
                    display: block;
                    min-height: 320px;
                    min-width: 220px;
                    max-width: 94vw;
                    max-height: 90vh;
                    height: auto;
                    width: auto;
                    margin: auto;
                    border-radius: 10px;
                    box-shadow: 0 0 14px #000;">
            <span class="close-modal" data-tooltip="Chiudi immagine" style="top:18px;right:34px;color:#fff;text-shadow:0 1px 6px #000, 0 0 1px #fff;">&times;</span>
        `;
        document.body.appendChild(modaleImg);

        // Chiudi modale al click sulla X
        modaleImg.querySelector('.close-modal').onclick = function (e) {
            e.stopPropagation();
            modaleImg.style.display = "none";
        };

        // Chiudi modale al click sullo sfondo (ma non sull'immagine)
        modaleImg.addEventListener("click", function (e) {
            if (e.target === modaleImg) modaleImg.style.display = "none";
        });
        // ESC è gestito dal KeyboardManager globale
    }
    const modalImg = document.getElementById("img-modal-content-global");
    modalImg.src = src;
    modaleImg.style.display = "flex";
};

/**
 * Dropdown custom universale RIUTILIZZANDO le classi di styles.css.
 * container = div.custom-select-box (contiene anche .custom-select-dropdown)
 * opzioni = array di oggetti [{value, label, code?, badge?}]
 * opts: { placeholder, onSelect, valoreIniziale }
 */
window.showCustomDropdown = function (container, opzioni, opts = {}) {
    // Svuota e mostra dropdown
    const dropdown = container.querySelector('.custom-select-dropdown') || (() => {
        const d = document.createElement('div');
        d.className = 'custom-select-dropdown';
        container.appendChild(d);
        return d;
    })();
    dropdown.innerHTML = '';
    dropdown.style.display = 'block';
    container.classList.add('open');

    // Barra di ricerca
    const search = document.createElement('input');
    search.type = 'text';
    search.className = 'custom-dropdown-search'; // <-- tua classe
    search.placeholder = opts.placeholder || 'Cerca...';
    dropdown.appendChild(search);

    // Lista opzioni
    const list = document.createElement('div');
    list.style.maxHeight = '196px';
    list.style.overflowY = 'auto';

    dropdown.appendChild(list);

    // Funzione di rendering opzioni (usa solo le tue classi)
    function renderList(filtro = '') {
        list.innerHTML = '';
        const trova = opzioni.filter(opt =>
        (opt.label?.toLowerCase().includes(filtro.toLowerCase()) ||
            (opt.code && opt.code.toLowerCase().includes(filtro.toLowerCase())))
        );
        trova.forEach(opt => {
            // Usa le tue classi (code+desc se ci sono, altrimenti solo label)
            const el = document.createElement('div');
            el.className = 'custom-select-option custom-dropdown-item';
            el.innerHTML = `
                ${opt.code ? `<span class="custom-select-code">${window.escapeHtml(opt.code)}</span>` : ''}
                <span class="custom-select-desc">${window.escapeHtml(opt.label)}</span>
                ${opt.badge ? `<span class="badge-disciplina">${window.escapeHtml(opt.badge)}</span>` : ''}
            `;
            if (opts.valoreIniziale && opts.valoreIniziale == opt.value) {
                el.classList.add('selected');
            }
            el.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (opts.onSelect) opts.onSelect(opt);
                dropdown.style.display = 'none';
                container.classList.remove('open');
                search.value = '';
            });
            list.appendChild(el);
        });
        if (!trova.length) {
            list.innerHTML = `<div style="padding:8px 14px;color:#a00;">Nessun risultato</div>`;
        }
    }

    // Barra ricerca live
    search.addEventListener('input', function () { renderList(this.value); });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            dropdown.style.display = 'none';
            container.classList.remove('open');
        }
        if (e.key === 'Enter') {
            const first = list.querySelector('.custom-select-option');
            if (first) first.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
    });

    renderList('');

    setTimeout(() => search.focus(), 40);

    // Chiudi fuori
    document.addEventListener('mousedown', function handleClose(e) {
        if (!dropdown.contains(e.target) && !container.contains(e.target)) {
            dropdown.style.display = 'none';
            container.classList.remove('open');
            document.removeEventListener('mousedown', handleClose);
        }
    });
};

// === FORMATTAZIONE DATE GLOBALE (formato italiano dd/mm/yyyy) ===
// Versione migliorata che supporta più formati di input
window.formatDate = function (dateValue) {
    if (!dateValue && dateValue !== 0) return '';

    // Supporta Date objects
    if (dateValue instanceof Date) {
        if (Number.isNaN(dateValue.getTime())) return '';
        const day = String(dateValue.getDate()).padStart(2, '0');
        const month = String(dateValue.getMonth() + 1).padStart(2, '0');
        const year = dateValue.getFullYear();
        return `${day}/${month}/${year}`;
    }

    // Supporta oggetti con year/month/day
    if (typeof dateValue === 'object' && dateValue !== null) {
        if (dateValue.date && typeof dateValue.date === 'object') {
            const { year, month, day } = dateValue.date;
            if (year && month && day) {
                return `${String(day).padStart(2, '0')}/${String(month).padStart(2, '0')}/${year}`;
            }
        }
        if ('year' in dateValue && 'month' in dateValue && 'day' in dateValue) {
            const { year, month, day } = dateValue;
            return `${String(day).padStart(2, '0')}/${String(month).padStart(2, '0')}/${year}`;
        }
    }

    // Supporta numeri (timestamp)
    if (typeof dateValue === 'number' && Number.isFinite(dateValue)) {
        const date = new Date(dateValue);
        if (!Number.isNaN(date.getTime())) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }
    }

    // Supporta stringhe (vari formati)
    if (typeof dateValue === 'string') {
        const str = dateValue.trim();
        if (!str) return '';

        // Se è già in formato ISO (YYYY-MM-DD)
        const isoMatch = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (isoMatch) {
            const [, year, month, day] = isoMatch;
            return `${day}/${month}/${year}`;
        }

        // Formato italiano DD-MM-YYYY o DD/MM/YYYY
        const italianMatch = str.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/);
        if (italianMatch) {
            const [, day, month, year] = italianMatch;
            // Verifica validità: mese tra 1-12, giorno tra 1-31
            const m = parseInt(month, 10);
            const d = parseInt(day, 10);
            if (m >= 1 && m <= 12 && d >= 1 && d <= 31) {
                return `${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${year}`;
            }
        }

        // Prova parsing con Date
        const date = new Date(str.replace(' ', 'T'));
        if (!isNaN(date.getTime())) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }
    }

    return '';
};

// Formattazione data + ora (formato italiano)
window.formatDateTime = function (dateValue) {
    if (!dateValue && dateValue !== 0) return '';

    let date;

    // Supporta Date objects
    if (dateValue instanceof Date) {
        date = dateValue;
    }
    // Supporta oggetti
    else if (typeof dateValue === 'object' && dateValue !== null) {
        // Prova a estrarre da oggetto date
        if (dateValue.date && typeof dateValue.date === 'object') {
            const { year, month, day, hour = 0, minute = 0 } = dateValue.date;
            if (year && month && day) {
                date = new Date(year, month - 1, day, hour, minute);
            }
        } else {
            date = new Date(dateValue);
        }
    }
    // Supporta numeri (timestamp)
    else if (typeof dateValue === 'number' && Number.isFinite(dateValue)) {
        date = new Date(dateValue);
    }
    // Supporta stringhe
    else if (typeof dateValue === 'string') {
        const str = dateValue.trim();
        if (!str) return '';
        date = new Date(str.replace(' ', 'T'));
    }

    if (!date || Number.isNaN(date.getTime())) {
        return window.formatDate(dateValue) || '';
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}/${month}/${year} ${hours}:${minutes}`;
};

// === STAMPA / ESPORTA CENTRALE (function-bar) ===
window.handleExportOrPrint = function () {
    let page = '';
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('page')) {
        page = urlParams.get('page');
    } else {
        page = (window.location.pathname.split('/').pop() || '').replace('.php', '');
    }

    if (document.querySelector('.modal[style*="display: block"]')) {
        showToast('Chiudi i modali prima di stampare o esportare.', 'info');
        return;
    }

    if (page === 'protocollo_email') {
        const table = document.getElementById('protocolTable');
        if (table) {
            exportTableToExcel('protocolTable', getExportFileName());
            return;
        } else {
            showToast("Tabella non trovata!", "error");
            return;
        }
    }

    else {
        showToast("Funzione stampa/esporta non configurata per questa pagina.", "info");
    }
};

window.exportTableToExcel = function (tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    // Solo righe effettivamente mostrate (visibili)
    const rows = Array.from(table.querySelectorAll('tbody tr')).filter(tr => tr.offsetParent !== null);
    if (rows.length === 0) {
        showToast('Nessun dato da esportare!', 'info');
        return;
    }

    // Intestazioni, saltando la colonna "Azioni"
    const headers = Array.from(table.querySelectorAll('thead th'))
        .filter(th => th.textContent.trim().toLowerCase() !== 'azioni')
        .map(th => th.textContent.trim());

    // Dati righe (senza la colonna Azioni, che è la prima)
    const data = rows.map(tr =>
        Array.from(tr.children)
            .filter((td, i) => i !== 0)
            .map(td => td.textContent.trim())
    );

    // Intestazione evidenziata (grassetto, giallo chiaro)
    let xls = `<table>
    <thead>
    <tr style="background:#fff5a3;font-weight:bold;">${headers.map(h => `<th style="background:#fff5a3;font-weight:bold;">${h}</th>`).join('')}</tr>
    </thead>
    <tbody>`;
    data.forEach(row => {
        xls += `<tr>${row.map(cell => `<td>${cell.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</td>`).join('')}</tr>`;
    });
    xls += `</tbody></table>`;

    // Nome file dinamico SE NON passato (e MAI doppio .xls)
    if (!filename) {
        const now = new Date();
        const pad = n => n < 10 ? '0' + n : n;
        filename = `archivio_protocollo_email_${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}.xls`;
    }
    if (!/\.xls$/i.test(filename)) filename += '.xls';

    // Genera blob e scarica come file Excel (.xls)
    const blob = new Blob([`
        <html xmlns:o="urn:schemas-microsoft-com:office:office"
              xmlns:x="urn:schemas-microsoft-com:office:excel"
              xmlns="http://www.w3.org/TR/REC-html40">
        <head><!--[if gte mso 9]>
        <xml>
        <x:ExcelWorkbook>
        <x:ExcelWorksheets>
            <x:ExcelWorksheet>
            <x:Name>Archivio Protocollo</x:Name>
            <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
            </x:ExcelWorksheet>
        </x:ExcelWorksheets>
        </x:ExcelWorkbook>
        </xml><![endif]-->
        </head><body>${xls}</body></html>
    `], { type: "application/vnd.ms-excel" });

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }, 200);
}

// Funzione per nome file con data e ora
function getExportFileName() {
    const now = new Date();
    const pad = n => n < 10 ? '0' + n : n;
    const y = now.getFullYear();
    const m = pad(now.getMonth() + 1);
    const d = pad(now.getDate());
    const h = pad(now.getHours());
    const min = pad(now.getMinutes());
    const s = pad(now.getSeconds());
    return `archivio_protocollo_email_${y}${m}${d}_${h}${min}${s}.xls`;
}


// === STATI CENTRALIZZATI (segnalazioni, gare, tasks) ===
window.STATI_MAP = {
    1: "Aperta",
    2: "In corso",
    3: "Sospesa",
    4: "Rifiutata",
    5: "Chiusa"
};

window.STATI_MAP_REVERSE = {
    "Aperta": 1,
    "In corso": 2,
    "Sospesa": 3,
    "Rifiutata": 4,
    "Chiusa": 5
};

// === TOGGLE SUBTASKS GLOBALE ===
window.toggleSubtasks = function (parentId, context = 'table') {
    const storageKey = `${context}_subtasks_collapsed_${parentId}`;
    const subtasks = document.querySelectorAll(
        context === 'gantt' ? `[data-parent-task-id="${parentId}"]` :
            context === 'kanban' ? `.kanban-subtask[data-parent-id="${parentId}"]` :
                `tr[data-parent-task-id="${parentId}"]`
    );

    if (!subtasks.length) return;

    const isHidden = subtasks[0].style.display === 'none' || subtasks[0].classList.contains('hidden');

    subtasks.forEach(row => {
        if (context === 'kanban') {
            isHidden ? row.classList.remove('hidden') : row.classList.add('hidden');
        } else {
            row.style.display = isHidden ? '' : 'none';
        }
    });

    const toggleIcon = document.querySelector(
        context === 'gantt' ? `[data-parent-id="${parentId}"].gv-toggle-icon` :
            context === 'kanban' ? `.kanban-subtasks-toggle[data-parent-id="${parentId}"]` :
                `td[data-task-id="${parentId}"] .subtask-toggle-icon`
    );

    if (toggleIcon) {
        toggleIcon.textContent = isHidden ? '▼' : '▶';
        if (context === 'table') toggleIcon.style.transform = isHidden ? 'rotate(0deg)' : 'rotate(-90deg)';
        if (context === 'kanban') toggleIcon.classList.toggle('collapsed', !isHidden);
    }

    localStorage.setItem(storageKey, (!isHidden).toString());
};

window.restoreSubtaskStates = function (context = 'table') {
    if (context === 'gantt') {
        document.querySelectorAll('.gv-toggle-icon[data-parent-id]').forEach(icon => {
            const parentId = icon.getAttribute('data-parent-id');
            if (!parentId) return;

            const storageKey = `gantt_subtasks_collapsed_${parentId}`;
            const shouldBeCollapsed = localStorage.getItem(storageKey) === 'true';

            if (shouldBeCollapsed) {
                const subtasks = document.querySelectorAll(`[data-parent-task-id="${parentId}"]`);
                icon.textContent = '▶';
                icon.setAttribute('data-toggle-state', 'collapsed');
                subtasks.forEach(row => row.style.display = 'none');
            }
        });
    } else if (context === 'kanban') {
        document.querySelectorAll('.kanban-subtasks-toggle[data-parent-id]').forEach(icon => {
            const parentId = icon.getAttribute('data-parent-id');
            if (!parentId) return;

            const storageKey = `kanban_subtasks_collapsed_${parentId}`;
            const shouldBeCollapsed = localStorage.getItem(storageKey) === 'true';

            if (shouldBeCollapsed) {
                const subtasks = document.querySelectorAll(`.kanban-subtask[data-parent-id="${parentId}"]`);
                icon.classList.add('collapsed');
                subtasks.forEach(el => el.classList.add('hidden'));
            }
        });
    } else {
        document.querySelectorAll('td[data-has-subtasks="true"]').forEach(cell => {
            const taskId = cell.getAttribute('data-task-id');
            if (!taskId) return;

            const storageKey = `table_subtasks_collapsed_${taskId}`;
            const shouldBeCollapsed = localStorage.getItem(storageKey) === 'true';

            if (shouldBeCollapsed) {
                const subtasks = document.querySelectorAll(`tr[data-parent-task-id="${taskId}"]`);
                const icon = cell.querySelector('.subtask-toggle-icon');
                if (icon) icon.style.transform = 'rotate(-90deg)';
                subtasks.forEach(row => row.style.display = 'none');
            }
        });
    }
};

// === USER MAPPING GLOBALE ===
window.__USERS_MAP__ = window.__USERS_MAP__ || {};

window.getUserNameById = function (userId) {
    if (!userId) return '—';
    const uid = String(userId);
    if (isNaN(userId) && userId.length > 2) return userId;
    if (window.__USERS_MAP__[uid]) return window.__USERS_MAP__[uid];
    const currentId = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
    if (uid === currentId) {
        const name = window.CURRENT_USER?.nome_completo || window.CURRENT_USER?.Nominativo || 'Tu';
        window.__USERS_MAP__[uid] = name;
        return name;
    }
    return '—';
};

// Utility comuni per moduli sicurezza (VVCS, VCS, ecc.)
window.sicCommon = (function () {
    function esc(s) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(String(s ?? ''));
        return (s ?? '').toString().replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function renderDocList(containerEl, items, typeLabel) {
        containerEl.innerHTML = '';
        if (!items || !items.length) {
            containerEl.innerHTML = '<div style="padding:12px;border:1px solid #eaeaea;border-radius:10px;color:#64748b;">Nessun verbale</div>';
            return;
        }
        items.forEach(it => {
            const row = document.createElement('div');
            row.className = 'doc-card';
            row.style.cssText = 'display:flex;align-items:center;gap:10px;border:1px solid #e4e4e4;border-radius:10px;padding:12px;background:#fff;';
            const when = esc(it.updated_at || it.created_at || '');
            row.innerHTML = `
        <div style="font-weight:700;min-width:60px;text-align:center;">${esc(typeLabel)}</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(it.titolo || 'Verbale')}</div>
          <div style="color:#64748b;font-size:.92em;">${when}</div>
        </div>
        <button class="action-icon btn-pdf" data-id="${esc(it.id)}" data-tooltip="Esporta PDF" aria-label="Esporta PDF" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/pdf.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-open" data-id="${esc(it.id)}" data-tooltip="Apri / Modifica" aria-label="Modifica" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/edit.png" alt="" width="16" height="16">
        </button>
        <button class="action-icon btn-del" data-id="${esc(it.id)}" data-tooltip="Elimina" aria-label="Elimina" style="vertical-align:middle;padding:0;">
          <img src="/assets/icons/delete.png" alt="" width="16" height="16">
        </button>`;
            containerEl.appendChild(row);
        });
    }

    return { esc, renderDocList };
})();

// === PRINT LAYOUT MANAGER ===
(function initPrintLayoutManager() {
    const layouts = new Map();
    let pendingPrepare = null;
    const portalId = 'print-layout-portal';
    const BODY_CLASS = 'print-layout-active';

    function normalizeElement(target) {
        if (!target) return null;
        if (target instanceof Element) return target;
        if (typeof target === 'string') return document.querySelector(target);
        return null;
    }

    async function runHook(fn) {
        if (typeof fn === 'function') {
            await fn();
        }
    }

    function ensurePortal() {
        let portal = document.getElementById(portalId);
        if (!portal) {
            portal = document.createElement('div');
            portal.id = portalId;
            portal.setAttribute('data-print-root', 'true');
            portal.setAttribute('data-print-portal', 'true');
            portal.style.display = 'none';
            document.body.insertBefore(portal, document.body.firstChild || null);
        } else if (portal.previousSibling !== null) {
            document.body.insertBefore(portal, document.body.firstChild || null);
        }
        return portal;
    }

    function markContainer(config) {
        const el = normalizeElement(config?.container);
        if (el) {
            el.setAttribute('data-print-root', 'true');
        }
    }

    window.registerPrintLayout = function registerPrintLayout(key, config = {}) {
        if (!key) return;
        layouts.set(key, { ...config });
        markContainer(config);
    };

    window.unregisterPrintLayout = function unregisterPrintLayout(key) {
        layouts.delete(key);
    };

    async function prepareAllLayouts() {
        for (const config of layouts.values()) {
            await runHook(config.before);
            markContainer(config);
        }
    }

    function mountPortal() {
        const portal = ensurePortal();
        portal.innerHTML = '';

        layouts.forEach((config) => {
            const source = normalizeElement(config?.container);
            if (!source) return;
            const clone = source.cloneNode(true);
            clone.setAttribute('data-print-root', 'true');
            portal.appendChild(clone);
        });

        portal.style.display = layouts.size ? 'block' : 'none';
        if (layouts.size) {
            document.body.classList.add(BODY_CLASS);
        }
    }

    function clearPortal() {
        const portal = document.getElementById(portalId);
        if (portal) {
            portal.innerHTML = '';
            portal.style.display = 'none';
        }
        document.body.classList.remove(BODY_CLASS);
    }

    window.requestPrintLayout = async function requestPrintLayout() {
        if (!layouts.size) return;
        if (pendingPrepare) return pendingPrepare;
        pendingPrepare = (async () => {
            await prepareAllLayouts();
            mountPortal();
        })();
        try {
            await pendingPrepare;
        } finally {
            pendingPrepare = null;
        }
    };

    window.addEventListener('beforeprint', () => {
        if (!layouts.size) return;
        window.requestPrintLayout()?.catch?.((err) => {
            console.error('Errore preparazione stampa:', err);
        });
    });

    window.addEventListener('afterprint', () => {
        if (!layouts.size) return;
        for (const config of layouts.values()) {
            if (typeof config.after === 'function') {
                try {
                    config.after();
                } catch (err) {
                    console.error('Errore ripristino layout stampa:', err);
                }
            }
        }
        clearPortal();
    });

    const printMQ = typeof window.matchMedia === 'function' ? window.matchMedia('print') : null;
    if (printMQ && printMQ.addEventListener) {
        printMQ.addEventListener('change', (m) => {
            if (m.matches) {
                window.requestPrintLayout()?.catch((err) => console.error('Errore preparazione stampa:', err));
            } else {
                clearPortal();
            }
        });
    }
})();

/**
 * Formattazione monetaria in tempo reale per input[data-money] e input.money
 * Formato IT: separatore migliaia = punto (.), separatore decimali = virgola (,)
 * Sempre 2 decimali, formattazione durante la digitazione con gestione corretta del cursore
 */
(function initMoneyFormatting() {
    'use strict';

    /**
     * Estrae solo le cifre numeriche da una stringa (mantiene il segno - opzionale)
     * @param {string} str - Stringa da processare
     * @returns {string} - Stringa con solo cifre (e opzionalmente - all'inizio)
     */
    function extractDigits(str) {
        if (!str) return '';
        const isNegative = str.trim().startsWith('-');
        const digits = str.replace(/[^\d]/g, '');
        return isNegative && digits ? '-' + digits : digits;
    }

    /**
     * Formatta un numero come stringa monetaria IT (es: 123456 -> "1.234,56")
     * Separatore migliaia = punto (.), separatore decimali = virgola (,)
     * Sempre 2 decimali obbligatori
     * @param {string} digits - Stringa di sole cifre (può iniziare con -)
     * @returns {string} - Stringa formattata con 2 decimali
     */
    function formatMoneyValue(digits) {
        if (!digits) return '';

        const isNegative = digits.startsWith('-');
        const cleanDigits = isNegative ? digits.substring(1) : digits;

        if (!cleanDigits) return isNegative ? '-' : '';

        // Gestisci decimali: le ultime 2 cifre sono i centesimi
        let integerPart = cleanDigits;
        let decimalPart = '00';

        if (cleanDigits.length >= 2) {
            // Le ultime 2 cifre sono i decimali
            decimalPart = cleanDigits.substring(cleanDigits.length - 2);
            integerPart = cleanDigits.substring(0, cleanDigits.length - 2) || '0';
        } else if (cleanDigits.length === 1) {
            // Una sola cifra: diventa "0,X0"
            decimalPart = '0' + cleanDigits;
            integerPart = '0';
        }

        // Formatta parte intera con separatore migliaia (punto)
        const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        // Combina: parte intera + virgola + 2 decimali
        const result = formattedInteger + ',' + decimalPart;
        return isNegative ? '-' + result : result;
    }

    /**
     * Calcola la posizione del cursore dopo la formattazione
     * Strategia: conta quante cifre numeriche ci sono PRIMA del cursore originale,
     * poi trova quella stessa posizione nella stringa formattata
     * @param {string} oldValue - Valore prima della formattazione
     * @param {string} newValue - Valore dopo la formattazione
     * @param {number} oldCursorPos - Posizione del cursore prima della formattazione
     * @returns {number} - Nuova posizione del cursore
     */
    function calculateNewCursorPosition(oldValue, newValue, oldCursorPos) {
        if (oldCursorPos <= 0) return 0;

        // Estrai solo cifre dal valore originale fino alla posizione del cursore
        const beforeCursor = oldValue.substring(0, oldCursorPos);
        const digitsBeforeCursor = extractDigits(beforeCursor).replace(/^-/, '');
        const digitCount = digitsBeforeCursor.length;

        if (digitCount === 0) {
            // Nessuna cifra prima: posiziona all'inizio (dopo il segno - se presente)
            return newValue.startsWith('-') ? 1 : 0;
        }

        // Conta quante cifre ci sono nella nuova stringa formattata
        const newDigits = extractDigits(newValue).replace(/^-/, '');

        // Se abbiamo meno cifre di quelle che ci aspettavamo, usa la fine
        if (digitCount >= newDigits.length) {
            return newValue.length;
        }

        // Trova la posizione nella stringa formattata corrispondente alla N-esima cifra
        let digitIndex = 0;
        for (let i = 0; i < newValue.length; i++) {
            const char = newValue[i];
            if (char >= '0' && char <= '9') {
                digitIndex++;
                if (digitIndex === digitCount) {
                    // Trovata la posizione: ritorna dopo questa cifra
                    return i + 1;
                }
            }
        }

        // Fallback: fine della stringa
        return newValue.length;
    }

    /**
     * Applica la formattazione monetaria a un input
     * @param {HTMLInputElement} input - Elemento input da formattare
     */
    function setupMoneyInput(input) {
        if (!input || input.tagName !== 'INPUT' || input.dataset.moneySetup) {
            return; // Già configurato o non valido
        }

        input.dataset.moneySetup = 'true';

        let isComposing = false;

        // Gestione IME composition (input method editor per lingue asiatiche)
        input.addEventListener('compositionstart', function () {
            isComposing = true;
        });

        input.addEventListener('compositionend', function () {
            isComposing = false;
            // Trigger formattazione dopo la fine della composizione
            const event = new Event('input', { bubbles: true });
            input.dispatchEvent(event);
        });

        // Evento input: formattazione in tempo reale durante la digitazione
        input.addEventListener('input', function (e) {
            if (isComposing) return; // Ignora durante IME composition

            const inputEl = e.target;
            const oldValue = inputEl.value;
            const oldCursorPos = inputEl.selectionStart || 0;

            // Estrai solo cifre (mantenendo il segno - opzionale)
            const digits = extractDigits(oldValue);

            // Se non ci sono cifre, lascia vuoto
            if (!digits || digits === '-') {
                inputEl.value = '';
                inputEl.setSelectionRange(0, 0);
                return;
            }

            // Formatta il valore
            const formatted = formatMoneyValue(digits);

            // Aggiorna il valore solo se è cambiato
            if (formatted !== oldValue) {
                inputEl.value = formatted;

                // Calcola e riposiziona il cursore
                const newCursorPos = calculateNewCursorPosition(oldValue, formatted, oldCursorPos);
                inputEl.setSelectionRange(newCursorPos, newCursorPos);
            }
        });

        // Evento blur: normalizzazione finale di sicurezza
        input.addEventListener('blur', function (e) {
            const inputEl = e.target;
            const digits = extractDigits(inputEl.value);

            if (!digits || digits === '-') {
                inputEl.value = '';
                return;
            }

            const formatted = formatMoneyValue(digits);
            inputEl.value = formatted;
        });
    }

    /**
     * Inizializza la formattazione monetaria su tutti gli input monetari presenti nel DOM
     * Riconosce: input[data-money], input.money, input.importo-money, input.importo-categoria, input.importo-suddivisione
     */
    function initMoneyInputs() {
        const moneyInputs = document.querySelectorAll(
            'input[data-money], input.money, input.importo-money, input.importo-categoria, input.importo-suddivisione'
        );
        moneyInputs.forEach(setupMoneyInput);
    }

    // Inizializza al caricamento del DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMoneyInputs);
    } else {
        initMoneyInputs();
    }

    // Inizializza anche per elementi aggiunti dinamicamente (MutationObserver opzionale)
    // Nota: per semplicità, si può anche chiamare manualmente setupMoneyInput su nuovi elementi
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { // Element node
                        if (node.tagName === 'INPUT' && (
                            node.hasAttribute('data-money') ||
                            node.classList.contains('money') ||
                            node.classList.contains('importo-money') ||
                            node.classList.contains('importo-categoria') ||
                            node.classList.contains('importo-suddivisione')
                        )) {
                            setupMoneyInput(node);
                        }
                        // Cerca anche nei discendenti
                        const descendants = node.querySelectorAll && node.querySelectorAll(
                            'input[data-money], input.money, input.importo-money, input.importo-categoria, input.importo-suddivisione'
                        );
                        if (descendants) {
                            descendants.forEach(setupMoneyInput);
                        }
                    }
                });
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Esponi le funzioni per uso manuale se necessario
    window.setupMoneyInput = setupMoneyInput;
    window.initMoneyInputs = initMoneyInputs;

    // Forza reinizializzazione dopo un breve delay per catturare elementi aggiunti dinamicamente
    // Utile per pagine che aggiungono elementi dopo DOMContentLoaded
    setTimeout(function () {
        initMoneyInputs();
    }, 100);

    setTimeout(function () {
        initMoneyInputs();
    }, 500);
})();

// =============================================================================
// KEYBOARD MANAGER GLOBALE - Gestione centralizzata ESC e ENTER
// =============================================================================
// Opt-out attributes:
//   data-esc-close="0"        - Il modale non si chiude con ESC
//   data-no-enter-submit="1"  - Il form/elemento non invia con ENTER
// =============================================================================
(function initKeyboardManager() {
    'use strict';

    // Flag debug (impostare a true solo per sviluppo)
    const DEBUG = false;
    const log = DEBUG ? console.log.bind(console, '[KeyboardManager]') : () => { };

    /**
     * Trova il modale attivo più in alto (visibile e in foreground)
     * Supporta vari pattern di modali del progetto:
     * - .modal.show (Bootstrap-style)
     * - .modal[style*="display: block"] o display:block
     * - .custom-confirm-overlay (modali custom showConfirm/showPrompt/showRenameModal)
     * - .gare-modal-overlay (modali gare)
     * - .cv-modal-overlay (modali CV)
     * - #media-viewer-modal (viewer media)
     * - Altri overlay con z-index alto
     */
    function getActiveModal() {
        // Selettori per tutti i tipi di modali del progetto
        const modalSelectors = [
            '.custom-confirm-overlay',           // showConfirm, showPrompt, showRenameModal, showPermissionModal
            '.modal.show',                       // Bootstrap-style .show
            '.modal[style*="display: block"]',   // inline style display: block
            '.modal[style*="display:block"]',    // senza spazio
            '.gare-modal-overlay',               // modali gare
            '.cv-modal-overlay',                 // modali CV
            '.sic-modal',                        // modali sicurezza
            '#media-viewer-modal',               // viewer media
            '#img-modal-global',                 // showImageModal (wrapper)
            '#global-drawer-panel',               // Global DRAWER
            '[role="dialog"]:not([aria-hidden="true"])', // ARIA dialog
        ];

        const allModals = [];

        modalSelectors.forEach(selector => {
            try {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    // Verifica che sia effettivamente visibile
                    const style = window.getComputedStyle(el);
                    const isVisible = style.display !== 'none' &&
                        style.visibility !== 'hidden' &&
                        parseFloat(style.opacity) > 0;

                    if (isVisible) {
                        const zIndex = parseInt(style.zIndex) || 0;
                        allModals.push({ element: el, zIndex: zIndex });
                    }
                });
            } catch (e) {
                // Ignora selettori non validi
            }
        });

        // Anche i modali con classe .modal che hanno display != none (per quelli settati via JS)
        document.querySelectorAll('.modal').forEach(el => {
            const style = window.getComputedStyle(el);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                const zIndex = parseInt(style.zIndex) || 0;
                // Evita duplicati
                if (!allModals.find(m => m.element === el)) {
                    allModals.push({ element: el, zIndex: zIndex });
                }
            }
        });

        if (allModals.length === 0) return null;

        // Ordina per z-index decrescente, poi per ordine DOM (ultimo = più in alto)
        allModals.sort((a, b) => {
            if (b.zIndex !== a.zIndex) return b.zIndex - a.zIndex;
            // Se stesso z-index, usa ordine DOM (compareDocumentPosition)
            const pos = a.element.compareDocumentPosition(b.element);
            return pos & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
        });

        log('Modali attivi trovati:', allModals.length, 'Top:', allModals[0]?.element);
        return allModals[0]?.element || null;
    }

    /**
     * Chiude un modale usando il metodo appropriato per il suo tipo
     */
    function closeModal(modal) {
        if (!modal) return false;

        log('Tentativo chiusura modale:', modal.id || modal.className);

        // 1. Custom confirm overlay (showConfirm, showPrompt, ecc.) - rimuovi dal DOM
        if (modal.classList.contains('custom-confirm-overlay')) {
            modal.remove();
            log('Rimosso custom-confirm-overlay');
            return true;
        }

        // 2. Cerca bottone X o close dentro il modale
        const closeBtn = modal.querySelector('.close, .close-modal, [data-dismiss="modal"], .btn-close, .global-drawer-close');
        if (closeBtn) {
            closeBtn.click();
            log('Click su bottone close');
            return true;
        }

        // 3. Special handling for GlobalDrawer
        if (modal.id === 'global-drawer-panel' && window.GlobalDrawer) {
            window.GlobalDrawer.close();
            log('Chiamato GlobalDrawer.close()');
            return true;
        }

        // 4. Prova a chiamare closeModal() se definita globalmente e il modale ha un ID
        if (modal.id && typeof window.closeModal === 'function') {
            try {
                window.closeModal(modal.id);
                log('Chiamato window.closeModal()');
                return true;
            } catch (e) { }
        }

        // 4. Toggle classe .show (Bootstrap-style)
        if (modal.classList.contains('show')) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            log('Rimosso classe .show');
            return true;
        }

        // 5. Nascondi via style
        if (window.getComputedStyle(modal).display !== 'none') {
            modal.style.display = 'none';
            log('Nascosto via style.display = none');
            return true;
        }

        return false;
    }

    /**
     * Gestisce pressione ESC
     */
    function handleEscape(e) {
        const modal = getActiveModal();

        if (!modal) {
            log('Nessun modale attivo, ESC ignorato');
            return;
        }

        // Controlla opt-out: data-esc-close="0" o "false"
        const escClose = modal.getAttribute('data-esc-close');
        if (escClose === '0' || escClose === 'false') {
            log('Modale ha data-esc-close="0", ESC ignorato');
            return;
        }

        // Previeni comportamento default e chiudi
        e.preventDefault();
        e.stopPropagation();

        if (closeModal(modal)) {
            log('Modale chiuso con ESC');
        }
    }

    /**
     * Gestisce pressione ENTER
     */
    function handleEnter(e) {
        const activeElement = document.activeElement;

        // 1. Non inviare se in TEXTAREA
        if (activeElement && activeElement.tagName === 'TEXTAREA') {
            log('ENTER in TEXTAREA, ignorato');
            return;
        }

        // 2. Non inviare se in contenteditable
        if (activeElement && activeElement.isContentEditable) {
            log('ENTER in contenteditable, ignorato');
            return;
        }

        // 3. Non inviare se Shift/Ctrl/Cmd + Enter
        if (e.shiftKey || e.ctrlKey || e.metaKey) {
            log('ENTER con modificatore, ignorato');
            return;
        }

        // 4. Controlla opt-out sull'elemento attivo
        if (activeElement && activeElement.getAttribute('data-no-enter-submit') === '1') {
            log('Elemento ha data-no-enter-submit="1", ignorato');
            return;
        }

        // 5. Trova il form associato
        let form = null;

        // Se l'elemento attivo è dentro un form, usa quello
        if (activeElement) {
            form = activeElement.closest('form');
        }

        // Se non c'è form dall'elemento attivo, cerca nel modale attivo
        if (!form) {
            const modal = getActiveModal();
            if (modal) {
                form = modal.querySelector('form');
            }
        }

        if (!form) {
            log('Nessun form trovato, ENTER ignorato');
            return;
        }

        // 6. Controlla opt-out sul form
        if (form.getAttribute('data-no-enter-submit') === '1') {
            log('Form ha data-no-enter-submit="1", ignorato');
            return;
        }

        // 7. Cerca un submit button abilitato nel form
        const submitBtn = form.querySelector('button[type="submit"]:not(:disabled), input[type="submit"]:not(:disabled)');

        if (submitBtn) {
            log('Trovato submit button, click');
            e.preventDefault();
            submitBtn.click();
            return;
        }

        // 8. Se non c'è submit button ma il form è valido, invia
        // Usa requestSubmit se disponibile (rispetta validazione HTML5)
        if (typeof form.requestSubmit === 'function') {
            log('Invio form con requestSubmit()');
            e.preventDefault();
            form.requestSubmit();
        } else {
            log('Invio form con submit()');
            e.preventDefault();
            form.submit();
        }
    }

    /**
     * Handler principale keydown a livello document
     */
    function handleKeyDown(e) {
        // Ignora se il target è un input di ricerca/filtro generico che potrebbe gestire ESC internamente
        // (questo permette ai dropdown custom di gestire ESC prima del manager globale)

        if (e.key === 'Escape') {
            handleEscape(e);
        } else if (e.key === 'Enter') {
            handleEnter(e);
        }
    }

    // Registra l'handler globale
    // Usa capture=false (bubbling) così gli handler specifici possono fare stopPropagation se necessario
    document.addEventListener('keydown', handleKeyDown, false);

    log('KeyboardManager inizializzato');

    // Esponi funzioni per uso programmatico se necessario
    window.KeyboardManager = {
        getActiveModal: getActiveModal,
        closeActiveModal: function () {
            const modal = getActiveModal();
            return modal ? closeModal(modal) : false;
        }
    };

})();

// ============================================
// AUTO-RESIZE TEXTAREA
// ============================================
// I textarea hanno la stessa altezza iniziale degli input e si espandono automaticamente
(function () {
    /**
     * Funzione per auto-resize di un textarea
     */
    function autoResizeTextarea(textarea) {
        // Skip se ha l'attributo data-no-auto-resize
        if (textarea.hasAttribute('data-no-auto-resize')) {
            return;
        }

        // Reset height per calcolare correttamente scrollHeight
        textarea.style.height = 'auto';
        // Imposta l'altezza basata sul contenuto, con un minimo di 38px (come gli input)
        const newHeight = Math.max(38, textarea.scrollHeight);
        textarea.style.height = newHeight + 'px';
    }

    /**
     * Inizializza auto-resize per un textarea
     */
    function setupTextarea(textarea) {
        if (textarea.hasAttribute('data-auto-resize-setup')) {
            return; // Già configurato
        }

        textarea.setAttribute('data-auto-resize-setup', '1');

        // Inizializza l'altezza
        autoResizeTextarea(textarea);

        // Aggiungi listener per input (quando si digita)
        textarea.addEventListener('input', function () {
            autoResizeTextarea(this);
        });
    }

    /**
     * Inizializza auto-resize per tutti i textarea
     */
    function initAutoResizeTextareas() {
        // Seleziona tutti i textarea (escludi quelli con data-no-auto-resize)
        const textareas = document.querySelectorAll('textarea:not([data-no-auto-resize])');
        textareas.forEach(setupTextarea);
    }

    // Inizializza al caricamento della pagina
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAutoResizeTextareas);
    } else {
        initAutoResizeTextareas();
    }

    // Re-inizializza quando vengono aggiunti nuovi textarea dinamicamente
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.addedNodes.length) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { // Element node
                        // Se il nodo stesso è un textarea
                        if (node.tagName === 'TEXTAREA') {
                            setupTextarea(node);
                        }
                        // Cerca textarea dentro il nodo
                        const newTextareas = node.querySelectorAll ? node.querySelectorAll('textarea:not([data-no-auto-resize])') : [];
                        newTextareas.forEach(setupTextarea);
                    }
                });
            }
        });
    });

    // Osserva l'intero documento per nuovi textarea
    if (document.body) {
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();

// === MODALI GLOBALI PER AGGIUNTA AZIENDA/CONTATTO ===
// Riutilizzabili in tutte le pagine (MOM, Protocollo Email, Commesse, ecc.)

/**
 * Mostra modale per aggiungere una nuova azienda/società
 * @param {string} defaultValue - Valore di default per ragione sociale
 * @param {function} onSuccess - Callback chiamato dopo inserimento: onSuccess(nuovaAzienda)
 */
window.showAddCompanyModal = function (defaultValue, onSuccess) {
    const modalId = 'global-modal-nuovo-destinatario';
    let modal = document.getElementById(modalId);

    // Crea modale se non esiste
    if (!modal) {
        modal = document.createElement('div');
        modal.id = modalId;
        modal.className = 'modal modal-small';
        modal.innerHTML = `
            <div class="modal-content">
                <span class="close-modal" onclick="window.toggleModal('${modalId}', 'close')">&times;</span>
                <h3>Nuova società</h3>
                <form id="global-nuovo-destinatario-form" autocomplete="off">
                    <div class="modal-form-grid">
                        <div>
                            <label for="global-dest-ragione">Ragione sociale*:</label>
                            <input type="text" id="global-dest-ragione" name="ragionesociale" required>
                        </div>
                        <div>
                            <label for="global-dest-piva">Partita IVA:</label>
                            <input type="text" id="global-dest-piva" name="partitaiva">
                        </div>
                        <div>
                            <label for="global-dest-citta">Città:</label>
                            <input type="text" id="global-dest-citta" name="citta">
                        </div>
                        <div>
                            <label for="global-dest-email">Email:</label>
                            <input type="email" id="global-dest-email" name="email">
                        </div>
                        <div>
                            <label for="global-dest-tel">Telefono:</label>
                            <input type="text" id="global-dest-tel" name="telefono">
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" id="global-btn-cancella-destinatario" class="button">Annulla</button>
                        <button type="submit" id="global-btn-salva-destinatario" class="button">Salva</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Mostra modale e popola campo
    modal.style.display = 'block';
    document.getElementById('global-dest-ragione').value = defaultValue || '';
    document.getElementById('global-dest-piva').value = '';
    document.getElementById('global-dest-citta').value = '';
    document.getElementById('global-dest-email').value = '';
    document.getElementById('global-dest-tel').value = '';

    // Focus sul campo ragione sociale
    setTimeout(() => document.getElementById('global-dest-ragione').focus(), 100);

    // Handler form submit
    const form = document.getElementById('global-nuovo-destinatario-form');
    form.onsubmit = function (ev) {
        ev.preventDefault();
        const ragione = document.getElementById('global-dest-ragione').value.trim();
        if (!ragione) {
            showToast("Inserire la ragione sociale.", "error");
            return;
        }
        const dati = {
            ragionesociale: ragione,
            partitaiva: document.getElementById('global-dest-piva').value.trim(),
            citta: document.getElementById('global-dest-citta').value.trim(),
            email: document.getElementById('global-dest-email').value.trim(),
            telefono: document.getElementById('global-dest-tel').value.trim()
        };

        customFetch('protocollo_email', 'aggiungiAzienda', dati).then(res => {
            if (res && res.success) {
                showToast("Società aggiunta con successo!", "success");
                modal.style.display = 'none';

                // Reset cache aziende se disponibile
                if (typeof window.resetAziendeContattiCache === 'function') {
                    window.resetAziendeContattiCache();
                }
                if (window.autocompleteManager && typeof window.autocompleteManager.clearCache === 'function') {
                    window.autocompleteManager.clearCache('companies');
                }

                // Callback con nuova azienda
                if (typeof onSuccess === 'function') {
                    // Ricarica lista aziende per ottenere l'ID della nuova
                    customFetch('protocollo_email', 'caricaAziende', {}).then(resAziende => {
                        if (resAziende.success && Array.isArray(resAziende.data)) {
                            const nuovaAzienda = resAziende.data.find(a =>
                                (a.ragionesociale || '').toLowerCase() === ragione.toLowerCase()
                            );
                            onSuccess(nuovaAzienda || { ragionesociale: ragione });
                        } else {
                            onSuccess({ ragionesociale: ragione });
                        }
                    }).catch(() => {
                        onSuccess({ ragionesociale: ragione });
                    });
                }
            } else {
                showToast("Errore salvataggio: " + (res && res.error ? res.error : "Errore"), "error");
            }
        }).catch(err => {
            console.error('Errore aggiunta azienda:', err);
            showToast("Errore di comunicazione con il server", "error");
        });
    };

    // Handler annulla
    document.getElementById('global-btn-cancella-destinatario').onclick = function () {
        modal.style.display = 'none';
    };
};

/**
 * Mostra modale per aggiungere un nuovo contatto
 * @param {string} defaultName - Valore di default per nome/cognome
 * @param {number|string} companyId - ID dell'azienda a cui associare il contatto
 * @param {string} defaultEmail - Valore di default per email
 * @param {function} onSuccess - Callback chiamato dopo inserimento: onSuccess(nuovoContatto)
 */
window.showAddContactModal = function (defaultName, companyId, defaultEmail, onSuccess) {
    const modalId = 'global-modal-nuovo-contatto';
    let modal = document.getElementById(modalId);

    // Crea modale se non esiste
    if (!modal) {
        modal = document.createElement('div');
        modal.id = modalId;
        modal.className = 'modal modal-small';
        modal.innerHTML = `
            <div class="modal-content">
                <span class="close-modal" onclick="window.toggleModal('${modalId}', 'close')">&times;</span>
                <h3>Nuovo contatto</h3>
                <form id="global-nuovo-contatto-form" autocomplete="off">
                    <div class="modal-form-grid">
                        <div>
                            <label for="global-contatto-cognome">Cognome*:</label>
                            <input type="text" id="global-contatto-cognome" name="cognome" required>
                        </div>
                        <div>
                            <label for="global-contatto-nome">Nome*:</label>
                            <input type="text" id="global-contatto-nome" name="nome" required>
                        </div>
                        <div>
                            <label for="global-contatto-email">Email*:</label>
                            <input type="email" id="global-contatto-email" name="email" required>
                        </div>
                        <div>
                            <label for="global-contatto-ruolo">Ruolo:</label>
                            <input type="text" id="global-contatto-ruolo" name="ruolo">
                        </div>
                        <div>
                            <label for="global-contatto-telefono">Telefono:</label>
                            <input type="text" id="global-contatto-telefono" name="telefono">
                        </div>
                        <div>
                            <label for="global-contatto-cellulare">Cellulare:</label>
                            <input type="text" id="global-contatto-cellulare" name="cellulare">
                        </div>
                    </div>
                    <div class="modal-btns">
                        <button type="button" id="global-btn-cancella-contatto" class="button">Annulla</button>
                        <button type="submit" id="global-btn-salva-contatto" class="button">Salva</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Mostra modale e popola campi
    modal.style.display = 'block';

    // Parse defaultName se contiene spazio (potrebbe essere "Nome Cognome" o "Cognome Nome")
    let defaultCognome = '';
    let defaultNome = '';
    if (defaultName && defaultName.includes(' ')) {
        const parts = defaultName.trim().split(/\s+/);
        if (parts.length >= 2) {
            defaultNome = parts[0];
            defaultCognome = parts.slice(1).join(' ');
        } else {
            defaultNome = defaultName;
        }
    } else {
        defaultNome = defaultName || '';
    }

    document.getElementById('global-contatto-cognome').value = defaultCognome;
    document.getElementById('global-contatto-nome').value = defaultNome;
    document.getElementById('global-contatto-email').value = defaultEmail || '';
    document.getElementById('global-contatto-ruolo').value = '';
    document.getElementById('global-contatto-telefono').value = '';
    document.getElementById('global-contatto-cellulare').value = '';

    // Focus sul campo cognome
    setTimeout(() => document.getElementById('global-contatto-cognome').focus(), 100);

    // Salva companyId nello scope della closure
    const aziendaId = companyId;

    // Handler form submit
    const form = document.getElementById('global-nuovo-contatto-form');
    form.onsubmit = function (ev) {
        ev.preventDefault();
        const nome = document.getElementById('global-contatto-nome').value.trim();
        const cognome = document.getElementById('global-contatto-cognome').value.trim();
        const email = document.getElementById('global-contatto-email').value.trim();

        if (!nome || !cognome || !email) {
            showToast("Nome, cognome ed email sono obbligatori", "error");
            return;
        }
        if (!aziendaId) {
            showToast("Seleziona prima una società/azienda", "error");
            return;
        }

        const dati = {
            azienda_id: aziendaId,
            nome: nome,
            cognome: cognome,
            email: email,
            ruolo: document.getElementById('global-contatto-ruolo').value.trim(),
            telefono: document.getElementById('global-contatto-telefono').value.trim(),
            cellulare: document.getElementById('global-contatto-cellulare').value.trim()
        };

        customFetch('protocollo_email', 'aggiungiContatto', dati).then(res => {
            if (res && res.success) {
                showToast("Contatto aggiunto con successo!", "success");
                modal.style.display = 'none';

                // Reset cache contatti se disponibile
                if (typeof window.resetAziendeContattiCache === 'function') {
                    window.resetAziendeContattiCache();
                }
                if (window.autocompleteManager && typeof window.autocompleteManager.clearCache === 'function') {
                    window.autocompleteManager.clearCache('contacts');
                    window.autocompleteManager.clearCache('contattiByAzienda');
                }

                // Callback con nuovo contatto
                if (typeof onSuccess === 'function') {
                    const nuovoContatto = {
                        id: res.id || null,
                        nome: nome,
                        cognome: cognome,
                        nomeCompleto: cognome + ' ' + nome,
                        email: email,
                        telefono: dati.telefono,
                        cellulare: dati.cellulare,
                        ruolo: dati.ruolo,
                        azienda_id: aziendaId
                    };
                    onSuccess(nuovoContatto);
                }
            } else {
                showToast("Errore salvataggio: " + (res && res.error ? res.error : "Errore"), "error");
            }
        }).catch(err => {
            console.error('Errore aggiunta contatto:', err);
            showToast("Errore di comunicazione con il server", "error");
        });
    };

    // Handler annulla
    document.getElementById('global-btn-cancella-contatto').onclick = function () {
        modal.style.display = 'none';
    };
};

/* ========= GLOBAL LOADER (reference-counted) ========= */
(function () {
    'use strict';

    const state = {
        count: 0,
        overlayEl: null,
        textEl: null,
        isReady: false
    };

    function ensureDom() {
        if (state.isReady) return;

        const overlay = document.createElement('div');
        overlay.id = 'global-loader-overlay';
        overlay.className = 'global-loader-overlay is-hidden';
        overlay.setAttribute('aria-hidden', 'true');

        const box = document.createElement('div');
        box.className = 'global-loader-box';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');

        const spinner = document.createElement('div');
        spinner.className = 'global-loader-spinner';

        const text = document.createElement('div');
        text.className = 'global-loader-text';
        text.textContent = 'Caricamento in corso...';

        box.appendChild(spinner);
        box.appendChild(text);
        overlay.appendChild(box);

        document.body.appendChild(overlay);

        state.overlayEl = overlay;
        state.textEl = text;
        state.isReady = true;
    }

    function show(text) {
        ensureDom();
        if (typeof text === 'string' && text.trim() !== '') {
            state.textEl.textContent = text.trim();
        }
        state.overlayEl.classList.remove('is-hidden');
        state.overlayEl.setAttribute('aria-hidden', 'false');
    }

    function hide() {
        if (!state.isReady) return;
        state.overlayEl.classList.add('is-hidden');
        state.overlayEl.setAttribute('aria-hidden', 'true');
    }

    function push(text) {
        state.count += 1;
        if (state.count === 1) {
            show(text);
        } else if (typeof text === 'string' && text.trim() !== '') {
            // se arrivano più operazioni, aggiorno il testo all’ultima richiesta “significativa”
            state.textEl.textContent = text.trim();
        }
    }

    function pop() {
        if (state.count > 0) state.count -= 1;
        if (state.count === 0) hide();
    }

    function reset() {
        state.count = 0;
        hide();
    }

    window.GlobalLoader = {
        push,
        pop,
        reset,
        show,
        hide
    };
})();
