function updateArchiveTable() {
    fetch('api/protocol/get_archivio.php')
        .then(response => response.json())
        .then(emails => {
            console.log("📌 Dati ricevuti per la tabella:", emails);

            if (!Array.isArray(emails)) {
                console.error(" Errore: La risposta dell'API non è un array valido.");
                return;
            }

            var tableElement = $('#universalTable');

            // **Se esiste una tabella DataTables, la distruggiamo correttamente**
            if ($.fn.DataTable.isDataTable(tableElement)) {
                tableElement.DataTable().clear().destroy();
                tableElement.empty(); // **Rimuove intestazioni errate**
            }

            // **Inizializza la tabella senza la colonna 'id'**
            tableElement.DataTable({
                data: emails.map(email => ({
                    protocollo: email.protocollo ?? '',
                    commessa: email.commessa ?? '',
                    inviato_da: email.inviato_da ?? '',
                    ditta: email.ditta ?? '',
                    contatto_referente: email.contatto_referente ?? '',
                    nome_referente: email.nome_referente ?? '',
                    data: email.data ?? '',
                    oggetto: email.oggetto ?? '',
                    tipologia: email.tipologia ?? ''
                })),
                columns: [
                    { data: 'protocollo', title: 'Protocollo' },
                    { data: 'commessa', title: 'Commessa' },
                    { data: 'inviato_da', title: 'Inviato Da' },
                    { data: 'ditta', title: 'Ditta' },
                    { data: 'contatto_referente', title: 'Contatto' },
                    { data: 'nome_referente', title: 'Nome Referente' },
                    { data: 'data', title: 'Data', render: function (data) {
                        return data ? new Date(data).toLocaleDateString('it-IT') : '';
                    }},
                    { data: 'oggetto', title: 'Oggetto' },
                    { data: 'tipologia', title: 'Tipologia' }
                ],
                destroy: true,
                ordering: false,
                paging: true,
                searching: false,
                lengthChange: false,
                autoWidth: false,
                language: {
                    paginate: { first: "Prima", last: "Ultima", next: ">", previous: "<" },
                    lengthMenu: "Mostra _MENU_ righe",
                    info: "Visualizzando da _START_ a _END_ di _TOTAL_ righe"
                }
            });

            console.log(" Tabella aggiornata con i nuovi dati!");
        })
        .catch(err => console.error(' Errore nel caricamento archivio:', err));
}

function toggleCategory(category) {
    var projectDropdown = document.getElementById('project');
    projectDropdown.innerHTML = '';
    var descrizioneBox = document.getElementById('descrizione');
    descrizioneBox.value = '';
    if (category === 'commessa') {
        fetch('api/protocol/get_commesse.php')
            .then(response => response.json())
            .then(data => {
                projectDropdown.innerHTML = '';
                data.forEach(commessa => {
                    const option = document.createElement('option');
                    option.value = commessa.Codice_commessa;
                    option.text = commessa.Codice_commessa;
                    option.setAttribute('data-descrizione', commessa.Descrizione);
                    projectDropdown.add(option);
                });
            })
            .catch(err => console.error('Errore nel caricamento delle commesse:', err));
    } else if (category === 'generale') {
        fetch('api/protocol/get_generale.php')
            .then(response => response.json())
            .then(data => {
                data.forEach(gen => {
                    const option = document.createElement('option');
                    option.value = gen.codice_generale;
                    option.text = gen.codice_generale;
                    option.setAttribute('data-descrizione', gen.tipologia);
                    projectDropdown.add(option);
                });
            })
            .catch(err => console.error('Errore nel caricamento dei dati generali:', err));
    }
    updateDescription();
}

function openProfileSidebar() {
    document.getElementById("profileSidebar").style.width = "250px";
}

function closeProfileSidebar() {
    document.getElementById("profileSidebar").style.width = "0";
}

