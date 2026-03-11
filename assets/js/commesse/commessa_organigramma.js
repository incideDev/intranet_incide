document.addEventListener("DOMContentLoaded", function () {
    const utenti = window._orgUtenti || {};
    const responsabileCommessaId = window._respCommessaId || null;

    // sorgente iniziale
    let treeData = (window._orgInitialData && typeof window._orgInitialData === 'object')
        ? window._orgInitialData
        : null;

    // se non c'è nulla salvato, semina con il responsabile; se c'è, NON sovrascrivere la root
    if (!treeData) {
        treeData = { user_id: responsabileCommessaId, children: [] };
    } else {
        if (!Array.isArray(treeData.children)) treeData.children = [];
        if (typeof treeData.user_id === 'undefined' || treeData.user_id === null) {
            treeData.user_id = responsabileCommessaId;
        }
    }

    // Stato drag
    let dragUserId = null;

    // Ricorsivo: trova tutti gli user_id già inseriti nell’albero
    function getAllUserIds(node) {
        if (!node) return [];
        let ids = [];
        if (node.user_id) ids.push(node.user_id);
        if (node.children && Array.isArray(node.children)) {
            node.children.forEach(child => { ids = ids.concat(getAllUserIds(child)); });
        }
        return ids;
    }

    function renderTree(node, container) {
        container.innerHTML = '';
        if (!node) return;
        container.appendChild(renderNode(node, true, null, null));
    }

    function renderNode(node, isRoot = false, parent = null, childIdx = null, inheritedDiscipline = null) {
        const wrap = document.createElement('div');
        wrap.className = 'org-node-wrap';

        // --- COLORE/BADGE ---
        let borderColor = '#f5c375'; // default
        let disciplineBadgeHtml = '';

        // Figlio diretto della root = responsabile divisione
        const isResponsabileDivisione = (parent && parent === treeData && node.user_id);

        // Assegnazione / ereditarietà disciplina
        let disciplineToUse = null;
        if (isResponsabileDivisione && Array.isArray(node.disciplines) && node.disciplines.length) {
            disciplineToUse = node.disciplines[0];
            const found = (window.DISCIPLINE_COMMESSE || []).find(x => x.code === disciplineToUse);
            borderColor = found ? found.color : borderColor;
            disciplineBadgeHtml = `
                <span class="org-node-root-label" style="
                    bottom: -13px; left: 50%; transform: translateX(-50%);
                    background: ${found ? found.color : '#b8e6ff'};
                    color: #fff;
                    border: 1.3px solid #fff;
                    font-size: 14px;
                    font-weight: 700;
                    padding: 2px 10px;
                    border-radius: 10px;
                    letter-spacing: 1.2px;
                    z-index:2;
                ">${disciplineToUse}</span>
            `;
        } else if (inheritedDiscipline) {
            const found = (window.DISCIPLINE_COMMESSE || []).find(x => x.code === inheritedDiscipline);
            borderColor = found ? found.color : borderColor;
        }

        // AVATAR
        const u = utenti[node.user_id] || {};
        const nodeDiv = document.createElement('div');
        nodeDiv.className = 'org-node' + (node.user_id ? '' : ' org-node-empty');
        if (node.user_id && u) {
            nodeDiv.innerHTML = `
                ${isRoot
                    ? `<span class="org-crown-root" style="position:absolute;top:-20px;left:50%;transform:translateX(-50%);z-index:3;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="22" viewBox="0 0 72 50">
                            <path fill="#ffb636" d="M68.4 10.8c-.6 1.4-1 3.3-1.1 4.5c-1.6 0-2.9 1.3-2.9 2.9c0 1.6 1.3 2.9 2.9 2.9c.2 0 .4 0 .6-.1c-.2 4.3-3.7 7.7-8.1 7.7c-4.4 0-7.9-3.4-8.1-7.7c1.3-.2 2.4-1.4 2.4-2.8c0-1.6-1.3-2.9-2.9-2.9h-.3c-.4-2-1.5-5.2-2.8-5.2c-1.3 0-2.4 3.1-2.8 5.2c-.2 0-.4-.1-.6-.1c-1.6 0-2.9 1.3-2.9 2.9c0 1.4 1 2.6 2.4 2.9c-.2 4.3-3.7 7.7-8.1 7.7c-4.4 0-7.9-3.5-8.1-7.8c1.3-.3 2.2-1.4 2.2-2.8c0-1.6-1.3-2.9-2.9-2.9H27c-.4-2-1.5-5.2-2.8-5.2c-1.3 0-2.4 3.1-2.8 5.2c-.2 0-.4-.1-.6-.1c-1.6 0-2.9 1.3-2.9 2.9c0 1.5 1.1 2.7 2.6 2.9c-.2 4.3-3.8 7.7-8.1 7.7c-4.4 0-7.9-3.4-8.1-7.7c.2 0 .4.1.6.1c1.6 0 2.9-1.3 2.9-2.9c0-1.6-1.3-2.9-2.9-2.9h-.2c-.2-1.3-.5-3-.9-4.4c-.3-1.1-1.8-.9-1.8.3v46.8h68.4V11.3c-.2-1.2-1.5-1.5-2-.5z"/>
                            <path fill="#ffd469" d="M70.8 43.6H1.2c-.7 0-1.2-.5-1.2-1.2V39c0-.7.5-1.2 1.2-1.2h69.5c.7 0 1.2.5 1.2 1.2v3.4c.1.7-.4 1.2-1.1 1.2zm1.2 17v-3.4c0-.7-.5-1.2-1.2-1.2H1.2c-.7 0-1.2.5-1.2 1.2v3.4c0 .7.5 1.2 1.2 1.2h69.5c.8 0 1.3-.5 1.3-1.2z"/>
                            <path fill="#ffc7ef" d="M64.4 50c0 1.8-1.4 3.2-3.2 3.2S58 51.8 58 50s1.4-3.2 3.2-3.2s3.2 1.4 3.2 3.2zM36 46.8c-1.8 0-3.2 1.4-3.2 3.2s1.4 3.2 3.2 3.2s3.2-1.4 3.2-3.2s-1.4-3.2-3.2-3.2zm-25.2 0c-1.8 0-3.2 1.4-3.2 3.2s1.4 3.2 3.2 3.2S14 51.7 14 50s-1.4-3.2-3.2-3.2z"/>
                        </svg>
                    </span>`
                    : `<button class="org-node-remove" title="Rimuovi" data-tooltip="Rimuovi da organigramma">×</button>`
                }
                <img src="${u.img}" class="org-node-avatar" data-tooltip="${u.nome}" style="border: 4px solid ${borderColor};">
                ${disciplineBadgeHtml}
            `;
            if (!isRoot) {
                nodeDiv.querySelector('.org-node-remove').onclick = function(e) {
                    e.stopPropagation();
                    removeNodeById(treeData, node.user_id);
                    renderAll();
                };
            }
        } else {
            nodeDiv.innerHTML = `<span class="org-node-placeholder">Trascina qui</span>`;
        }

        // DRAG user solo se non root
        if (!isRoot) {
            nodeDiv.ondragover = function(e) { e.preventDefault(); nodeDiv.classList.add('drop-hover'); };
            nodeDiv.ondragleave = function() { nodeDiv.classList.remove('drop-hover'); };
            nodeDiv.ondrop = function(e) {
                e.preventDefault();
                nodeDiv.classList.remove('drop-hover');
                if (!dragUserId || !utenti[dragUserId]) return;
                if (getAllUserIds(treeData).includes(Number(dragUserId))) return;
                node.user_id = Number(dragUserId);
                renderAll();
            };
        }

        // DROP discipline solo su responsabili divisione
        if (isResponsabileDivisione) {
            nodeDiv.ondragover = function(e) {
                if (e.dataTransfer.types.includes('discipline')) {
                    e.preventDefault();
                    nodeDiv.classList.add('drop-hover');
                }
            };
            nodeDiv.ondragleave = function() { nodeDiv.classList.remove('drop-hover'); };
            nodeDiv.ondrop = function(e) {
                nodeDiv.classList.remove('drop-hover');
                const code = e.dataTransfer.getData("discipline");
                if (!code) return;
                if (!Array.isArray(node.disciplines)) node.disciplines = [];
                if (node.disciplines.includes(code)) return;
                node.disciplines = [code]; // solo una
                renderAll();
            };
        }

        wrap.appendChild(nodeDiv);

        if (node.children && node.children.length) {
            const vline = document.createElement('div');
            vline.className = 'org-link-line';
            wrap.appendChild(vline);

            const lineAndChildren = document.createElement('div');
            lineAndChildren.className = 'org-children-fullrow';

            let hline = document.createElement('div');
            hline.className = 'org-link-horizontal' + (node.children.length > 1 ? '' : ' single');
            lineAndChildren.appendChild(hline);

            const childrenRow = document.createElement('div');
            childrenRow.className = 'org-children-wrap';

            node.children.forEach((child, i) => {
                const childWrap = document.createElement('div');
                childWrap.style.display = 'flex';
                childWrap.style.flexDirection = 'column';
                childWrap.style.alignItems = 'center';
                childWrap.style.position = 'relative';

                const vlink = document.createElement('div');
                vlink.className = 'org-link-vertical-between';
                childWrap.appendChild(vlink);

                childWrap.appendChild(
                    renderNode(child, false, node, i, disciplineToUse || inheritedDiscipline)
                );
                childrenRow.appendChild(childWrap);
            });

            const addBtn = document.createElement('button');
            addBtn.className = 'org-add-child';
            addBtn.textContent = '+';
            addBtn.setAttribute('data-tooltip','Aggiungi sulla stessa riga');
            addBtn.onclick = function(e) {
                e.stopPropagation();
                node.children = node.children || [];
                node.children.unshift({user_id:null,children:[]});
                renderAll();
            };
            childrenRow.insertBefore(addBtn, childrenRow.firstChild);

            lineAndChildren.appendChild(childrenRow);
            wrap.appendChild(lineAndChildren);
        } else {
            const childrenRow = document.createElement('div');
            childrenRow.className = 'org-children-wrap';
            const addBtn = document.createElement('button');
            addBtn.className = 'org-add-child';
            addBtn.textContent = '+';
            addBtn.setAttribute('data-tooltip','Aggiungi sotto-nodo');
            addBtn.onclick = function(e) {
                e.stopPropagation();
                node.children = node.children || [];
                node.children.push({user_id:null,children:[]});
                renderAll();
            };
            childrenRow.appendChild(addBtn);
            wrap.appendChild(childrenRow);
        }

        return wrap;
    }

    // Rimuove ricorsivo un nodo (non root!)
    function removeNodeById(node, id) {
        if (!node || !node.children) return;
        for (let i = node.children.length-1; i >= 0; i--) {
            if (node.children[i].user_id === id) { node.children.splice(i,1); continue; }
            if (node.children[i].children) removeNodeById(node.children[i], id);
        }
    }

    function renderSidebar() {
        const div = document.getElementById("sidebar-users");
        div.innerHTML = '';
        const searchInput = document.getElementById('org-search-users');
        const query = (searchInput && searchInput.value) ? searchInput.value.toLowerCase() : '';
        const usedIds = getAllUserIds(treeData);

        Object.entries(utenti).forEach(([id, u]) => {
            if (usedIds.includes(Number(id))) return;

            if (query && !((u.nome || '').toLowerCase().includes(query) || (u.disciplina || '').toLowerCase().includes(query))) return;

            let tile = document.createElement("div");
            tile.className = "user-tile-persona";
            tile.draggable = true;
            tile.setAttribute("data-uid", id);
            tile.setAttribute('data-tooltip', u.nome);

            tile.innerHTML = `
                <img src="${u.img}" class="user-tile-avatar" alt="">
                <span class="user-tile-disciplina">${u.disciplina ? window.escapeHtml(u.disciplina) : '&mdash;'}</span>
            `;

            tile.ondragstart = function(e) {
                dragUserId = id;
                e.dataTransfer.setData("uid", id);
                e.dataTransfer.effectAllowed = "copy";
                this.classList.add('dragover');
            };
            tile.ondragend = function() {
                dragUserId = null;
                this.classList.remove('dragover');
            };

            div.appendChild(tile);
        });
    }

    function renderSidebarDiscipline() {
        const div = document.getElementById("sidebar-disciplines");
        div.innerHTML = '';
        (window.DISCIPLINE_COMMESSE || []).forEach(d => {
            let badge = document.createElement("span");
            badge.className = "badge-disciplina";
            badge.draggable = true;
            badge.style.background = d.color;
            badge.style.color = "#fff";
            badge.setAttribute("data-code", d.code);
            badge.textContent = d.code;
            badge.ondragstart = function (e) {
                e.dataTransfer.setData("discipline", d.code);
                e.dataTransfer.effectAllowed = "copy";
            };
            div.appendChild(badge);
        });
    }

    async function salvaOrganigrammaAuto() {
        if (typeof customFetch !== 'function') {
            console.error("customFetch NON definita! Includi main.js prima di questo file.");
            return;
        }
        const res = await customFetch("commesse", "saveOrganigrammaTree", {
            commessa_id: window._commessaId,
            organigramma: treeData
        });
        return res;
    }

    function renderAll() {
        renderSidebar();
        renderSidebarDiscipline();
        const area = document.getElementById("org-fasce-area");
        renderTree(treeData, area);
        adaptTreeZoom();
        salvaOrganigrammaAuto();
    }

    function adaptTreeZoom() {
        const scrollwrap = document.querySelector('.org-tree-scrollwrap');
        const inner = document.querySelector('.org-tree-inner');
        if (!scrollwrap || !inner) return;

        inner.style.transform = 'scale(1)';
        setTimeout(() => {
            const availW = scrollwrap.clientWidth;
            const availH = scrollwrap.clientHeight;
            const neededW = inner.scrollWidth;
            const neededH = inner.scrollHeight;
            let zoom = 1;

            if (neededW > availW || neededH > availH) {
                zoom = Math.min(availW / neededW, availH / neededH, 1);
            }
            inner.style.transform = 'scale(' + (zoom * 0.97) + ')';
        }, 10);
    }
        
    let _adaptTreeZoomTimeout = null;
    window.addEventListener('resize', function() {
        clearTimeout(_adaptTreeZoomTimeout);
        _adaptTreeZoomTimeout = setTimeout(adaptTreeZoom, 100);
    });

    renderAll();

    document.addEventListener('input', function(e) {
        if (e.target && e.target.id === 'org-search-users') {
            renderSidebar();
        }
    });
});
