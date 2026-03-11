// assets/js/commesse/commessa_controlli_sicurezza.js

document.addEventListener('DOMContentLoaded', async () => {
    try {
        await ensureCustomFetch();
        await initControlliPerImpresa();
    } catch (err) {
        console.error('[controlli_sicurezza] init error:', err);
        if (typeof window.showToast === 'function') {
            showToast('Errore inizializzazione controlli sicurezza.', 'error');
        }
    }
});

/* Attende che window.customFetch sia disponibile (max ~5s) */
async function ensureCustomFetch(maxMs = 5000) {
    const started = Date.now();
    while (typeof window.customFetch !== 'function') {
        if (Date.now() - started > maxMs) {
            throw new Error('customFetch non disponibile');
        }
        await new Promise(r => setTimeout(r, 50));
    }
}

async function initControlliPerImpresa() {
    const root = document.getElementById('controlliAziende');
    if (!root) return;

    const tabella = (root.dataset.tabella || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();
    const titolo = root.dataset.titolo || 'Commessa';

    // viste disponibili (per abilitare/disabilitare i pill)
    let knownViews = [];
    try {
        knownViews = JSON.parse(root.dataset.knownViews || '[]');
        if (!Array.isArray(knownViews)) knownViews = [];
    } catch { knownViews = []; }

    const hasVrtp = knownViews.includes('sic_vrtp');
    const hasVpos = knownViews.includes('sic_vpos');
    const hasVfp = knownViews.includes('sic_vfp');

    // 1) Organigramma → ID aziende
    const org = await getOrganigrammaImprese(tabella);
    console.debug('[controlli_sicurezza] getOrganigrammaImprese →', org);

    const aziendaIds = Array.from(new Set(flattenAziende(org)));
    if (!aziendaIds.length) {
        console.warn('[controlli_sicurezza] Nessuna azienda nell’organigramma (ids vuoti)');
    }

    // 2) Anagrafiche imprese → filtro per ids dell’organigramma
    let usable = [];
    try {
        const all = await getImpreseList(''); // [{id,label,piva,...}]
        console.debug('[controlli_sicurezza] getImpreseList → items:', all?.length ?? 0);
        usable = (Array.isArray(all) ? all : []).filter(x => aziendaIds.includes(Number(x.id)));
    } catch (e) {
        console.warn('[controlli_sicurezza] getImpreseList errore:', e);
    }

    // 3) Fallback: se ancora vuoto, usa direttamente il Service listImpresePerControlli
    if (!Array.isArray(usable) || usable.length === 0) {
        try {
            const res = await callCommesse('listImpresePerControlli', { tabella });
            console.debug('[controlli_sicurezza] fallback listImpresePerControlli →', res);
            if (res && res.success && Array.isArray(res.aziende)) {
                // adatta al formato {id,label,ruolo}
                usable = res.aziende.map(r => ({
                    id: Number(r.azienda_id),
                    label: String(r.nome || 'Impresa'),
                    ruolo: String(r.ruolo || '')
                })).filter(x => Number.isFinite(x.id) && x.id > 0);
            }
        } catch (e) {
            console.warn('[controlli_sicurezza] fallback service errore:', e);
        }
    }

    // 4) Mappa ruoli dall’organigramma (se presenti)
    const ruoloByAzienda = mapRuoliByAzienda(org);

    // 5) Render
    renderAziende(root, usable, { tabella, titolo, hasVrtp, hasVpos, hasVfp, ruoloByAzienda });
}

/* =================== RENDER =================== */
function renderAziende(root, items, opts) {
    const { tabella, titolo, hasVrtp, hasVpos, hasVfp, ruoloByAzienda } = opts;
    root.innerHTML = '';

    if (!Array.isArray(items) || items.length === 0) {
        root.innerHTML = `<div class="placeholder" style="color:#777;">Nessuna impresa disponibile per questa commessa.</div>`;
        return;
    }

    // Ordina alfabeticamente per label
    items.sort((a, b) => String(a.label || '').localeCompare(String(b.label || '')));

    items.forEach(az => {
        const aziendaId = parseInt(az.id, 10) || 0;
        const nome = String(az.label || 'Impresa');
        // priorità: ruolo fornito dal fallback → altrimenti dall’organigramma
        const ruolo = String(az.ruolo || ruoloByAzienda.get(aziendaId) || '');

        const card = document.createElement('div');
        card.className = 'ctrl-card';
        card.dataset.aziendaId = String(aziendaId);

        const main = document.createElement('div');
        main.className = 'ctrl-main';

        const title = document.createElement('div');
        title.className = 'ctrl-title';
        title.textContent = nome;
        main.appendChild(title);

        if (ruolo) {
            const sub = document.createElement('div');
            sub.className = 'ctrl-subtitle';
            sub.textContent = ruolo;
            main.appendChild(sub);
        }

        const actions = document.createElement('div');
        actions.className = 'ctrl-actions';

        const mkLink = (viewKey, text, tooltip) => {
            const a = document.createElement('a');
            a.className = 'ctrl-pill';
            a.setAttribute('data-tooltip', tooltip);
            a.href = `index.php?section=commesse&page=commessa&tabella=${encodeURIComponent(tabella)}&titolo=${encodeURIComponent(titolo)}&view=${encodeURIComponent(viewKey)}&azienda_id=${encodeURIComponent(aziendaId)}`;
            a.textContent = text;
            return a;
        };
        const mkDisabled = (text, tooltip) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'ctrl-pill disabled';
            b.disabled = true;
            b.setAttribute('data-tooltip', tooltip);
            b.textContent = text;
            return b;
        };

        actions.appendChild(hasVrtp ? mkLink('sic_vrtp', 'VTP', 'Apri VTP') : mkDisabled('VTP', 'VTP non disponibile'));
        actions.appendChild(hasVpos ? mkLink('sic_vpos', 'VPOS', 'Apri VPOS') : mkDisabled('VPOS', 'VPOS non disponibile'));
        actions.appendChild(hasVfp ? mkLink('sic_vfp', 'VFP', 'Apri VFP') : mkDisabled('VFP', 'VFP non ancora disponibile'));

        card.appendChild(main);
        card.appendChild(actions);
        root.appendChild(card);
    });
}

