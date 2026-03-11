document.addEventListener('DOMContentLoaded', function () {
    // Legge l'area documentale dal nuovo oggetto window.documentArea (iniettato da document_manager.php)
    // Mantiene retrocompatibilità con window.currentSection (che ora è un alias)
    const _dmArea = window.documentArea || {};
    const currentSection = _dmArea.id || window.currentSection || 'archivio';

    // Helper permessi: legge direttamente dal flag PHP-injected, senza costruire la stringa
    function _dmCanManage() {
        if (_dmArea.permissions && typeof _dmArea.permissions.manage !== 'undefined') {
            return !!_dmArea.permissions.manage;
        }
        // Fallback legacy
        if (typeof window.userHasPermission === 'function') {
            return window.userHasPermission('manage_' + currentSection);
        }
        return false;
    }

    const urlParams = new URLSearchParams(window.location.search);
    const pageSlug = urlParams.get('page');
    // Se page=section (es. page=archivio o page=qualita) siamo nella dashboard
    const isVistaGenerale = (pageSlug === currentSection);

    // Flag globale per sincronizzare viste dopo modifiche
    window.__dmDocumentsDirty = false;

    const uploadModal = document.getElementById('uploadModalDM');

    // Inizializza dropzone solo se siamo su una pagina specifica
    if (uploadModal && !isVistaGenerale && pageSlug) {
        const dropArea = document.getElementById('dropAreaDM');
        const inputFiles = document.getElementById('uploadFilesDM');

        if (dropArea && inputFiles) {
            // Drag and drop manuale
            dropArea.addEventListener('dragover', e => {
                e.preventDefault();
                dropArea.classList.add('dragover');
            });
            dropArea.addEventListener('dragleave', e => {
                e.preventDefault();
                dropArea.classList.remove('dragover');
            });
            dropArea.addEventListener('drop', e => {
                e.preventDefault();
                dropArea.classList.remove('dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length) {
                    inputFiles.files = e.dataTransfer.files;
                    inputFiles.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            dropArea.addEventListener('click', e => {
                if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
                inputFiles.click();
            });
        }

        // Collega bottoni chiudi
        const closeBtn = uploadModal.querySelector('.close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                closeModal('uploadModalDM');
            });
        }
        const cancelUploadBtn = document.getElementById('cancelUploadDM');
        if (cancelUploadBtn) {
            cancelUploadBtn.addEventListener('click', () => {
                closeModal('uploadModalDM');
            });
        }

        // Chiudi cliccando fuori
        uploadModal.addEventListener('click', function (e) {
            if (e.target === uploadModal) {
                closeModal('uploadModalDM');
            }
        });

        // Gestione upload file UI
        if (inputFiles) {
            const filesList = document.getElementById('uploadFilesList');
            const titoloGroup = document.getElementById('uploadTitoloGroup');
            const descrizioneGroup = document.getElementById('uploadDescrizioneGroup');
            const uploadBtn = document.getElementById('uploadButtonDM');

            function updateUploadUI() {
                const files = inputFiles.files;
                const count = files ? files.length : 0;

                if (count === 0) {
                    if (filesList) filesList.style.display = 'none';
                    if (titoloGroup) titoloGroup.style.display = 'none';
                    if (descrizioneGroup) descrizioneGroup.style.display = 'none';
                    if (uploadBtn) uploadBtn.disabled = true;
                    return;
                }

                if (filesList) {
                    filesList.style.display = 'block';
                    filesList.innerHTML = '';
                    Array.from(files).forEach((file) => {
                        const item = document.createElement('div');
                        item.style.cssText = 'display: flex; align-items: center; padding: 6px 8px; background: #f5f5f5; border-radius: 4px; margin-bottom: 4px;';

                        let icon = 'fa-file';
                        if (file.type.startsWith('image/')) icon = 'fa-file-image-o';
                        else if (file.type === 'application/pdf') icon = 'fa-file-pdf-o';
                        else if (file.type.includes('word')) icon = 'fa-file-word-o';
                        else if (file.type.includes('excel') || file.type.includes('spreadsheet')) icon = 'fa-file-excel-o';

                        const sizeKB = (file.size / 1024).toFixed(1);
                        const sizeStr = sizeKB > 1024 ? (sizeKB / 1024).toFixed(1) + ' MB' : sizeKB + ' KB';

                        item.innerHTML = `
                            <i class="fa ${icon}" style="margin-right: 8px; color: #666;"></i>
                            <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${file.name}">${file.name}</span>
                            <span style="color: #888; font-size: 12px; margin-left: 10px;">${sizeStr}</span>
                        `;
                        filesList.appendChild(item);
                    });
                    if (count > 1) {
                        const badge = document.createElement('div');
                        badge.style.cssText = 'text-align: center; color: #666; font-size: 12px; margin-top: 8px;';
                        badge.textContent = `${count} file selezionati`;
                        filesList.appendChild(badge);
                    }
                }

                if (titoloGroup) titoloGroup.style.display = count === 1 ? 'block' : 'none';
                if (descrizioneGroup) descrizioneGroup.style.display = 'block';
                if (uploadBtn) uploadBtn.disabled = false;
            }

            inputFiles.addEventListener('change', updateUploadUI);
        }

        const selectFilesBtn = document.getElementById('selectFilesBtn');
        if (selectFilesBtn) {
            selectFilesBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                const fileInput = document.getElementById('uploadFilesDM');
                if (fileInput) fileInput.click();
            });
        }

        // Handler bottone "Carica"
        const uploadButtonDM = document.getElementById('uploadButtonDM');
        if (uploadButtonDM) {
            uploadButtonDM.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const input = document.getElementById('uploadFilesDM');
                const files = input?.files;

                if (!files || files.length === 0) {
                    showToast('Seleziona almeno un file', 'warning');
                    return;
                }

                if (!_dmCanManage()) {
                    showToast('Non hai i permessi per caricare documenti.', 'error');
                    return;
                }

                uploadDocumentiDM(currentSection, pageSlug, files).then(() => {
                    closeModal('uploadModalDM');
                    resetUploadModal();
                });
            });
        }

        function resetUploadModal() {
            const filesInput = document.getElementById('uploadFilesDM');
            const titoloInput = document.getElementById('uploadTitoloDM');
            const descrizioneInput = document.getElementById('uploadDescrizioneDM');
            const filesList = document.getElementById('uploadFilesList');
            const titoloGroup = document.getElementById('uploadTitoloGroup');
            const descrizioneGroup = document.getElementById('uploadDescrizioneGroup');
            const uploadBtn = document.getElementById('uploadButtonDM');

            if (filesInput) filesInput.value = '';
            if (titoloInput) titoloInput.value = '';
            if (descrizioneInput) descrizioneInput.value = '';
            if (filesList) { filesList.innerHTML = ''; filesList.style.display = 'none'; }
            if (titoloGroup) titoloGroup.style.display = 'none';
            if (descrizioneGroup) descrizioneGroup.style.display = 'none';
            if (uploadBtn) uploadBtn.disabled = true;
        }

        const cancelBtn = document.getElementById('cancelUploadDM');
        if (cancelBtn) cancelBtn.addEventListener('click', resetUploadModal);
    }

    const modalNuovaPagina = document.getElementById('modalNuovaPagina');
    const formNuovaPagina = document.getElementById('formNuovaPagina');
    const menuSelect = document.getElementById('menuSelect');
    const btnNuovoMenu = document.getElementById('btnNuovoMenu');

    // Carica menu disponibili per la sezione corrente
    async function caricaMenuDM() {
        if (!menuSelect) return;
        const res = await customFetch(currentSection, 'getMenus');
        if (res.success && Array.isArray(res.data)) {
            menuSelect.innerHTML = '<option value="">-- Seleziona menu --</option>';
            res.data.forEach(menu => {
                const opt = document.createElement('option');
                opt.value = menu.title;
                opt.textContent = menu.title;
                menuSelect.appendChild(opt);
            });
        }
    }

    if (menuSelect) {
        caricaMenuDM();
    }

    // Gestione creazione nuovo menu inline
    const createMenuArea = document.getElementById('createMenuInlineArea');
    const newMenuTitleInput = document.getElementById('newMenuTitleDM');
    const btnCreateMenuInline = document.getElementById('btnCreateMenuInlineDM');
    const btnCancelMenuInline = document.getElementById('btnCancelMenuInlineDM');

    function showCreateMenuInline() {
        if (!createMenuArea || !newMenuTitleInput) return;
        createMenuArea.classList.remove('hidden');
        setTimeout(() => newMenuTitleInput.focus(), 50);
    }

    function hideCreateMenuInline(reset = true) {
        if (!createMenuArea || !newMenuTitleInput) return;
        createMenuArea.classList.add('hidden');
        if (reset) newMenuTitleInput.value = '';
    }

    async function submitCreateMenuInline() {
        if (!newMenuTitleInput || !menuSelect) return;
        const title = newMenuTitleInput.value.trim();

        if (!title || title.length < 2) {
            showToast('Il nome del menu deve essere di almeno 2 caratteri', 'error');
            newMenuTitleInput.focus();
            return;
        }
        if (title.length > 80) {
            showToast('Il nome del menu non può superare 80 caratteri', 'error');
            newMenuTitleInput.focus();
            return;
        }

        const res = await customFetch(currentSection, 'createMenu', { title: title, parent_title: currentSection.charAt(0).toUpperCase() + currentSection.slice(1) });

        if (res.success) {
            showToast('Menu creato con successo', 'success');
            const opt = document.createElement('option');
            opt.value = res.title || title;
            opt.textContent = res.title || title;
            menuSelect.appendChild(opt);
            menuSelect.value = res.title || title;
            hideCreateMenuInline(true);
        } else {
            showToast(res.error || 'Errore durante la creazione del menu', 'error');
            newMenuTitleInput.focus();
        }
    }

    if (btnNuovoMenu) {
        btnNuovoMenu.onclick = function () {
            if (!_dmCanManage()) {
                showToast('Non hai i permessi per creare menu.', 'error');
                return;
            }
            if (createMenuArea && createMenuArea.classList.contains('hidden')) {
                showCreateMenuInline();
            } else {
                hideCreateMenuInline(true);
            }
        };
    }

    if (btnCreateMenuInline) btnCreateMenuInline.addEventListener('click', submitCreateMenuInline);
    if (btnCancelMenuInline) btnCancelMenuInline.addEventListener('click', () => hideCreateMenuInline(true));
    if (newMenuTitleInput) {
        newMenuTitleInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitCreateMenuInline();
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                hideCreateMenuInline(true);
            }
        });
    }

    if (!pageSlug || isVistaGenerale) {
        document.querySelectorAll('.docs-count').forEach(async function (span) {
            const slug = span.dataset.slug;
            const res = await customFetch(currentSection, 'getDocumentiCount', { slug });
            if (res.success && typeof res.count === 'number') {
                span.textContent = res.count;
            } else {
                span.textContent = '0';
            }
        });
    }

    const addBtn = document.getElementById('addButton');
    if (addBtn) {
        if (!_dmCanManage()) {
            addBtn.classList.add('disabled');
            addBtn.onclick = null;
            addBtn.setAttribute('title', 'Non hai i permessi per caricare o creare pagine.');
        } else {
            addBtn.classList.remove('disabled');
            addBtn.removeAttribute('title');
            addBtn.onclick = function () {
                const modalNuovaPagina = document.getElementById('modalNuovaPagina');
                const uploadModal = document.getElementById('uploadModalDM');

                if (!pageSlug || isVistaGenerale) {
                    // DASHBOARD
                    if (uploadModal) closeModal('uploadModalDM');
                    resetThumbUpload();
                    caricaMenuDM();
                    if (modalNuovaPagina) openModal('modalNuovaPagina');
                } else {
                    // PAGINA DINAMICA
                    if (modalNuovaPagina) closeModal('modalNuovaPagina');
                    if (uploadModal) {
                        openModal('uploadModalDM');
                        const dropArea = document.getElementById('dropAreaDM');
                        const uploadFiles = document.getElementById('uploadFilesDM');
                        const titoloInput = document.getElementById('uploadTitoloDM');
                        const descrizioneInput = document.getElementById('uploadDescrizioneDM');
                        if (dropArea) dropArea.classList.remove('dragover');
                        if (uploadFiles) uploadFiles.value = "";
                        if (titoloInput) titoloInput.value = "";
                        if (descrizioneInput) descrizioneInput.value = "";
                        const preview = dropArea?.querySelector('.upload-preview');
                        if (preview) preview.innerHTML = '';
                    }
                }
            };
        }
    }

    function closeNuovaPagina() {
        if (modalNuovaPagina) {
            closeModal('modalNuovaPagina');
            resetDMModalEdit();
        }
    }
    window.closeNuovaPagina = closeNuovaPagina;

    const cancelBtnMain = document.getElementById("cancelNuovaPagina");
    if (cancelBtnMain) cancelBtnMain.addEventListener("click", closeNuovaPagina);

    if (modalNuovaPagina) {
        const closeBtn = modalNuovaPagina.querySelector('.close');
        if (closeBtn) closeBtn.addEventListener('click', closeNuovaPagina);
        modalNuovaPagina.addEventListener("click", function (e) {
            if (e.target === modalNuovaPagina) closeNuovaPagina();
        });
    }

    // Helpers modale
    function openModal(id) {
        if (typeof window.toggleModal === 'function') {
            window.toggleModal(id, 'open');
        } else {
            const m = document.getElementById(id);
            if (m) m.style.display = 'block';
        }
    }
    function closeModal(id) {
        if (typeof window.toggleModal === 'function') {
            window.toggleModal(id, 'close');
        } else {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }
    }

    // Slug auto
    const titoloInput = document.getElementById('titolo');
    const slugInput = document.getElementById('slug');

    titoloInput?.addEventListener('input', function () {
        if (slugInput) {
            let generated = titoloInput.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            slugInput.value = generated;
        }
    });

    formNuovaPagina?.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!_dmCanManage()) {
            showToast('Non hai i permessi per questa operazione.', 'error');
            return;
        }

        // Per macro_policy=single il menu è implicito (= label area), altrimenti da select
        const isSinglePolicy = _dmArea.macro_policy === 'single';
        const menuTitle = isSinglePolicy ? (_dmArea.label || currentSection) : (menuSelect?.value.trim() || '');
        if (!menuTitle) {
            showToast('Seleziona un menu', 'error');
            return;
        }

        // Determina la section corretta per la URL (hosted areas usano ui_host)
        const urlSection = (_dmArea.ui_host && _dmArea.ui_host !== 'root') ? _dmArea.ui_host : currentSection;

        const data = {
            titolo: titoloInput?.value.trim() || '',
            slug: slugInput?.value.trim() || '',
            descrizione: this.descrizione?.value.trim() || '',
            immagine: this.immagine?.value.trim() || '',
            colore: this.colore?.value || '#4caf50',
            menu_title: menuTitle
        };
        const hidden = this.querySelector('input[name="original_slug"]');
        if (hidden && hidden.value) {
            data.original_slug = hidden.value;
            const res = await customFetch(currentSection, 'editPagina', data);
            if (res.success) {
                showToast('Pagina modificata con successo.', 'success');
                setTimeout(() => window.location.reload(), 900);
            } else {
                showToast(res.error || 'Errore durante la modifica', 'error');
            }
        } else {
            const res = await customFetch(currentSection, 'createPagina', data);
            if (res.success) {
                window.location.href = `index.php?section=${urlSection}&page=${encodeURIComponent(res.slug || '')}`;
            } else {
                showToast(res.error || 'Errore durante la creazione', 'error');
            }
        }
    });

    // Upload Thumb
    const uploadThumb = document.getElementById('uploadThumb');
    const uploadThumbBtn = document.getElementById('uploadThumbBtn');
    const immaginePath = document.getElementById('immaginePath');
    const previewThumb = document.getElementById('previewThumb');

    function resetThumbUpload() {
        if (immaginePath) immaginePath.value = '';
        if (previewThumb) previewThumb.innerHTML = '';
        if (uploadThumb) uploadThumb.value = '';
    }

    if (uploadThumbBtn && uploadThumb) {
        uploadThumbBtn.onclick = function (e) {
            e.preventDefault();
            uploadThumb.click();
        };
    }

    uploadThumb?.addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        if (!/^image\/(png|jpeg|jpg|webp)$/i.test(file.type)) {
            showToast('Formato immagine non valido', 'error');
            resetThumbUpload();
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showToast('Immagine troppo grande (max 2MB)', 'error');
            resetThumbUpload();
            return;
        }

        let fileToUpload = file;
        if (typeof window.compressImageFile === 'function') {
            try {
                fileToUpload = await window.compressImageFile(file, {
                    maxWidth: 400,
                    maxHeight: 400,
                    quality: 0.80,
                    outputType: 'image/webp',
                    keepName: false,
                    outputNameSuffix: '_thumb'
                });
            } catch (error) {
                console.warn('Errore compressione thumb, uso originale:', error);
            }
        }

        const formData = new FormData();
        formData.append('file', fileToUpload);
        formData.append('section', currentSection);
        formData.append('action', 'uploadThumb');

        previewThumb.innerHTML = '<span style="font-size:13px;color:#aaa;">Caricamento...</span>';

        // Usa customFetch con formData
        const res = await customFetch(currentSection, 'uploadThumb', formData, true);
        if (res.success && res.path) {
            immaginePath.value = res.path;
            previewThumb.innerHTML = `<img src="${res.path}" alt="Anteprima copertina" style="max-width:90px;height:auto;margin-top:7px;border-radius:7px;">`;
            showToast('Immagine caricata', 'success');
        } else {
            resetThumbUpload();
            showToast(res.error || 'Errore durante il caricamento immagine', 'error');
        }
    });

    if (pageSlug && !isVistaGenerale) {
        currentPage = 1;
        currentFolder = null; // Reset folder al caricamento pagina
        caricaCartelleDM(currentSection, pageSlug);

        // Carica la vista attiva (grid default, oppure tabella se preferenza salvata)
        const kanbanFileView = document.getElementById('documenti-kanban');
        if (kanbanFileView && !kanbanFileView.classList.contains('hidden')) {
            caricaDocumentiKanban(currentSection, pageSlug, 1, false);
        } else {
            caricaDocumentiDM(currentSection, pageSlug, 1, false);
        }
    }

    setupDMSwitchView();

    if (_dmCanManage()) {
        window.registerContextMenu('.grid-item', [
            {
                label: 'Modifica pagina',
                action: async function (el) {
                    const slug = el.dataset.slug;
                    const res = await customFetch(currentSection, 'getPagina', { slug });
                    if (!res.success || !res.data) return showToast('Errore nel recupero dati pagina', 'error');
                    openEditDMModal(res.data);
                }
            },
            {
                label: 'Elimina pagina',
                action: function (el) {
                    const slug = el.dataset.slug;
                    showConfirm('Confermi l\'eliminazione della pagina?<br><b>' + slug + '</b>', async function () {
                        const res = await customFetch(currentSection, 'deletePagina', { slug });
                        if (res.success) {
                            el.remove();
                            // Aggiorna sidebar (best effort)
                            const sidebarMenu = document.getElementById('sidebar-menu');
                            if (sidebarMenu) {
                                const submenuItems = sidebarMenu.querySelectorAll('.submenu-item');
                                submenuItems.forEach(item => {
                                    const link = item.querySelector('a.menu');
                                    if (link && link.href) {
                                        if (link.href.includes(`page=${encodeURIComponent(slug)}`) && link.href.includes(`section=${currentSection}`)) {
                                            item.remove();
                                        }
                                    }
                                });
                            }
                            showToast('Pagina eliminata!', 'success');
                        } else {
                            showToast(res.error || 'Errore durante eliminazione', 'error');
                        }
                    }, { allowHtml: true });
                }
            }
        ]);
    }

    // Context menu per le card documento nella vista griglia (.file-box)
    window.registerContextMenu('.file-box', [
        {
            label: 'Scarica',
            action: function (el) {
                const url = el.dataset.fileurl;
                if (url) {
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = '';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                }
            }
        },
        {
            label: 'Modifica',
            action: function (el) {
                const id = el.dataset.iddocumento;
                const titolo = el.dataset.titolo || '';
                const descrizione = el.dataset.descrizione || '';
                showRenameModal(titolo, descrizione, async function (newTitolo, newDescrizione) {
                    const res = await customFetch(currentSection, 'renameDocumento', { id, titolo: newTitolo, descrizione: newDescrizione });
                    if (res.success) {
                        // Aggiorna card in-place
                        const nameEl = el.querySelector('.file-name');
                        if (nameEl) {
                            const display = newTitolo.length > 25 ? newTitolo.substr(0, 22) + '...' : newTitolo;
                            nameEl.textContent = display;
                            nameEl.setAttribute('data-tooltip', newTitolo);
                        }
                        el.dataset.titolo = newTitolo;
                        el.dataset.descrizione = newDescrizione;
                        let descEl = el.querySelector('.file-desc');
                        if (newDescrizione) {
                            if (!descEl) {
                                descEl = document.createElement('div');
                                descEl.className = 'file-desc';
                                descEl.setAttribute('data-tooltip', 'Descrizione');
                                nameEl?.after(descEl);
                            }
                            descEl.textContent = newDescrizione;
                        } else if (descEl) {
                            descEl.remove();
                        }
                        showToast('Documento aggiornato', 'success');
                    } else {
                        showToast(res.error || 'Errore aggiornamento', 'error');
                    }
                });
            },
            visible: function () {
                return _dmCanManage();
            }
        },
        {
            label: 'Elimina',
            action: function (el) {
                const id = el.dataset.iddocumento;
                deleteDocumentoDM(currentSection, id, el);
            },
            visible: function () {
                return _dmCanManage();
            }
        },
        {
            label: 'Sposta',
            action: function (el) {
                const id = el.dataset.iddocumento;
                if (id) showMoveModal([id]);
            },
            visible: function () {
                return _dmCanManage();
            }
        },
        {
            label: 'Seleziona',
            action: function (el) {
                enterSelectionMode();
                toggleSelectBox(el);
            },
            visible: function () {
                return !_selectionActive && _dmCanManage();
            }
        }
    ]);

    // ── CONTEXT MENU CARTELLE (solo Grid) ────────────────────────
    window.registerContextMenu('.dm-folder-card:not(.dm-folder-add)', [
        {
            label: 'Apri',
            action: function (el) {
                const name = el.dataset.foldername;
                if (name) navigateToFolder(currentSection, pageSlug, name);
            }
        },
        {
            label: 'Rinomina',
            action: async function (el) {
                const oldName = el.dataset.foldername;
                if (!oldName) return;
                window.showPrompt('Nuovo nome cartella:', oldName, async function(newName) {
                    if (!newName || !newName.trim() || newName.trim() === oldName) return;
                    const trimmed = newName.trim();
                    if (trimmed.includes('/') || trimmed.includes('\\') || trimmed.includes('..') || trimmed.length > 100) {
                        showToast('Nome cartella non valido', 'error');
                        return;
                    }
                    const res = await customFetch(currentSection, 'renameFolder', { slug: pageSlug, folder: oldName, newName: trimmed });
                    if (res.success) {
                        showToast('Cartella rinominata', 'success');
                        caricaCartelleDM(currentSection, pageSlug);
                    } else {
                        showToast(res.error || 'Errore rinomina cartella', 'error');
                    }
                });
            },
            visible: function () { return _dmCanManage(); }
        },
        {
            label: 'Elimina',
            action: function (el) {
                const name = el.dataset.foldername;
                if (!name) return;
                showConfirm('Eliminare la cartella <b>' + _escHtml(name) + '</b> e tutti i file contenuti?', async function () {
                    const res = await customFetch(currentSection, 'deleteFolder', { slug: pageSlug, folder: name });
                    if (res.success) {
                        showToast('Cartella eliminata', 'success');
                        caricaCartelleDM(currentSection, pageSlug);
                        // Se eravamo dentro la cartella eliminata, torna a root
                        if (currentFolder === name) {
                            navigateToFolder(currentSection, pageSlug, null);
                        }
                    } else {
                        showToast(res.error || 'Errore eliminazione cartella', 'error');
                    }
                }, { allowHtml: true });
            },
            visible: function () { return _dmCanManage(); }
        }
    ]);

    // ── SELECTION MODE ─────────────────────────────────────────────
    let _selectionActive = false;
    const _selectedIds = new Set();
    let _lastSelectedIndex = null;

    function getKanbanContainer() {
        return document.getElementById('documenti-kanban');
    }

    function getAllBoxes() {
        const c = getKanbanContainer();
        return c ? Array.from(c.querySelectorAll('.file-box')) : [];
    }

    function enterSelectionMode() {
        if (_selectionActive) return;
        _selectionActive = true;
        _selectedIds.clear();
        _lastSelectedIndex = null;

        const container = getKanbanContainer();
        if (!container) return;
        container.classList.add('is-selection-mode');
        container.querySelectorAll('.file-box').forEach(b => b.classList.add('select-mode'));

        updateSelectionBar();
    }

    function exitSelectionMode() {
        _selectionActive = false;
        _selectedIds.clear();
        _lastSelectedIndex = null;

        const container = getKanbanContainer();
        if (container) {
            container.classList.remove('is-selection-mode');
            container.querySelectorAll('.file-box').forEach(b => {
                b.classList.remove('select-mode', 'selected');
            });
        }
        if (typeof BottomBar !== 'undefined') BottomBar.hide();
    }

    function selectBox(el) {
        const id = el.dataset.iddocumento;
        if (!id) return;
        _selectedIds.add(id);
        el.classList.add('selected');
    }

    function deselectBox(el) {
        const id = el.dataset.iddocumento;
        if (!id) return;
        _selectedIds.delete(id);
        el.classList.remove('selected');
    }

    function toggleSelectBox(el) {
        const id = el.dataset.iddocumento;
        if (!id) return;
        if (_selectedIds.has(id)) {
            deselectBox(el);
        } else {
            selectBox(el);
        }
    }

    function handleSelectionClick(box, shiftKey) {
        const idx = parseInt(box.dataset.index, 10);

        if (shiftKey && _lastSelectedIndex !== null && idx !== _lastSelectedIndex) {
            // Range select
            const lo = Math.min(_lastSelectedIndex, idx);
            const hi = Math.max(_lastSelectedIndex, idx);
            getAllBoxes().forEach(b => {
                const bi = parseInt(b.dataset.index, 10);
                if (bi >= lo && bi <= hi) selectBox(b);
            });
        } else {
            toggleSelectBox(box);
        }

        _lastSelectedIndex = idx;
        updateSelectionBar();
    }

    function updateSelectionBar() {
        if (typeof BottomBar === 'undefined') return;
        const n = _selectedIds.size;

        BottomBar.setConfig({
            statusText: n > 0 ? n + ' document' + (n === 1 ? 'o' : 'i') + ' selezionat' + (n === 1 ? 'o' : 'i') : 'Nessuna selezione',
            actions: [
                { id: 'sel-all',    label: 'Seleziona tutto', className: 'button-secondary' },
                { id: 'sel-none',   label: 'Deseleziona',     className: 'button-secondary', hidden: n === 0 },
                { id: 'sel-move',   label: 'Sposta (' + n + ')', className: 'button-primary', disabled: n === 0 },
                { id: 'sel-delete', label: 'Elimina (' + n + ')', className: 'button-danger', disabled: n === 0 },
                { id: 'sel-cancel', label: 'Annulla',         className: 'button-secondary' }
            ]
        });
    }

    // Click delegation (capture): in selection mode intercetta click sulle card
    document.addEventListener('click', function (e) {
        if (!_selectionActive) return;
        const box = e.target.closest('.file-box');
        if (!box) return;
        // Blocca anteprima e download
        e.preventDefault();
        e.stopPropagation();
        handleSelectionClick(box, e.shiftKey);
    }, true);

    // BottomBar actions
    document.addEventListener('bottomBar:action', async function (e) {
        const action = e.detail.actionId;
        if (!_selectionActive) return;

        if (action === 'sel-cancel') {
            exitSelectionMode();
        } else if (action === 'sel-all') {
            getAllBoxes().forEach(b => selectBox(b));
            _lastSelectedIndex = null;
            updateSelectionBar();
        } else if (action === 'sel-none') {
            getAllBoxes().forEach(b => deselectBox(b));
            _lastSelectedIndex = null;
            updateSelectionBar();
        } else if (action === 'sel-move') {
            const ids = Array.from(_selectedIds);
            if (ids.length === 0) return;
            showMoveModal(ids);
        } else if (action === 'sel-delete') {
            const ids = Array.from(_selectedIds);
            if (ids.length === 0) return;
            showConfirm('Eliminare <b>' + ids.length + '</b> document' + (ids.length === 1 ? 'o' : 'i') + '?', async function () {
                const res = await customFetch(currentSection, 'deleteDocumentiMultipli', { ids });
                if (res.success) {
                    const deletedSet = new Set((res.deleted || []).map(String));
                    getAllBoxes().forEach(b => {
                        if (deletedSet.has(b.dataset.iddocumento)) b.remove();
                    });
                    const nDel = (res.deleted || []).length;
                    const nFail = (res.failed || []).length;
                    showToast(nDel + ' eliminat' + (nDel === 1 ? 'o' : 'i') + (nFail > 0 ? ', ' + nFail + ' falliti' : ''), nFail > 0 ? 'error' : 'success');
                    exitSelectionMode();
                } else {
                    showToast(res.error || 'Errore eliminazione', 'error');
                }
            }, { allowHtml: true });
        }
    });

    // ESC per uscire da selection mode
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && _selectionActive) {
            exitSelectionMode();
        }
    });
    // ── FINE SELECTION MODE ────────────────────────────────────────
});