function updateContatti() {
    var recipient = document.getElementById('recipient').value;
    var contactDropdown = document.getElementById('contatto');

    // Svuota e disabilita momentaneamente il menu
    contactDropdown.innerHTML = '<option value="">Seleziona contatto</option>';
    contactDropdown.disabled = true;

    if (!recipient) return; // Se non è selezionato un destinatario, esci dalla funzione

    fetch('api/protocol/get_contatti.php?azienda=' + encodeURIComponent(recipient))
        .then(response => response.json())
        .then(contatti => {
            if (!Array.isArray(contatti) || contatti.length === 0) {
                console.warn("⚠️ Nessun contatto trovato per:", recipient);
                return;
            }

            // Aggiunge i contatti al menu a tendina
            contatti.forEach(contatto => {
                var option = document.createElement('option');
                option.value = contatto.E_mail;
                option.text = contatto.E_mail;
                contactDropdown.add(option);
            });

            contactDropdown.disabled = false;
        })
        .catch(err => console.error(' Errore nel caricamento dei contatti:', err));
}

function updateDescription() {
    var codiceSelect = document.getElementById('project');
    var descrizioneBox = document.getElementById('descrizione');
    if (codiceSelect.selectedIndex >= 0) {
        var selectedOption = codiceSelect.options[codiceSelect.selectedIndex];
        descrizioneBox.value = selectedOption.getAttribute('data-descrizione');
    } else {
        descrizioneBox.value = '';
    }
}

function generateProtocolCode() {
    var project = document.getElementById('project').value;
    if (!project) return;

    var type = 'M';
    var year = new Date().getFullYear().toString().slice(-2);
    var progressivo = String(nextId).padStart(3, '0');
    var protocolCode = type + '_' + project + '_' + progressivo + '_' + year;
    var subject = document.getElementById('subject').value;
    document.getElementById('final-code').value = protocolCode + ' - ' + subject;
    return protocolCode;
}

function initEmailBody() {
    generateProtocolCode();
}

function saveEmail() {
    var commessa = document.getElementById('project')?.value || '';
    var ditta = document.getElementById('recipient')?.value || '';
    var contatto = document.getElementById('contatto')?.value || ''; 
    var nome_referente = document.getElementById('nome_referente')?.value.trim() || '';

    var data = new Date().toISOString().slice(0, 19).replace('T', ' ');
    var oggetto = document.getElementById('subject')?.value || '';
    var finalCode = document.getElementById('final-code')?.value || '';
    var protocollo = finalCode.split(' - ')[0];
    var tipologia = document.getElementById('nuova-tipologia')?.value || '';

    console.log("📌 Controllo prima di salvare:", { commessa, ditta, contatto, nome_referente, data, oggetto, finalCode, tipologia });

    if (!tipologia) {
        alert('⚠️ Seleziona una tipologia');
        return;
    }

    if (!contatto) {
        alert('⚠️ Seleziona un contatto valido prima di salvare.');
        return;
    }

    fetch('api/protocol/save_email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            commessa,
            inviato_da: CURRENT_USER.username, // 🔹 Inseriamo il nome utente della sessione
            ditta,
            contatto_referente: contatto,
            nome_referente,
            data,
            oggetto,
            protocollo,
            final_code: finalCode,
            tipologia
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(' Email salvata con successo!');

            setTimeout(() => {
                console.log("🔄 Aggiorno la tabella dopo l'invio!");
                updateArchiveTable();
            }, 2000);  // 🔹 Ritardo per evitare problemi di caricamento dati

            // **🔹 Ora apriamo Outlook con i campi precompilati**
            var mailtoLink = `mailto:${contatto}?subject=${encodeURIComponent(finalCode)}`;
            window.location.href = mailtoLink;  // 🔹 Apre Outlook

            resetFormAndFields();
        } else {
            alert(' Errore durante il salvataggio dell\'email: ' + data.error);
        }
    })
    .catch(err => console.error(' Errore nel salvataggio dell\'email:', err));
}

