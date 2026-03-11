export function openTab(event, tabId) {
    console.log(`Apro la scheda con ID: ${tabId}`);

    // Trova il contenitore delle schede
    const parentContainer = event.currentTarget.closest('.tab-container') || document;

    // Nasconde tutte le schede
    parentContainer.querySelectorAll('.tab-content').forEach(tab => {
        tab.style.display = 'none';
    });

    // Rimuove la classe "active" da tutti i pulsanti
    parentContainer.querySelectorAll('.tab-link').forEach(link => {
        link.classList.remove('active');
    });

    // Mostra la scheda selezionata
    const selectedTab = parentContainer.querySelector(`#${tabId}`);
    if (selectedTab) {
        selectedTab.style.display = 'block';
    }

    // Aggiunge la classe "active" al pulsante cliccato
    if (event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
}

export function initializeTabs(container = document) {
    console.log('Inizializzo i tab:', container);

    container.querySelectorAll('.tab-link').forEach(tabLink => {
        tabLink.addEventListener('click', event => {
            const tabId = tabLink.getAttribute('data-tab-id');
            openTab(event, tabId);
        });
    });

    const defaultTabLink = container.querySelector('.tab-link.active');
    if (defaultTabLink) {
        openTab({ currentTarget: defaultTabLink }, defaultTabLink.getAttribute('data-tab-id'));
    }
}