async function uploadDocumentiDM(section, slug, files) {
    if (!files || files.length === 0) {
        showToast('Seleziona almeno un file', 'info');
        return;
    }

    const titolo = document.getElementById('uploadTitoloDM')?.value.trim() || '';
    const descrizione = document.getElementById('uploadDescrizioneDM')?.value.trim() || '';

    const formData = new FormData();
    for (let f of files) formData.append('files[]', f);
    formData.append('slug', slug);
    formData.append('titolo', titolo);
    formData.append('descrizione', descrizione);
    if (currentFolder) formData.append('folder', currentFolder);

    const res = await customFetch(section, 'uploadDocumenti', formData, true);
    if (res.success) {
        showToast('Documenti caricati con successo', 'success');
        currentPage = 1;
        kanbanCurrentPage = 1;
        window.__dmDocumentsDirty = true;
        const kanbanContainer = document.getElementById('documenti-kanban');
        if (kanbanContainer && !kanbanContainer.classList.contains('hidden')) {
            await caricaDocumentiKanban(section, slug, 1, false);
        } else {
            caricaDocumentiDM(section, slug, 1, false);
        }
        setTimeout(() => {
            const filesInput = document.getElementById('uploadFilesDM');
            const titoloInput = document.getElementById('uploadTitoloDM');
            const descrizioneInput = document.getElementById('uploadDescrizioneDM');
            if (filesInput) filesInput.value = "";
            if (titoloInput) titoloInput.value = "";
            if (descrizioneInput) descrizioneInput.value = "";
        }, 500);
    } else {
        showToast(res.error || 'Errore nel caricamento', 'error');
    }
}