function sendEmail() {
    document.getElementById("email-form").reset();
}

function resetFormAndFields() {
    var emailForm = document.getElementById('email-form');
    if (emailForm) {
        emailForm.reset();
    }

    resetCodice();
    document.getElementById('descrizione').value = '';
    document.getElementById('recipient').selectedIndex = 0;
    document.getElementById('contatto').innerHTML = '<option value="">Seleziona contatto</option>';
    document.getElementById('contatto').disabled = true;
    document.getElementById('nome_referente').value = '';
        document.getElementById('final-code').value = '';
    document.getElementById('subject').value = '';
}

function resetCodice() {
    var projectDropdown = document.getElementById('project');
    projectDropdown.innerHTML = '';

    var defaultOption = document.createElement('option');
    defaultOption.text = 'Seleziona un codice';
    defaultOption.value = '';
    projectDropdown.appendChild(defaultOption);

    projectDropdown.selectedIndex = 0;
}

function toggleProtocolForm(showForm = null) {
    var form = document.getElementById('protocollo-form');
    var archive = document.getElementById('archive-container');
    var button = document.getElementById('new-protocol-button');

    if (showForm === null) {
        showForm = form.style.display === 'none';
    }

    if (showForm) {
        form.style.display = 'block';
        archive.style.display = 'none';
        button.textContent = 'Torna all\'archivio';
        sessionStorage.setItem('lastView', 'protocolForm');
    } else {
        form.style.display = 'none';
        archive.style.display = 'block';
        button.textContent = 'Nuovo protocollo';
        sessionStorage.setItem('lastView', 'archive');
    }
}

function handleTipologiaChange() {
    var tipo = document.getElementById('nuova-tipologia').value;
    var generaButton = document.getElementById('genera-button');

    if (tipo === 'lettera') {
        generaButton.setAttribute('onclick', 'generaLettera()');
    } else {
        generaButton.setAttribute('onclick', 'saveEmail()');
    }
}

function generaLettera() {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'genera_lettera.php';

    form.appendChild(createHiddenField('destinatario', document.getElementById('recipient').value));
    form.appendChild(createHiddenField('nome_referente', document.getElementById('nome_referente').value));
    form.appendChild(createHiddenField('data', new Date().toISOString().slice(0, 10)));
    form.appendChild(createHiddenField('protocollo', document.getElementById('final-code').value));
    form.appendChild(createHiddenField('descrizione', document.getElementById('descrizione').value));

    document.body.appendChild(form);
    form.submit();
}

function createHiddenField(name, value) {
    var hiddenField = document.createElement('input');
    hiddenField.type = 'hidden';
    hiddenField.name = name;
    hiddenField.value = value;
    return hiddenField;
}

function resetFormAfterSubmission(event) {
    setTimeout(function() {
        document.getElementById("email-form").reset();
    }, 500);
}

var activeFilters = {};
var searchTerms = {};

function toggleDropdown(id, triggerElement) {
    var dropdown = document.getElementById(id);
    var isDropdownOpen = dropdown.style.display === 'block';

    closeAllDropdowns();

    if (!isDropdownOpen) {
        dropdown.style.display = 'block';

        var rect = triggerElement.getBoundingClientRect();
        var dropdownRect = dropdown.getBoundingClientRect();

        if (dropdownRect.left < 0) {
            dropdown.style.left = '0';
        } else if (dropdownRect.right > window.innerWidth) {
            dropdown.style.left = 'auto';
            dropdown.style.right = '0';
        }

        highlightActiveFilters(id);
    }
}

function closeAllDropdowns() {
    var dropdowns = document.querySelectorAll('.dropdown-content');
    dropdowns.forEach(function(dropdown) {
        dropdown.style.display = 'none';
    });
}

