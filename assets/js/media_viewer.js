// Assicura che currentDocumentsList esista globalmente per la navigazione
window.currentDocumentsList = window.currentDocumentsList || [];

window.showMediaViewer = function (path, opts = {}) {
    try {
        // Normalizza il path: assicura che inizi con / per accesso HTTP, ma non per ajax.php relativi
        if (path && typeof path === 'string' && path.charAt(0) !== '/' && path.indexOf('ajax.php') === -1) {
            path = '/' + path;
        }
        let modal = document.getElementById("media-viewer-modal");

        // Funzione per navigare tra i file (definita fuori dal blocco if per essere sempre disponibile)
        const navigateToFile = function (direction) {
            const currentList = window.currentDocumentsList || [];
            if (currentList.length === 0) return;

            // Trova l'indice del file corrente usando i dati salvati nel modale
            const currentDocId = modal._currentDocId || opts.id_documento;
            const currentDocPath = modal._currentDocPath || path;

            let currentIndex = currentList.findIndex(doc => {
                const docPath = doc.file_url || '';
                const normalizedCurrentPath = currentDocPath.charAt(0) !== '/' ? '/' + currentDocPath : currentDocPath;
                return docPath === normalizedCurrentPath || doc.id == currentDocId; // uso == per compatibilità string/int
            });

            if (currentIndex === -1) {
                // Fallback: prova a cercare solo per path
                const normalizedCurrentPath = currentDocPath.charAt(0) !== '/' ? '/' + currentDocPath : currentDocPath;
                currentIndex = currentList.findIndex(doc => {
                    const docPath = doc.file_url || '';
                    return docPath === normalizedCurrentPath;
                });
            }

            if (currentIndex === -1) return;

            // Calcola il nuovo indice
            const newIndex = currentIndex + direction;
            if (newIndex < 0 || newIndex >= currentList.length) return;

            // Carica il nuovo file
            const nextDoc = currentList[newIndex];
            const formatDate = function (dateStr) {
                if (!dateStr) return '';
                try {
                    const d = new Date(dateStr);
                    return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
                } catch (e) {
                    return dateStr;
                }
            };

            // file_url del nuovo documento
            let nextDocPath = nextDoc.file_url || '';
            if (!nextDocPath) {
                console.warn('Missing file_url for document id=' + (nextDoc.id || '?'));
                return;
            }
            if (nextDocPath.charAt(0) !== '/' && nextDocPath.indexOf('ajax.php') === -1) {
                nextDocPath = '/' + nextDocPath;
            }

            let sizeStr = '';
            if (nextDoc.size) {
                sizeStr = (Math.round(nextDoc.size / 1024) + " KB");
            }

            window.showMediaViewer(nextDocPath, {
                id_documento: nextDoc.id,
                nome_file: nextDoc.nome_file || '',
                titolo: nextDoc.titolo || nextDoc.nome_file || '',
                descrizione: nextDoc.descrizione || '',
                dataIT: formatDate(nextDoc.data_caricamento),
                size: sizeStr,
                mime_type: nextDoc.mime_type || ''
            });
        };

        if (!modal) {
            modal = document.createElement("div");
            modal.id = "media-viewer-modal";
            modal.className = "media-viewer-overlay";
            // Opt-out dal KeyboardManager globale: questo modale gestisce ESC + frecce internamente
            modal.setAttribute('data-esc-close', '0');
            modal.innerHTML = `
                <div class="media-viewer-wrapper">
                    <button class="media-viewer-close" data-tooltip="Chiudi">&times;</button>
                    <button class="media-viewer-nav media-viewer-prev" data-tooltip="File precedente (←)" id="media-viewer-prev">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                    </button>
                    <button class="media-viewer-nav media-viewer-next" data-tooltip="File successivo (→)" id="media-viewer-next">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                    <div class="media-viewer-info">
                        <div class="media-viewer-header">
                            <div class="media-viewer-main-info">
                                <div class="media-viewer-title" id="media-viewer-title"></div>
                                <div class="media-viewer-desc" id="media-viewer-desc"></div>
                                <div class="media-viewer-meta" id="media-viewer-meta"></div>
                            </div>
                            <div class="media-viewer-actions">
                                <a id="media-viewer-download" data-tooltip="Scarica file" class="media-viewer-action-btn">
                                    <img src="assets/icons/download.png" alt="Scarica">
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="media-viewer-content-full" id="media-viewer-content"></div>
                </div>
            `;
            document.body.appendChild(modal);

            // Funzione per chiudere il modale
            const closeModal = () => {
                modal.style.display = 'none';
                if (modal._keydownHandler) {
                    document.removeEventListener("keydown", modal._keydownHandler);
                    delete modal._keydownHandler;
                }
            };

            // Salva la funzione di chiusura nel modale
            modal._closeModal = closeModal;

            // Collega i pulsanti di chiusura (solo se non già collegati)
            const closeBtn = modal.querySelector('.media-viewer-close');
            if (closeBtn && !closeBtn._listenerAttached) {
                closeBtn.onclick = (e) => {
                    e.stopPropagation();
                    if (modal._closeModal) modal._closeModal();
                };
                closeBtn._listenerAttached = true;
            }

            if (!modal._clickHandlerAttached) {
                modal.onclick = (e) => {
                    if (e.target === modal && modal._closeModal) {
                        modal._closeModal();
                    }
                };
                modal._clickHandlerAttached = true;
            }

            // Gestione navigazione tastiera (solo se non già collegata)
            if (!modal._keydownHandler) {
                const handleKeydown = function (e) {
                    if (e.key === "Escape") {
                        if (modal._closeModal) modal._closeModal();
                    } else if (e.key === "ArrowLeft") {
                        e.preventDefault();
                        if (modal._navigateToFile) modal._navigateToFile(-1);
                    } else if (e.key === "ArrowRight") {
                        e.preventDefault();
                        if (modal._navigateToFile) modal._navigateToFile(1);
                    }
                };
                document.addEventListener("keydown", handleKeydown);
                modal._keydownHandler = handleKeydown;
            }
        }

        // Aggiorna sempre la funzione di chiusura (anche se il modale esiste già)
        if (!modal._closeModal) {
            modal._closeModal = () => {
                modal.style.display = 'none';
                if (modal._keydownHandler) {
                    document.removeEventListener("keydown", modal._keydownHandler);
                    delete modal._keydownHandler;
                }
            };
        }

        // Collega i pulsanti di chiusura anche se il modale esiste già (ma solo una volta)
        const closeBtn = modal.querySelector('.media-viewer-close');
        if (closeBtn) {
            // Rimuovi eventuali listener precedenti per evitare duplicati
            if (closeBtn._listenerAttached) {
                closeBtn.onclick = null;
            }
            closeBtn.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();
                if (modal._closeModal) modal._closeModal();
            };
            closeBtn._listenerAttached = true;
        }

        // Rimuovi eventuali listener precedenti per evitare duplicati
        if (modal._clickHandlerAttached) {
            modal.onclick = null;
        }
        modal.onclick = (e) => {
            if (e.target === modal && modal._closeModal) {
                modal._closeModal();
            }
        };
        modal._clickHandlerAttached = true;

        // Collega il link di download (aggiorna sempre)
        const downloadLink = modal.querySelector('#media-viewer-download');
        if (downloadLink) {
            downloadLink.href = path;
            downloadLink.download = opts.nome_file || "file";
            downloadLink.onclick = function (e) {
                e.stopPropagation();
            };
        }

        // Salva il file corrente nel modale per la navigazione
        modal._currentDocId = opts.id_documento;
        modal._currentDocPath = path;
        modal._navigateToFile = navigateToFile;

        // Collega i pulsanti di navigazione (sempre, anche se il modale esiste già)
        const prevBtn = modal.querySelector('#media-viewer-prev');
        const nextBtn = modal.querySelector('#media-viewer-next');
        if (prevBtn) {
            prevBtn.onclick = (e) => {
                e.stopPropagation();
                if (modal._navigateToFile) modal._navigateToFile(-1);
            };
        }
        if (nextBtn) {
            nextBtn.onclick = (e) => {
                e.stopPropagation();
                if (modal._navigateToFile) modal._navigateToFile(1);
            };
        }

        // Aggiorna sempre il contenuto e i pulsanti (sia per modale nuovo che esistente)
        const content = modal.querySelector("#media-viewer-content");
        const ext = (opts.nome_file || path).split('.').pop().toLowerCase();
        const mime = opts.mime_type || '';

        if (["jpg", "jpeg", "png", "gif", "webp", "svg"].includes(ext)) {
            if (ext === "svg") {
                content.innerHTML = `<object data="${path}" type="image/svg+xml" style="width:100%;height:100%;"></object>`;
            } else {
                content.innerHTML = `<img src="${path}" alt="Anteprima" class="media-viewer-img" onerror="handleMissingMedia(this, '${opts.id_documento}')">`;
            }
        } else if (["mp4", "webm", "ogg", "mov"].includes(ext) || mime.startsWith('video/')) {
            content.innerHTML = `<video src="${path}" controls autoplay style="max-width:100%;max-height:100%;outline:none;background:#000;"></video>`;
        } else if (ext === "pdf" || mime === 'application/pdf') {
            content.innerHTML = `<iframe src="${path}" style="width:100%;height:100%;"></iframe>`;
        } else if (["docx", "doc", "odt"].includes(ext)) {
            content.innerHTML = `
                <div style="color:#294c7d;text-align:center;padding:30px;">
                    <img src="assets/icons/file_word.png" alt="Word" style="width:60px;"><br>
                    <div style="font-size:20px;font-weight:bold;margin-top:18px;">DOCUMENTO WORD</div>
                    <a href="${path}" download style="margin-top:22px;display:inline-block;font-size:16px;color:#2567c7;font-weight:600;text-decoration:underline;">Scarica il file</a>
                </div>
            `;
        } else if (["xls", "xlsx", "ods", "csv"].includes(ext)) {
            content.innerHTML = `
                <div style="color:#236721;text-align:center;padding:30px;">
                    <img src="assets/icons/excel.png" alt="Excel" style="width:54px;"><br>
                    <div style="font-size:20px;font-weight:bold;margin-top:18px;">DOCUMENTO EXCEL</div>
                    <a href="${path}" download style="margin-top:22px;display:inline-block;font-size:16px;color:#236721;font-weight:600;text-decoration:underline;">Scarica il file</a>
                </div>
            `;
        } else if (["txt", "log"].includes(ext)) {
            content.innerHTML = `
                <div style="color:#333;text-align:center;padding:30px;">
                    <img src="assets/icons/txt.png" alt="TXT" style="width:48px;"><br>
                    <div style="font-size:20px;font-weight:bold;margin-top:18px;">FILE DI TESTO</div>
                    <a href="${path}" download style="margin-top:22px;display:inline-block;font-size:15px;color:#333;font-weight:600;text-decoration:underline;">Scarica il file</a>
                </div>
            `;
        } else {
            content.innerHTML = `
                <div style="color:#444;font-size:17px;max-width:95%;padding:30px;">
                    <img src="assets/icons/file.png" alt="File" style="width:45px;margin-bottom:8px;"><br>
                    <div style="margin-top:18px;">Visualizzazione non disponibile.<br>
                    <a href="${path}" download>Scarica il file</a></div>
                </div>
            `;
        }

        modal.style.display = "flex";

        // Aggiorna i pulsanti di navigazione
        const updateNavButtons = function () {
            const prevBtn = modal.querySelector('#media-viewer-prev');
            const nextBtn = modal.querySelector('#media-viewer-next');
            const currentList = window.currentDocumentsList || [];
            if (currentList.length === 0) {
                if (prevBtn) prevBtn.style.display = 'none';
                if (nextBtn) nextBtn.style.display = 'none';
                return;
            }

            // Usa i dati salvati nel modale
            const currentDocId = modal._currentDocId;
            const currentDocPath = modal._currentDocPath;

            let currentIndex = currentList.findIndex(doc => {
                const docPath = doc.file_url || '';
                const normalizedCurrentPath = currentDocPath.charAt(0) !== '/' ? '/' + currentDocPath : currentDocPath;
                return docPath === normalizedCurrentPath || doc.id == currentDocId;
            });

            if (currentIndex === -1) {
                const normalizedCurrentPath = currentDocPath.charAt(0) !== '/' ? '/' + currentDocPath : currentDocPath;
                currentIndex = currentList.findIndex(doc => {
                    const docPath = doc.file_url || '';
                    return docPath === normalizedCurrentPath;
                });
            }

            if (prevBtn) {
                prevBtn.style.display = currentIndex > 0 ? 'flex' : 'none';
                prevBtn.disabled = currentIndex <= 0;
            }
            if (nextBtn) {
                nextBtn.style.display = currentIndex >= 0 && currentIndex < currentList.length - 1 ? 'flex' : 'none';
                nextBtn.disabled = currentIndex >= currentList.length - 1;
            }
        };
        updateNavButtons();

        const titleEl = modal.querySelector("#media-viewer-title");
        const descEl = modal.querySelector("#media-viewer-desc");
        const metaEl = modal.querySelector("#media-viewer-meta");

        if (titleEl) {
            // Determina icona per titolo
            let iconSrc = 'assets/icons/file_doc.png';
            if (["jpg", "jpeg", "png", "gif", "webp", "svg"].includes(ext)) {
                iconSrc = 'assets/icons/image.png';
            } else if (ext === 'pdf' || mime === 'application/pdf') {
                iconSrc = 'assets/icons/file_pdf.png';
            } else if (["docx", "doc", "odt"].includes(ext)) {
                iconSrc = 'assets/icons/file_word.png';
            } else if (["xls", "xlsx", "ods", "csv"].includes(ext)) {
                iconSrc = 'assets/icons/file_excel.png';
            } else if (["zip", "rar", "7z"].includes(ext)) {
                iconSrc = 'assets/icons/file_zip.png';
            } else if (["txt", "log"].includes(ext)) {
                iconSrc = 'assets/icons/doc.png';
            }

            const safeTitle = opts.titolo || opts.nome_file || (path ? path.split('/').pop() : 'File');
            const escapeFn = (s) => String(s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            titleEl.innerHTML = `<img src="${iconSrc}" alt="" style="width:24px;height:24px;margin-right:8px;vertical-align:middle;object-fit:contain;"> <span style="vertical-align:middle;">${escapeFn(safeTitle)}</span>`;
        }

        if (descEl) {
            const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (s) => String(s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            if (opts.descrizione) {
                descEl.innerHTML = escapeFn(opts.descrizione);
                descEl.style.display = '';
            } else {
                descEl.style.display = 'none';
            }
        }

        if (metaEl) {
            const metaParts = [];
            if (opts.dataIT) {
                metaParts.push(`<span class="meta-item"><b>Data:</b> ${opts.dataIT}</span>`);
            }
            if (opts.size) {
                metaParts.push(`<span class="meta-item"><b>Dimensione:</b> ${opts.size}</span>`);
            }
            if (opts.mime_type) {
                metaParts.push(`<span class="meta-item"><b>Tipo:</b> ${opts.mime_type}</span>`);
            }
            if (metaParts.length > 0) {
                metaEl.innerHTML = metaParts.join(' • ');
                metaEl.style.display = '';
            } else {
                metaEl.style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Errore in showMediaViewer:', error);
        if (typeof showToast === 'function') {
            showToast('Errore nell\'apertura del file', 'error');
        }
    }
};

window.handleMissingMedia = function (imgEl, id) {
    if (!id || id === 'undefined') return;
    const section = window.currentSection || 'archivio';
    console.warn(`[MediaViewer] File non trovato: id=${id}, sezione=${section}.`);

    // Mostra placeholder nel viewer
    const container = imgEl.parentElement;
    if (container) {
        container.innerHTML = `
            <div style="color:#c0392b;text-align:center;padding:40px;">
                <i class="fa fa-exclamation-triangle" style="font-size:48px;margin-bottom:15px;"></i>
                <div style="font-size:18px;font-weight:bold;">FILE NON TROVATO</div>
                <p style="margin-top:10px;color:#666;">Il documento non è più disponibile su Nextcloud.<br>È stata inviata una segnalazione al sistema.</p>
            </div>
        `;
    }

    // Segnala al server
    if (typeof window.customFetch === 'function') {
        window.customFetch(section, 'markMissingDocumento', { id }).then(res => {
            if (res.success) {
                console.log(`[MediaViewer] Documento ${id} marcato come mancante.`);
                // Opzionale: segna sporco il DM per refresh alla chiusura
                window.__dmDocumentsDirty = true;
            }
        });
    }
};