let currentPage = 1;
let currentSlug = null;
let currentSection = null; // sezione/documentArea attiva
let hasMoreDocuments = false;
let currentDocumentsList = [];
let currentFolder = null; // null = root, stringa = nome cartella

/**
 * Riposiziona .table-pagination subito dopo il contenitore attivo.
 * - activeView = 'table'  → dopo .table-wrapper
 * - activeView = 'kanban' → dopo #documenti-kanban
 * Usa insertBefore: sposta il nodo esistente senza duplicarlo.
 */
function placeDmPagination(activeView) {
    const pagination = document.querySelector('.table-pagination');
    if (!pagination) return;

    let reference = null;
    if (activeView === 'table') {
        reference = document.querySelector('.table-wrapper');
    } else {
        reference = document.getElementById('documenti-kanban');
    }
    if (!reference || !reference.parentNode) return;

    // Sposta solo se già non è nel posto corretto
    if (pagination.previousElementSibling !== reference) {
        reference.parentNode.insertBefore(pagination, reference.nextSibling);
    }
}
window.placeDmPagination = placeDmPagination;

async function caricaDocumentiDM(section, slug, page = 1, append = false) {
    currentSlug = slug;
    currentSection = section;
    currentPage = page;
    const limit = parseInt(localStorage.getItem('dm_page_size') || 50, 10);

    const urlParams = new URLSearchParams(window.location.search);
    const debugNc = urlParams.get('debug_nc') === '1';

    const fetchParams = { slug, page, limit: limit, debug_nc: debugNc ? 1 : 0 };
    if (currentFolder) fetchParams.folder = currentFolder;

    const res = await customFetch(section, 'getDocumenti', fetchParams);
    if (!res.success) {
        showToast(res.error || 'Errore nel caricamento documenti', 'error');
        return;
    }

    // --- DEBUG NEXTCLOUD ---
    if (res.debug) {
        console.group("Nextcloud Sync Debug [" + slug + "]");
        console.log("Local Path:", res.debug.path);
        console.log("Exists:", res.debug.exists ? "YES" : "NO");
        console.log("Remote Files:", res.debug.remoteCountRaw);
        console.log("Inserted:", res.debug.inserted);
        if (res.debug.error) console.error("Sync Error:", res.debug.error);
        if (res.debug.logs && res.debug.logs.length) {
            console.groupCollapsed("Detailed Logs");
            res.debug.logs.forEach(l => console.log(l));
            console.groupEnd();
        }
        console.groupEnd();
    }
    // -----------------------

    const tbody = document.getElementById('documenti-list');
    const kanban = document.getElementById('documenti-kanban');

    if (!append) {
        tbody.innerHTML = '';
        if (kanban) kanban.innerHTML = '';
        window.currentDocumentsList = res.data;

        // Nella vista tabella le cartelle sono già nelle righe: nascondi il grid
        const foldersGrid = document.getElementById('dm-folders-grid');
        if (foldersGrid) foldersGrid.style.display = 'none';
    } else {
        window.currentDocumentsList = (window.currentDocumentsList || []).concat(res.data);
    }

    hasMoreDocuments = res.pagination && res.pagination.hasMore === true;

    // --- RIGHE CARTELLE in tabella (solo root, solo prima pagina) ---
    if (!currentFolder && !append) {
        try {
            const foldersRes = await customFetch(section, 'listFolders', { slug });
            if (foldersRes.success && foldersRes.folders && foldersRes.folders.length > 0) {
                foldersRes.folders.forEach(f => {
                    const tr = document.createElement('tr');
                    tr.className = 'folder-row';
                    tr.style.cursor = 'pointer';
                    tr.onclick = () => navigateToFolder(section, slug, f.name);

                    const tdAzioni = document.createElement('td');
                    tdAzioni.className = 'azioni-colonna';
                    const openBtn = document.createElement('button');
                    openBtn.className = 'action-icon';
                    openBtn.setAttribute('data-tooltip', 'Apri cartella');
                    openBtn.innerHTML = '<img src="assets/icons/right-arrow.png" alt="Apri" style="width:16px;height:16px;">';
                    openBtn.onclick = (e) => { e.stopPropagation(); navigateToFolder(section, slug, f.name); };
                    tdAzioni.appendChild(openBtn);
                    tr.appendChild(tdAzioni);

                    const tdTitolo = document.createElement('td');
                    tdTitolo.style.fontWeight = '500';
                    tdTitolo.innerHTML = '<img src="assets/icons/file_folder.png" alt="Cartella" style="width:20px;height:20px;margin-right:8px;vertical-align:middle;object-fit:contain;"> <span style="vertical-align:middle;">' + _escHtml(f.name) + '</span>';
                    tr.appendChild(tdTitolo);

                    const tdDesc = document.createElement('td');
                    tdDesc.textContent = 'Cartella';
                    tdDesc.style.color = '#888';
                    tr.appendChild(tdDesc);

                    const tdCreazione = document.createElement('td');
                    tdCreazione.textContent = '\u2014';
                    tr.appendChild(tdCreazione);

                    const tdData = document.createElement('td');
                    tdData.textContent = f.last_modified || '\u2014';
                    tr.appendChild(tdData);

                    tbody.appendChild(tr);
                });
            }
        } catch (e) {
            console.warn('Errore caricamento cartelle in tabella:', e);
        }
    }

    res.data.forEach(doc => {
        currentDocumentsList.push({
            id: doc.id,
            file_url: doc.file_url || '',
            nome_file: doc.nome_file || '',
            titolo: doc.titolo || doc.nome_file || '',
            descrizione: doc.descrizione || '',
            data_caricamento: doc.data_caricamento,
            size: doc.size,
            mime_type: doc.mime_type
        });

        const titolo = doc.titolo || doc.nome_file;
        const descrizione = doc.descrizione || '';
        const dataCaricamento = doc.data_caricamento_formattata || doc.data_caricamento;
        const fileUrl = doc.file_url || '';

        const tr = document.createElement('tr');
        tr.className = 'file-row';

        const azioniTd = document.createElement('td');
        azioniTd.className = 'azioni-colonna';

        const downloadBtn = document.createElement('a');
        downloadBtn.href = fileUrl;
        downloadBtn.className = 'action-icon';
        downloadBtn.download = '';
        downloadBtn.setAttribute('data-tooltip', 'Scarica');
        downloadBtn.innerHTML = '<img src="assets/icons/download.png" alt="Scarica">';
        azioniTd.appendChild(downloadBtn);

        if (typeof window.userHasPermission === 'function' && window.userHasPermission('manage_' + section)) {
            const delBtn = document.createElement('button');
            delBtn.className = 'action-icon';
            delBtn.setAttribute('data-tooltip', 'Elimina');
            delBtn.innerHTML = '<img src="assets/icons/delete.png" alt="Elimina">';
            delBtn.onclick = () => deleteDocumentoDM(section, doc.id, tr);
            azioniTd.appendChild(delBtn);
        }

        tr.appendChild(azioniTd);

        const tdTitolo = document.createElement('td');
        const ext = doc.nome_file.split('.').pop().toLowerCase();
        let iconSrc = 'assets/icons/file_doc.png';

        if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/image.png';
        } else if (/\.(pdf)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/file_pdf.png';
        } else if (/\.(docx?|odt)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/file_word.png';
        } else if (/\.(xlsx?|ods|csv)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/file_excel.png';
        } else if (/\.(zip|rar|7z|tar|gz)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/file_zip.png';
        } else if (/\.(txt|md|log)$/i.test(doc.nome_file)) {
            iconSrc = 'assets/icons/doc.png';
        }

        tdTitolo.innerHTML = `<img src="${iconSrc}" alt="${ext}" style="width:20px;height:auto;margin-right:8px;vertical-align:middle;object-fit:contain;"> <span style="vertical-align:middle;">${titolo}</span>`;
        tdTitolo.style.cursor = 'pointer';
        tdTitolo.style.fontWeight = '500';
        tdTitolo.style.color = '#2567c7';
        tdTitolo.style.textDecoration = 'none';
        tdTitolo.onmouseover = function () { this.querySelector('span').style.textDecoration = 'underline'; };
        tdTitolo.onmouseout = function () { this.querySelector('span').style.textDecoration = 'none'; };

        tdTitolo.onclick = () => {
            if (typeof window.showMediaViewer === 'function') {
                window.showMediaViewer(fileUrl, {
                    id_documento: doc.id,
                    nome_file: doc.nome_file,
                    titolo: titolo,
                    descrizione: descrizione,
                    dataIT: dataCaricamento,
                    mime_type: doc.mime_type
                });
            } else {
                window.open(fileUrl, '_blank');
            }
        };

        tr.appendChild(tdTitolo);

        const tdDesc = document.createElement('td');
        tdDesc.textContent = descrizione;
        tr.appendChild(tdDesc);

        const tdDataCreazione = document.createElement('td');
        tdDataCreazione.textContent = doc.data_creazione || '';
        tr.appendChild(tdDataCreazione);

        const tdData = document.createElement('td');
        tdData.textContent = dataCaricamento;
        tr.appendChild(tdData);

        tbody.appendChild(tr);
    });

    // Riposiziona la paginazione sotto la tabella (non tra folders-grid e kanban)
    placeDmPagination('table');

    // Aggiorna paginazione client-side se già inizializzata
    const dmTable = document.getElementById('documentiTable');
    if (dmTable && dmTable._paginationUpdateView) {
        dmTable._paginationUpdateView();
    } else if (dmTable && typeof window.initClientSidePagination === 'function') {
        if (dmTable.dataset.paginationInitialized !== 'true') {
            window.initClientSidePagination(dmTable);
        }
    }
}
window.caricaDocumentiDM = caricaDocumentiDM;