document.addEventListener('click', function(event) {
    var isClickInside = event.target.closest('.dropdown') || event.target.closest('.filter-icon');
    if (!isClickInside) {
        closeAllDropdowns();
    }
});

function populateDropdownOptions() {
    var allRows = document.querySelectorAll("#myTable tbody tr");

    // Funzione per popolare il dropdown
    function populateDropdown(uniqueSet, dropdownId, columnIndex) {
        var dropdown = document.getElementById(dropdownId);
        uniqueSet.forEach(value => {
            var option = document.createElement('a');
            option.textContent = value;
            option.href = "#";
            option.onclick = function() { filterTableByColumn(columnIndex, value); };
            dropdown.appendChild(option);
        });
    }

    var uniqueProtocollo = new Set(), uniqueCommessa = new Set(), uniqueInviatoDa = new Set();
    var uniqueDitta = new Set(), uniqueReferente = new Set(), uniqueData = new Set();
    var uniqueOggetto = new Set(), uniqueTipologia = new Set();

    allRows.forEach(row => {
        uniqueProtocollo.add(row.cells[0].textContent.trim());
        uniqueCommessa.add(row.cells[1].textContent.trim());
        uniqueInviatoDa.add(row.cells[2].textContent.trim());
        uniqueDitta.add(row.cells[3].textContent.trim());
        uniqueReferente.add(row.cells[4].textContent.trim());
        uniqueData.add(row.cells[5].textContent.trim());
        uniqueOggetto.add(row.cells[6].textContent.trim());
        uniqueTipologia.add(row.cells[7].textContent.trim());
    });

    populateDropdown(uniqueProtocollo, 'filterProtocollo', 0);
    populateDropdown(uniqueCommessa, 'filterCommessa', 1);
    populateDropdown(uniqueInviatoDa, 'filterInviatoDa', 2);
    populateDropdown(uniqueDitta, 'filterDitta', 3);
    populateDropdown(uniqueReferente, 'filterReferente', 4);
    populateDropdown(uniqueData, 'filterData', 5);
    populateDropdown(uniqueOggetto, 'filterOggetto', 6);
    populateDropdown(uniqueTipologia, 'filterTipologia', 7);
}

function filterTableByColumn(columnIndex, filterValue, element) {
    activeFilters[columnIndex] = filterValue;
    applyFilters();

    highlightActiveFilter(element);
}

function handleSearchInput(columnIndex, searchValue) {
    searchTerms[columnIndex] = searchValue.trim().toUpperCase();
    applyFilters();
}

function applyFilters() {
    var table = document.getElementById("myTable");
    var rows = table.getElementsByTagName("tr");

    for (var i = 1; i < rows.length; i++) {
        var showRow = true;

        for (var columnIndex in activeFilters) {
            if (activeFilters.hasOwnProperty(columnIndex)) {
                var td = rows[i].getElementsByTagName("td")[columnIndex];
                if (td) {
                    var txtValue = td.textContent || td.innerText;
                    var filterValue = activeFilters[columnIndex];

                    if (txtValue.toUpperCase().indexOf(filterValue.toUpperCase()) === -1) {
                        showRow = false;
                    }
                }
            }
        }

        for (var columnIndex in searchTerms) {
            if (searchTerms.hasOwnProperty(columnIndex)) {
                var td = rows[i].getElementsByTagName("td")[columnIndex];
                if (td) {
                    var txtValue = td.textContent || td.innerText;
                    var searchValue = searchTerms[columnIndex];

                    if (txtValue.toUpperCase().indexOf(searchValue) === -1) {
                        showRow = false;
                    }
                }
            }
        }

        rows[i].style.display = showRow ? "" : "none";
    }
}

window.onload = function() {
    populateDropdownOptions();

    document.querySelectorAll("#myTable thead input[type='text']").forEach(function(input, index) {
        input.addEventListener('input', function() {
            handleSearchInput(index, input.value);
        });
    });
};
