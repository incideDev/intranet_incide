// ========== GESTIONE UTENTI CENTRALIZZATA ==========
// Cache centralizzata user_id <-> Nominativo
// Zero duplicazione, auto-popolamento

(function () {
    'use strict';

    window.UserManager = {

        // Cache interna (user_id -> {id, nome, img})
        _cache: {},

        /**
         * Popola cache da array di oggetti
         * @param {Array} data - Array con {user_id, Nominativo, ...} o {id, nome, ...}
         */
        populate(data) {
            if (!Array.isArray(data)) return;

            data.forEach(item => {
                const userId = item.user_id || item.id || item.submitted_by || item.assegnato_a || item.responsabile;
                const nome = item.Nominativo || item.nome || item.creato_da || item.assegnato_a_nome || item.responsabile_nome;
                const img = item.image || item.profile_img || item.img || item.creato_da_img || item.responsabile_img;

                if (userId && nome && String(nome).trim() && isNaN(nome)) {
                    this._cache[String(userId)] = {
                        id: userId,
                        nome: String(nome).trim(),
                        img: img || null
                    };
                }
            });
        },

        /**
         * Popola da mappa semplice {user_id: nome}
         * @param {Object} map - {user_id: Nominativo}
         */
        populateMap(map) {
            if (typeof map !== 'object') return;

            Object.entries(map).forEach(([userId, nome]) => {
                if (userId && nome && String(nome).trim()) {
                    this._cache[String(userId)] = {
                        id: userId,
                        nome: String(nome).trim(),
                        img: null
                    };
                }
            });
        },

        /**
         * Ottieni nome da user_id
         * @param {string|number} userId
         * @returns {string} Nome utente o '—'
         */
        getName(userId) {
            if (!userId) return '—';
            const uid = String(userId);

            // Se è già un nome (non numerico), ritornalo
            if (isNaN(userId) && userId.length > 2) return String(userId);

            // Cerca in cache
            if (this._cache[uid]) return this._cache[uid].nome;

            // Fallback: CURRENT_USER
            const currentId = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
            if (uid === currentId) {
                const name = window.CURRENT_USER?.nome_completo || window.CURRENT_USER?.Nominativo || window.CURRENT_USER?.username || 'Tu';
                this._cache[uid] = { id: uid, nome: name, img: window.CURRENT_USER?.profile_img || null };
                return name;
            }

            return '—';
        },

        /**
         * Ottieni immagine profilo da user_id
         * @param {string|number} userId
         * @returns {string|null} URL immagine o null
         */
        getImage(userId) {
            if (!userId) return null;
            const uid = String(userId);

            if (this._cache[uid] && this._cache[uid].img) {
                return this._cache[uid].img;
            }

            // Fallback: CURRENT_USER
            const currentId = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
            if (uid === currentId) {
                return window.CURRENT_USER?.profile_img || '/assets/images/default_profile.png';
            }

            return '/assets/images/default_profile.png';
        },

        /**
         * Ottieni oggetto utente completo
         * @param {string|number} userId
         * @returns {Object|null} {id, nome, img} o null
         */
        getUser(userId) {
            if (!userId) return null;
            const uid = String(userId);
            return this._cache[uid] || null;
        },

        /**
         * Svuota cache
         */
        clear() {
            this._cache = {};
        }
    };

    // Retrocompatibilità: esponi anche come __USERS_MAP__
    Object.defineProperty(window, '__USERS_MAP__', {
        get() { return window.UserManager._cache; },
        set(val) {
            if (typeof val === 'object') {
                window.UserManager.populateMap(val);
            }
        }
    });

    // Funzione retrocompatibile
    window.getUserNameById = (userId) => window.UserManager.getName(userId);

})();