function deleteDocumentoDM(section, id, trElement) {
    showConfirm('Sei sicuro di voler eliminare questo documento?', async function () {
        const res = await customFetch(section, 'deleteDocumento', { id });
        if (res.success) {
            if (trElement) trElement.remove();
            showToast('Documento eliminato', 'success');
        } else {
            showToast(res.error || 'Errore eliminazione', 'error');
        }
    });
}

function setupDMSwitchView() {
    // Implementazione semplificata dello switch, simile a archivio_switch_view ma generica
    // Qui andrebbe logica per bottone switch table/grid
    // Assumiamo che ci sia un gestore eventi globale o inline per ora
}

function resetDMModalEdit() {
    const form = document.getElementById('formNuovaPagina');
    if (form) {
        form.reset();
        const hidden = form.querySelector('input[name="original_slug"]');
        if (hidden) hidden.value = '';
        const imgPath = document.getElementById('immaginePath');
        if (imgPath) imgPath.value = '';
        const preview = document.getElementById('previewThumb');
        if (preview) preview.innerHTML = '';
        const modalTitle = document.querySelector('#modalNuovaPagina .modal-header h3');
        if (modalTitle) modalTitle.textContent = 'Crea nuova pagina';
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.textContent = 'Crea pagina';
    }
}

function openEditDMModal(data) {
    const modal = document.getElementById('modalNuovaPagina');
    const form = document.getElementById('formNuovaPagina');
    if (!modal || !form) return;

    resetDMModalEdit();

    const title = document.querySelector('#modalNuovaPagina .modal-header h3');
    if (title) title.textContent = 'Modifica pagina';
    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.textContent = 'Salva modifiche';

    // Popola campi
    if (form.titolo) form.titolo.value = data.titolo || '';
    if (form.slug) form.slug.value = data.slug || '';
    if (form.descrizione) form.descrizione.value = data.descrizione || '';
    if (form.colore) form.colore.value = data.colore || '#4caf50';

    // Immpagine
    const imgPath = document.getElementById('immaginePath');
    const preview = document.getElementById('previewThumb');
    if (imgPath) imgPath.value = data.immagine || '';
    if (preview && data.immagine) {
        preview.innerHTML = `<img src="${data.immagine}" alt="Preview" style="max-width:90px;height:auto;margin-top:7px;border-radius:7px;">`;
    }

    // Menu title
    const menuSelect = document.getElementById('menuSelect');
    if (menuSelect && data.menu_title) {
        menuSelect.value = data.menu_title;
    }

    // Hidden original slug
    let hidden = form.querySelector('input[name="original_slug"]');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'original_slug';
        form.appendChild(hidden);
    }
    hidden.value = data.slug;

    if (typeof window.openModal === 'function') {
        window.openModal('modalNuovaPagina');
    } else if (typeof window.toggleModal === 'function') {
        window.toggleModal('modalNuovaPagina', 'open');
    } else {
        modal.style.display = 'block';
    }
}

