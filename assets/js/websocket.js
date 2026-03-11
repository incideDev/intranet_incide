document.addEventListener('DOMContentLoaded', () => {
    // Leggi URL WebSocket da data attribute o usa default
    const container = document.querySelector('.main-container');
    const wsUrl = container?.getAttribute('data-websocket-url') || 'ws://localhost:8080';
    const ws = new WebSocket(wsUrl);
    const chatBox = document.getElementById('chat-box');
    const chatForm = document.getElementById('chat-form');
    const messageInput = document.getElementById('message-input');
    const userId = document.querySelector('.main-container').getAttribute('data-user-id');
    const username = document.querySelector('.main-container').getAttribute('data-username');
    const chatTitle = document.getElementById('chat-title');
    let currentGroupId = null; // Variabile per tracciare il gruppo/utente attualmente selezionato

    // Carica gli utenti o i gruppi al caricamento della pagina
    const csrfToken = document.head.querySelector("[name~=token-csrf][content]").content;
    fetch('index.php?action=getGroupsOrUsers', {
          headers: {
            "X-Csrf-Token": csrfToken, // 👈👈👈 Set the token
            "Content-Type": "application/json"
          },
          method: 'POST',
          credentials: "same-origin"
        })
        .then(response => response.json())
        .then(data => {
            console.log(data);
            const userList = document.getElementById('user-list');
            data.forEach(item => {
                const button = document.createElement('button');
                button.textContent = `${item.name || item.group_name}`;
                button.className = 'user-button';
                button.dataset.groupId = item.id;
                button.dataset.name = item.name || item.group_name;
                userList.appendChild(button);
            });
        })
        .catch(error => console.error('Errore nel caricamento degli utenti o gruppi:', error));

    // Quando si clicca su un utente o un gruppo, carica i messaggi
    document.getElementById('user-list').addEventListener('click', (e) => {
        if (e.target.tagName === 'BUTTON') {
            currentGroupId = e.target.dataset.groupId;
            const recipientName = e.target.dataset.name; // Prendi il nome dell'utente o del gruppo
            chatTitle.textContent = recipientName; // Aggiorna il titolo della chat
            loadMessages(currentGroupId);
        }
    });

    // Funzione per caricare i messaggi
    function loadMessages(groupId) {
        fetch(`index.php?action=getMessages&groupId=${groupId}`,{
            headers: {
                "X-Csrf-Token": csrfToken, // 👈👈👈 Set the token
                "Content-Type": "application/json"
              },
              method: 'POST',
              credentials: "same-origin"
            })
            .then(response => response.json())
            .then(data => {
                chatBox.innerHTML = ''; // Pulisci la chat
                data.messages.forEach(msg => {
                    const p = document.createElement('p');
                    p.textContent = `${msg.username}: ${msg.message}`;
                    p.className = 'message' + (msg.userId === userId ? ' sent' : ' received');
                    chatBox.appendChild(p);
                });
                chatBox.scrollTop = chatBox.scrollHeight; // Scroll fino in fondo
            })
            .catch(error => console.error('Errore nel caricamento dei messaggi:', error));
    }

    ws.onopen = function () {
        console.log('Connected to WebSocket server!');
        ws.send(JSON.stringify({ type: 'init', userId: userId, username: username }));
    };

    ws.onmessage = function (event) {
        const data = JSON.parse(event.data);
        if (data.type === 'notification' && data.groupId === currentGroupId) {
            const p = document.createElement('p');
            p.className = 'message' + (data.userId === userId ? ' sent' : ' received');
            p.textContent = (data.userId === userId ? 'Tu: ' : `${data.username}: `) + data.message;
            chatBox.appendChild(p);
            chatBox.scrollTop = chatBox.scrollHeight; // Scroll fino in fondo
        }
    };

    // Invia messaggio
    chatForm.addEventListener('submit', function (e) {
        e.preventDefault(); // Impedisce il redirect alla home

        const message = messageInput.value.trim();
        if (message && currentGroupId) {
            // Invia il messaggio al WebSocket server
            ws.send(JSON.stringify({
                type: 'notification',
                userId: userId,
                groupId: currentGroupId,
                message: message
            }));

            // Aggiungi subito il messaggio alla chatbox per l'utente corrente
            const p = document.createElement('p');
            p.classList.add('message', 'sent'); // Messaggio inviato
            p.textContent = `Tu: ${message}`;
            chatBox.appendChild(p);
            chatBox.scrollTop = chatBox.scrollHeight; // Scroll to the bottom

            messageInput.value = ''; // Pulisce il campo di input dopo l'invio
        }
    });

    ws.onclose = function () {
        console.log('Disconnected from WebSocket server');
    };
});
