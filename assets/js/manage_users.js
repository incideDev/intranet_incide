document.addEventListener("DOMContentLoaded", function () {
    const notification = document.getElementById('notification');

    // Funzione per mostrare una notifica
    function showNotification(message, type = 'success') {
        notification.textContent = message;
        notification.className = `notification ${type} show`;

        setTimeout(() => {
            notification.className = `notification ${type}`;
            setTimeout(() => {
                notification.style.display = 'none';
            }, 500);
        }, 3000);
    }

    // Aggiorna il ruolo dell'utente utilizzando l'API
    window.updateUserRole = function (userId, roleId) {
    fetch('/api/users/update_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            id: userId, // Assicurati che il nome corrisponda a quello atteso in update_user.php
            role_id: roleId, // Assicurati che il nome corrisponda
        }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Ruolo aggiornato con successo!', 'success');
        } else {
            showNotification(`Errore: ${data.error}`, 'error');
        }
    })
    .catch(error => {
        console.error('Errore nella richiesta:', error);
        showNotification('Si è verificato un errore.', 'error');
    });
};


    // Risolve un utente problematico utilizzando l'API
    window.resolveUserInline = function (codOperatore) {
        const usernameInput = document.getElementById(`username-${codOperatore}`);
        const username = usernameInput.value.trim();

        if (!username) {
            showNotification("Inserisci un username valido.", "error");
            return;
        }

        const requestData = {
            codOperatore: codOperatore,
            username: username,
            role_id: 1 // Imposta un ruolo predefinito
        };

        console.log("Dati inviati:", requestData); // Logga i dati inviati

        fetch('/api/users/add_user.php', {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(requestData),
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification("Utente aggiornato con successo!", "success");

                    const problematicRow = document.querySelector(`#username-${codOperatore}`).closest("tr");
                    problematicRow.remove();

                    const tableBody = document.querySelector("table:nth-of-type(1) tbody");
                    const newRow = document.createElement("tr");

                    newRow.innerHTML = `
                        <td>${data.username}</td>
                        <td>${data.email}</td>
                        <td>1</td>
                        <td>
                            <a href="#" onclick="deleteUser(${data.user_id}, this); return false;">Elimina</a>
                        </td>
                    `;

                    tableBody.appendChild(newRow);
                } else {
                    showNotification(`Errore: ${data.error}`, "error");
                }
            })
            .catch(err => {
                console.error(err);
                showNotification("Si è verificato un errore durante l'aggiornamento.", "error");
            });
    };

    // Elimina un utente utilizzando l'API
    window.deleteUser = function (userId, element) {
        if (!confirm("Sei sicuro di voler eliminare questo utente?")) {
            return;
        }

        fetch(`/api/users/delete_user.php?id=${userId}`, {
            method: "POST",
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification("Utente eliminato con successo!", "success");

                    const row = element.closest("tr");
                    row.remove();
                } else {
                    showNotification(`Errore: ${data.error}`, "error");
                }
            })
            .catch(err => {
                console.error(err);
                showNotification("Si è verificato un errore durante l'eliminazione.", "error");
            });
    };

    // Event Listener per rimuovere la classe di notifica "show" dopo il timeout
    document.addEventListener('animationend', (event) => {
        if (event.target.classList.contains('notification')) {
            event.target.classList.remove('show');
        }
    });

});