// Helper globale per aprire modali (se non già definito altrove)
if (typeof window.openModal !== 'function') {
    window.openModal = function (id) {
        if (typeof window.toggleModal === 'function') {
            window.toggleModal(id, 'open');
        } else {
            const m = document.getElementById(id);
            if (m) m.style.display = 'block';
        }
    };
}
if (typeof window.closeModal !== 'function') {
    window.closeModal = function (id) {
        if (typeof window.toggleModal === 'function') {
            window.toggleModal(id, 'close');
        } else {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }
    };
}

// ... Altre funzioni helper (kanban, openWordPreview, etc) possono essere portate o lasciate globali se già esistono
// Per brevità ometto funzioni duplicate identiche se presenti in main_core.js o simili, ma aggiungo placeholder

async function caricaDocumentiKanban(section, slug, page, append) {
    const container = document.getElementById('documenti-kanban');
    if (!container) return;

    currentSlug = slug;
    currentSection = section;

    // Se non append, pulisci
    if (!append) {
        container.innerHTML = '';
        window.kanbanCurrentPage = 1;

        // Nel kanban il grid cartelle è visibile solo in root
        const foldersGrid = document.getElementById('dm-folders-grid');
        if (foldersGrid) foldersGrid.style.display = currentFolder ? 'none' : 'flex';
    } else {
        window.kanbanCurrentPage = page;
    }

    // Loader se non append
    if (!append) {
        container.innerHTML = '<div class="loader-spinner"></div>';
    } else {
        const loadMore = container.querySelector('.load-more-btn');
        if (loadMore) loadMore.remove();
    }

    const limit = parseInt(localStorage.getItem('dm_page_size') || 50, 10);
    const kanbanParams = { slug, page, limit: limit };
    if (currentFolder) kanbanParams.folder = currentFolder;
    const res = await customFetch(section, 'getDocumenti', kanbanParams);

    if (!append) container.innerHTML = ''; // Rimuovi loader

    if (res.success && Array.isArray(res.data)) {
        if (res.data.length === 0 && !append) {
            container.innerHTML = '<div class="empty-state">Nessun documento trovato.</div>';
            return;
        }

        const escapeFn = (text) => {
            if (!text) return '';
            return text.replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        };

        const cardsHTML = [];

        // Aggiorna la lista globale per il viewer
        if (!append) {
            window.currentDocumentsList = res.data;
        } else {
            // In append mode, aggiungi alla lista esistente
            window.currentDocumentsList = (window.currentDocumentsList || []).concat(res.data);
        }

        // Indice base: offset per append (card già presenti nel container)
        const indexOffset = append ? container.querySelectorAll('.file-box').length : 0;

        res.data.forEach((doc, i) => {
            const cardIndex = indexOffset + i;
            const titolo = doc.titolo?.trim() || doc.nome_file || '';
            const titoloDisplay = titolo.length > 25 ? titolo.substr(0, 22) + '...' : titolo;
            const descrizione = doc.descrizione?.trim() || '';
            const dataIT = doc.data_caricamento_formattata || doc.data_caricamento;
            const fileUrl = doc.file_url || '';
            const ext = doc.nome_file.split('.').pop().toLowerCase();

            // Preview Logic
            let preview = '';
            const flexCenter = 'display:flex;align-items:center;justify-content:center;height:100%;';
            const iconStyle = 'width:64px;height:64px;object-fit:contain;';

            if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(doc.nome_file)) {
                const imageUrl = doc.thumb || fileUrl;
                preview = `<img src="${imageUrl}" class="file-thumb" style="width: 100%; height: 100%; object-fit: cover;" onerror="handleMissingDocumentDM(this, '${doc.id}')">`;
            } else if (/\.(pdf)$/i.test(doc.nome_file)) {
                preview = `<div class="file-thumb file-thumb-pdf file-preview-click" data-type="pdf" data-src="${fileUrl}" data-tooltip="Apri PDF" style="${flexCenter}">
                            <img src="assets/icons/file_pdf.png" style="${iconStyle}">
                           </div>`;
            } else if (/\.(docx?|odt)$/i.test(doc.nome_file)) {
                preview = `<div class="file-thumb file-thumb-doc file-preview-click" data-type="word" data-src="${fileUrl}" data-tooltip="Visualizza documento Word" style="${flexCenter}">
                            <img src="assets/icons/file_word.png" style="${iconStyle}">
                           </div>`;
            } else if (/\.(xlsx?|ods|csv)$/i.test(doc.nome_file)) {
                preview = `<div class="file-icon file-xls" style="${flexCenter}">
                            <img src="assets/icons/file_excel.png" style="${iconStyle}">
                           </div>`;
            } else if (/\.(zip|rar|7z|tar|gz)$/i.test(doc.nome_file)) {
                preview = `<div class="file-icon" style="${flexCenter}">
                            <img src="assets/icons/file_zip.png" style="${iconStyle}">
                           </div>`;
            } else {
                preview = `<div class="file-icon" style="${flexCenter}">
                            <img src="assets/icons/file_doc.png" style="${iconStyle}opacity:0.6;">
                           </div>`;
            }

            // COSTRUZIONE CARD COMPLETA (stile archivio)
            cardsHTML.push(`
            <div class="file-box" tabindex="0" data-index="${cardIndex}" data-tooltip="${escapeFn(doc.nome_file || '')}" data-iddocumento="${doc.id}" data-titolo="${escapeFn(titolo)}" data-descrizione="${escapeFn(descrizione)}" data-fileurl="${escapeFn(fileUrl)}">
                <div class="file-preview file-preview-click" data-src="${escapeFn(fileUrl)}" data-ext="${escapeFn(ext)}" data-nome="${escapeFn(doc.nome_file || '')}" data-tooltip="Clic per anteprima">
                    ${preview}
                </div>
                <div class="file-name" data-tooltip="${escapeFn(titolo)}">
                    ${escapeFn(titoloDisplay)}
                </div>
                ${descrizione ? `<div class="file-desc" data-tooltip="Descrizione">${escapeFn(descrizione)}</div>` : ''}
                <div class="file-date">${escapeFn(dataIT)}</div>
                
                <div class="file-actions" style="margin-top: 10px; display: flex; justify-content: center; gap: 8px;">
                     <!-- Azioni inline per semplicità, ma i context menu globali funzionano su .file-box -->
                     <a href="${fileUrl}" download class="action-btn" style="color:#555;" title="Scarica"><i class="fa fa-download"></i></a>
                     ${(fileUrl.endsWith('.docx')) ? `<button class="action-btn" onclick="openWordPreview('${section}', '${fileUrl}', '${escapeFn(titolo)}')" style="background:none;border:none;cursor:pointer;color:#337ab7;" title="Anteprima"><i class="fa fa-eye"></i></button>` : ''}
                </div>
            </div>
            `);
        });

        const gridHtml = cardsHTML.join('');
        if (append) {
            container.insertAdjacentHTML('beforeend', gridHtml);
        } else {
            container.innerHTML = gridHtml;
        }

        // AGGIUNGI EVENT LISTENERS PER ANTEPRIME
        // (Necessario perché gli elementi sono creati dinamicamente)
        const previews = container.querySelectorAll('.file-preview-click');
        previews.forEach(el => {
            el.style.cursor = 'pointer';
            el.onclick = function (e) {
                e.preventDefault();
                e.stopPropagation();

                // Recupera dati dai data-attributes
                const src = el.dataset.src;
                const type = el.dataset.type;
                const nome = decodeURIComponent(el.dataset.nome || '');
                const idDoc = el.closest('.file-box')?.dataset.iddocumento;

                // Fallback titolo/desc
                const box = el.closest('.file-box');
                const titolo = box?.querySelector('.file-name')?.textContent?.trim() || nome;
                const descrizione = box?.querySelector('.file-desc')?.textContent?.trim() || '';
                const dataIT = box?.querySelector('.file-date')?.textContent?.trim() || '';

                if (typeof window.showMediaViewer === 'function') {
                    window.showMediaViewer(src, {
                        id_documento: idDoc,
                        nome_file: nome,
                        titolo: titolo,
                        descrizione: descrizione,
                        dataIT: dataIT,
                        mime_type: type === 'pdf' ? 'application/pdf' : (type === 'word' ? 'application/msword' : 'image/jpeg') // approx
                    });
                } else {
                    console.error("window.showMediaViewer non definita");
                    // Fallback se viewer non esiste: apri in nuova tab
                    window.open(src, '_blank');
                }
            };
        });

        // Load More button
        if (res.pagination && res.pagination.hasMore) {
            const btnContainer = document.createElement('div');
            btnContainer.className = 'load-more-container';
            btnContainer.id = 'load-more-dm';
            btnContainer.style.width = '100%';
            btnContainer.style.textAlign = 'center';
            btnContainer.style.marginTop = '20px';
            btnContainer.style.clear = 'both';

            const btn = document.createElement('button');
            btn.className = 'button button-secondary load-more-btn';
            btn.textContent = 'Carica altri';
            btn.onclick = () => {
                document.getElementById('load-more-dm')?.remove();
                caricaDocumentiKanban(section, slug, page + 1, true);
            };

            btnContainer.appendChild(btn);
            container.appendChild(btnContainer);
        }
    } else {
        if (!append) container.innerHTML = '<div class="error-state">Errore caricamento.</div>';
    }

    // Riposiziona la paginazione sotto il kanban (non tra folders-grid e kanban)
    if (!append) {
        placeDmPagination('kanban');
    }
}
window.caricaDocumentiKanban = caricaDocumentiKanban;