/* =================== SERVICE WRAPPER =================== */
async function callCommesse(action, payload) {
    if (typeof window.customFetch !== 'function') {
        throw new Error('customFetch non disponibile');
    }
    return await window.customFetch('commesse', action, payload || {});
}

/* =================== API (nomi puliti) =================== */
async function getOrganigrammaImprese(tabella) {
    const json = await callCommesse('getOrganigrammaImprese', { tabella });
    console.debug('[controlli_sicurezza] getOrganigrammaImprese raw →', json);

    if (!json || json.success !== true) return { azienda_id: null, children: [] };
    if (json.data && typeof json.data === 'object') return json.data; // formato nuovo
    if (json.azienda_id !== undefined) return json;                   // compat legacy

    return { azienda_id: null, children: [] };
}

async function getImpreseList(q) {
    const json = await callCommesse('getImpreseAnagrafiche', { q: q || '' });
    console.debug('[controlli_sicurezza] getImpreseList raw →', json);
    return (json && json.success && Array.isArray(json.items)) ? json.items : [];
}

/* =================== UTIL robusti =================== */
function flattenAziende(node) {
    const out = [];
    (function visit(n) {
        if (!n || typeof n !== 'object') return;

        const id = Number(n.azienda_id ?? n.aziendaId ?? 0);
        if (Number.isFinite(id) && id > 0) out.push(id);

        const kids = Array.isArray(n.children) ? n.children
            : Array.isArray(n.nodes) ? n.nodes
                : [];
        kids.forEach(visit);
    })(node);
    return out.filter((v, i, a) => a.indexOf(v) === i); // dedup
}

function mapRuoliByAzienda(node) {
    const map = new Map();
    (function visit(n) {
        if (!n || typeof n !== 'object') return;

        const id = Number(n.azienda_id ?? n.aziendaId ?? 0);
        if (Number.isFinite(id) && id > 0) {
            const ruolo = String(n.ruolo ?? n.title ?? n.nomeRuolo ?? '');
            if (ruolo) map.set(id, ruolo);
        }

        const kids = Array.isArray(n.children) ? n.children
            : Array.isArray(n.nodes) ? n.nodes
                : [];
        kids.forEach(visit);
    })(node);
    return map;
}