window.handleMissingDocumentDM = function (imgEl, id) {
    const section = (window.documentArea && window.documentArea.id) || window.currentSection || 'archivio';
    console.warn(`[DocumentManager] File non trovato: id=${id}, sezione=${section}. Segnalo al server...`);

    // Rimuovi la card visivamente
    const card = imgEl.closest('.file-box') || imgEl.closest('.file-row');
    if (card) {
        card.style.opacity = '0.5';
        card.style.pointerEvents = 'none';
        setTimeout(() => card.remove(), 2000);
    }

    // Segnala al server
    customFetch(section, 'markMissingDocumento', { id }).then(res => {
        if (res.success) {
            console.log(`[DocumentManager] Documento ${id} marcato come mancante.`);
        }
    });
};

// ── CARTELLE (Directory su Nextcloud) ─────────────────────────────
/**
 * Carica e mostra le cartelle della pagina corrente.
 */
// ── MODALE SPOSTA FILE ──────────────────────────────────────────
async function showMoveModal(ids) {
    if (!ids || ids.length === 0) return;

    const section = currentSection || (window.documentArea && window.documentArea.id) || 'archivio';
    const slug = currentSlug;
    if (!slug) {
        showToast('Pagina non identificata, ricarica la vista', 'error');
        return;
    }

    // Carica cartelle disponibili
    const foldersRes = await customFetch(section, 'listFolders', { slug });
    const folders = (foldersRes.success && foldersRes.folders) ? foldersRes.folders : [];

    // Rimuovi eventuale modale precedente
    document.getElementById('dm-move-modal')?.remove();

    const overlay = document.createElement('div');
    overlay.id = 'dm-move-modal';
    overlay.className = 'custom-confirm-overlay';

    // Se in root: root è disabled (già ci sei), le cartelle sono selezionabili
    // Se in una cartella: root è selezionata per default, le altre cartelle sono selezionabili (non quella corrente)
    const inRoot = !currentFolder;

    // Controlla se ci sono destinazioni disponibili
    const availableDestinations = inRoot
        ? folders.filter(f => f.name !== currentFolder)
        : folders.filter(f => f.name !== currentFolder).concat([{ _isRoot: true }]);

    if (availableDestinations.length === 0 || (inRoot && folders.length === 0)) {
        showToast('Nessuna cartella disponibile verso cui spostare i file', 'info');
        return;
    }

    // Prima cartella selezionabile (per pre-selezione quando in root)
    const firstAvailableFolder = folders.find(f => f.name !== currentFolder);

    let optionsHtml = '<label style="display:block;padding:6px 0;cursor:pointer;">' +
        '<input type="radio" name="dm-move-dest" value="" ' +
        (inRoot ? 'disabled' : 'checked') +
        '> ' +
        '<img src="assets/icons/file_folder.png" style="width:18px;height:18px;vertical-align:middle;margin-right:4px;"> Root pagina' +
        (inRoot ? ' <span style="color:#999;">(posizione attuale)</span>' : '') +
        '</label>';

    folders.forEach((f, idx) => {
        const isCurrent = (currentFolder === f.name);
        // Pre-seleziona la prima cartella disponibile se si è in root
        const isPreSelected = inRoot && !isCurrent && firstAvailableFolder && f.name === firstAvailableFolder.name;
        optionsHtml += '<label style="display:block;padding:6px 0;cursor:pointer;">' +
            '<input type="radio" name="dm-move-dest" value="' + _escHtml(f.name) + '" ' +
            (isCurrent ? 'disabled' : (isPreSelected ? 'checked' : '')) + '> ' +
            '<img src="assets/icons/file_folder.png" style="width:18px;height:18px;vertical-align:middle;margin-right:4px;"> ' +
            _escHtml(f.name) + (isCurrent ? ' <span style="color:#999;">(corrente)</span>' : '') + '</label>';
    });

    overlay.innerHTML =
        '<div class="custom-confirm-box" style="min-width:320px;max-width:420px;">' +
            '<h3 style="margin:0 0 12px 0;font-size:16px;">Sposta ' + ids.length + ' file</h3>' +
            '<div style="max-height:250px;overflow-y:auto;margin-bottom:15px;">' + optionsHtml + '</div>' +
            '<div class="custom-confirm-buttons">' +
                '<button class="button confirm-ok">Sposta</button>' +
                '<button class="button confirm-cancel">Annulla</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    overlay.querySelector('.confirm-cancel').addEventListener('click', () => overlay.remove());

    overlay.querySelector('.confirm-ok').addEventListener('click', async () => {
        const checked = overlay.querySelector('input[name="dm-move-dest"]:checked');
        if (!checked) {
            showToast('Seleziona una destinazione', 'info');
            return;
        }
        const destination = checked.value || null; // '' → null (root)
        overlay.remove();

        const res = await customFetch(section, 'moveDocumenti', { slug, ids, destination });
        if (res.success) {
            const nMoved = (res.moved || []).length;
            const nFail = (res.failed || []).length;
            showToast(nMoved + ' file spostat' + (nMoved === 1 ? 'o' : 'i') + (nFail > 0 ? ', ' + nFail + ' falliti' : ''), nFail > 0 ? 'error' : 'success');

            if (typeof exitSelectionMode === 'function' && _selectionActive) exitSelectionMode();

            // Refresh vista corrente
            window.__dmDocumentsDirty = true;
            currentPage = 1;
            const kanbanContainer = document.getElementById('documenti-kanban');
            if (kanbanContainer && !kanbanContainer.classList.contains('hidden')) {
                caricaDocumentiKanban(section, slug, 1, false);
            } else {
                caricaDocumentiDM(section, slug, 1, false);
            }
        } else {
            showToast(res.error || 'Errore spostamento', 'error');
        }
    });
}
window.showMoveModal = showMoveModal;

async function caricaCartelleDM(section, slug) {
    const grid = document.getElementById('dm-folders-grid');
    if (!grid) return;

    const res = await customFetch(section, 'listFolders', { slug });
    grid.innerHTML = '';

    if (!res.success) return;

    const folders = res.folders || [];

    // Rendi le card cartella
    folders.forEach(f => {
        const card = document.createElement('div');
        card.className = 'dm-folder-card';
        card.setAttribute('data-tooltip', f.name);
        card.setAttribute('data-foldername', f.name);
        card.innerHTML = `<img src="/assets/icons/file_folder.png" alt="Cartella" style="width:36px;height:36px;object-fit:contain;">
            <span class="dm-folder-name">${_escHtml(f.name)}</span>`;
        card.addEventListener('click', () => navigateToFolder(section, slug, f.name));
        grid.appendChild(card);
    });

    // Card "+ Nuova cartella" (solo se canManage)
    const _dmArea = window.documentArea || {};
    if (_dmArea.permissions && _dmArea.permissions.manage) {
        const addCard = document.createElement('div');
        addCard.className = 'dm-folder-card dm-folder-add';
        addCard.setAttribute('data-tooltip', 'Crea nuova cartella');
        addCard.innerHTML = `<img src="/assets/icons/file_folder.png" alt="Nuova cartella" style="width:36px;height:36px;object-fit:contain;opacity:.5;">
            <span class="dm-folder-name" style="color:#999;">+ Nuova</span>`;
        addCard.addEventListener('click', () => promptCreateFolder(section, slug));
        grid.appendChild(addCard);
    }

    // Mostra breadcrumb
    updateFolderBreadcrumb(section, slug, null);
}

/**
 * Naviga in una cartella: aggiorna contesto, ricarica file.
 */
function navigateToFolder(section, slug, folderName) {
    currentFolder = folderName || null;
    updateFolderBreadcrumb(section, slug, currentFolder);

    // Nascondi grid cartelle quando siamo dentro una cartella
    const grid = document.getElementById('dm-folders-grid');
    if (grid) grid.style.display = currentFolder ? 'none' : 'flex';

    // Ricarica file con contesto cartella
    currentPage = 1;
    const kanbanContainer = document.getElementById('documenti-kanban');
    if (kanbanContainer && !kanbanContainer.classList.contains('hidden')) {
        caricaDocumentiKanban(section, slug, 1, false);
    } else {
        caricaDocumentiDM(section, slug, 1, false);
    }
}

/**
 * Aggiorna il breadcrumb in base alla cartella corrente.
 */
function updateFolderBreadcrumb(section, slug, folder) {
    const bc = document.getElementById('dm-folder-breadcrumb');
    const sep = document.getElementById('dm-breadcrumb-separator');
    const folderEl = document.getElementById('dm-breadcrumb-folder');
    const rootLink = document.getElementById('dm-breadcrumb-root');
    const globalBreadcrumbUl = document.querySelector('#breadcrumb ul');

    if (!bc) return;

    if (folder) {
        if (!globalBreadcrumbUl) {
            bc.style.display = 'block';
        }
        sep.style.display = 'inline';
        folderEl.style.display = 'inline';
        folderEl.textContent = folder;

        // Click su root: torna alla root
        rootLink.onclick = (e) => {
            e.preventDefault();
            navigateToFolder(section, slug, null);
        };
        rootLink.style.cursor = 'pointer';
        rootLink.style.textDecoration = 'underline';

        // Sincronizza il breadcrumb globale
        if (globalBreadcrumbUl) {
            // Rimuovi eventuali nodi cartella aggiunti in precedenza
            globalBreadcrumbUl.querySelectorAll('.dm-global-folder').forEach(el => el.remove());
            
            // Rendi la pagina root cliccabile (l'ultimo LI originale)
            const lis = globalBreadcrumbUl.querySelectorAll('li');
            const lastLi = lis.length > 0 ? lis[lis.length - 1] : null;

            if (lastLi && !lastLi.classList.contains('separator')) {
                 const currentSpan = lastLi.querySelector('span.current');
                 if (currentSpan) {
                     if (!lastLi.hasAttribute('data-original-text')) {
                         lastLi.setAttribute('data-original-text', currentSpan.textContent);
                     }
                     // Trasforma span.current in un link
                     lastLi.innerHTML = `<a href="#" onclick="event.preventDefault(); navigateToFolder('${section}', '${slug}', null);">${lastLi.getAttribute('data-original-text')}</a>`;
                 }
            }

            // Aggiungi la cartella corrente al breadcrumb globale
            const separatorLi = document.createElement('li');
            separatorLi.className = 'separator dm-global-folder';
            separatorLi.textContent = '/';
            
            const folderLi = document.createElement('li');
            folderLi.className = 'dm-global-folder';
            folderLi.innerHTML = `<span class="current">${_escHtml(folder)}</span>`;

            globalBreadcrumbUl.appendChild(separatorLi);
            globalBreadcrumbUl.appendChild(folderLi);
        }

    } else {
        // In root: breadcrumb locale nascosto
        bc.style.display = 'none';
        sep.style.display = 'none';
        folderEl.style.display = 'none';

        // Ripristina il breadcrumb globale
        if (globalBreadcrumbUl) {
            globalBreadcrumbUl.querySelectorAll('.dm-global-folder').forEach(el => el.remove());

            const lis = globalBreadcrumbUl.querySelectorAll('li');
            const lastLi = lis.length > 0 ? lis[lis.length - 1] : null;
            if (lastLi && lastLi.hasAttribute('data-original-text')) {
                 lastLi.innerHTML = `<span class="current">${lastLi.getAttribute('data-original-text')}</span>`;
                 lastLi.removeAttribute('data-original-text');
            }
        }
    }
}

/**
 * Prompt per creare una nuova cartella.
 */
async function promptCreateFolder(section, slug) {
    window.showPrompt('Nome della nuova cartella:', '', async function(name) {
        if (!name || !name.trim()) return;

        const folderName = name.trim();
        // Validazione client-side base
        if (folderName.includes('/') || folderName.includes('\\') || folderName.includes('..') || folderName.length > 100) {
            showToast('Nome cartella non valido', 'error');
            return;
        }

        const res = await customFetch(section, 'createFolder', { slug, folder: folderName });
        if (res.success) {
            showToast('Cartella creata', 'success');
            caricaCartelleDM(section, slug);
        } else {
            showToast(res.error || 'Errore nella creazione della cartella', 'error');
        }
    });
}

function _escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

window.caricaCartelleDM = caricaCartelleDM;
window.navigateToFolder = navigateToFolder;
// ── FINE CARTELLE ─────────────────────────────────────────────────

function openWordPreview(section, path, title) {
    // ... Logica preview word
}

function openEditDocumentModal(section, doc) {
    // ... Logica edit doc
}

// Reattività cambio numero item per pagina (Document Manager)
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('pagination-page-size')) {
        const newLimit = parseInt(e.target.value, 10);
        if (newLimit && !isNaN(newLimit)) {
            localStorage.setItem('dm_page_size', newLimit);
            
            // Sincronizza visivamente le eventuali altre select
            document.querySelectorAll('.pagination-page-size').forEach(sel => {
                if (sel !== e.target) sel.value = newLimit;
            });

            if (typeof window.reloadCurrentDMView === 'function') {
                window.reloadCurrentDMView();
            }
        }
    }
});

function reloadCurrentDMView() {
    const slug = currentSlug || (new URLSearchParams(window.location.search)).get('page');
    const section = currentSection || (window.documentArea && window.documentArea.id) || 'archivio';
    if (!slug) return;
    
    const kanbanContainer = document.getElementById('documenti-kanban');
    const isKanbanVisible = kanbanContainer && !kanbanContainer.classList.contains('hidden');
    
    window.__dmDocumentsDirty = true;
    
    if (isKanbanVisible) {
        if (typeof window.caricaDocumentiKanban === 'function') window.caricaDocumentiKanban(section, slug, 1, false);
    } else {
        if (typeof window.caricaDocumentiDM === 'function') window.caricaDocumentiDM(section, slug, 1, false);
    }
}
window.reloadCurrentDMView = reloadCurrentDMView;

