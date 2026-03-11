document.addEventListener("DOMContentLoaded", function () {
  // Creazione dinamica del menu contestuale
  const contextMenu = document.createElement("div");
  contextMenu.id = "contextMenu";
  contextMenu.style.display = "none";
  contextMenu.style.position = "absolute";
  contextMenu.style.backgroundColor = "#fff";
  contextMenu.style.border = "1px solid #ccc";
  contextMenu.style.padding = "10px";
  contextMenu.style.zIndex = "1000";
  document.body.appendChild(contextMenu);

  /**
   * Gestione del menu contestuale
   */
  document.addEventListener("contextmenu", function (event) {
    event.preventDefault();

    const target = event.target;
    const svgBounds = document.getElementById("orgChartDiv").getBoundingClientRect();
    const menuWidth = contextMenu.offsetWidth || 150; // Dimensione predefinita
    const menuHeight = contextMenu.offsetHeight || 100; // Dimensione predefinita

    // Calcola le coordinate relative al contenitore SVG
    let x = event.clientX - svgBounds.left;
    let y = event.clientY - svgBounds.top;

    // Assicura che il menu non esca dai bordi del contenitore
    if (x + menuWidth > svgBounds.width) x = svgBounds.width - menuWidth - 10;
    if (y + menuHeight > svgBounds.height) y = svgBounds.height - menuHeight - 10;

    // Differenzia il menu in base al contesto
    if (target.classList.contains("node")) {
      // Menu per i nodi
      contextMenu.innerHTML = `
        <button id="editNode">Modifica Nodo</button>
        <button id="deleteNode">Elimina Nodo</button>
      `;
      contextMenu.setAttribute("data-target-id", target.getAttribute("data-id"));
    } else if (target.classList.contains("isolated-node")) {
      // Menu per i nodi isolati
      contextMenu.innerHTML = `
        <button id="connectNode">Collega Nodo</button>
        <button id="deleteNode">Elimina Nodo</button>
      `;
      contextMenu.setAttribute("data-target-id", target.getAttribute("data-id"));
    } else {
      // Menu per l'area vuota
      contextMenu.innerHTML = `
        <label for="userDropdown">Seleziona Dipendente:</label>
        <select id="userDropdown"></select>
        <button id="confirmAddNode">Aggiungi Nodo</button>
      `;
      populateUserDropdown(); // Popola dinamicamente il dropdown
      contextMenu.removeAttribute("data-target-id");
    }

    // Mostra il menu contestuale nella posizione calcolata
    contextMenu.style.left = `${x}px`;
    contextMenu.style.top = `${y}px`;
    contextMenu.style.display = "block";
  });

  /**
   * Nasconde il menu contestuale quando si clicca fuori
   */
  document.addEventListener("click", function (event) {
    if (!contextMenu.contains(event.target)) {
      contextMenu.style.display = "none";
    }
  });

  /**
   * Gestisce le azioni del menu contestuale
   */
  document.addEventListener("click", function (event) {
    const targetId = contextMenu.getAttribute("data-target-id");

    if (event.target.id === "confirmAddNode") {
      // Aggiunta di un nuovo nodo
      const selectedUserId = document.getElementById("userDropdown").value;
      if (selectedUserId) {
        addNode(selectedUserId, null); // Aggiungi come nodo isolato
      } else {
        alert("Seleziona un dipendente dal menu a tendina.");
      }
    } else if (event.target.id === "connectNode") {
      // Collegamento di un nodo isolato
      if (targetId && selectedNode) {
        setParent(targetId, selectedNode.user_id);
        selectedNode = null;
        alert("Nodo collegato con successo!");
      } else {
        alert("Seleziona prima un nodo genitore.");
      }
    } else if (event.target.id === "editNode") {
      // Modifica del nodo
      const newName = prompt("Inserisci il nuovo nome per il nodo:");
      if (newName) {
        editNode(targetId, newName);
      }
    } else if (event.target.id === "deleteNode") {
      // Eliminazione del nodo
      if (confirm("Sei sicuro di voler eliminare questo nodo?")) {
        deleteNode(targetId);
      }
    }
  });

  /**
   * Popola dinamicamente il dropdown con tutti i dipendenti
   */
  function populateUserDropdown() {
    const dropdown = document.getElementById("userDropdown");
    if (dropdown.childElementCount > 1) return; // Evita di ripopolare

    fetch("api/organigram/get_all_employees.php")
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                dropdown.innerHTML = "<option value=''>Seleziona un dipendente</option>";
                data.data.forEach(employee => {
                    const option = document.createElement("option");
                    option.value = employee.user_id; // Usa l'ID corretto
                    option.textContent = `${employee.name} - ${employee.role || "Ruolo non definito"}`; // Mostra nome e ruolo
                    dropdown.appendChild(option);
                });
            } else {
                console.error("Errore nel caricamento del dropdown:", data.message);
            }
        })
        .catch(error => console.error("Errore:", error));
}

  /**
   * Aggiunge un nuovo nodo all'organigramma
   */
  function addNode(userId, parentId) {
    fetch("api/organigram/add_organigram_node.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ user_id: userId, parent_id: parentId })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert("Nodo aggiunto con successo!");
          location.reload();
        } else {
          alert(data.message);
        }
      })
      .catch(error => console.error("Errore nell'aggiunta del nodo:", error));
  }

  /**
   * Modifica il nome di un nodo esistente
   */
  function editNode(nodeId, newName) {
    fetch("api/organigram/update_organigram_node.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ node_id: nodeId, name: newName })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert("Nodo aggiornato con successo!");
          location.reload();
        } else {
          alert(data.message);
        }
      })
      .catch(error => console.error("Errore nella modifica del nodo:", error));
  }

  /**
   * Elimina un nodo dall'organigramma
   */
  function deleteNode(nodeId) {
    fetch("api/organigram/delete_organigram_node.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ node_id: nodeId })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert("Nodo eliminato con successo!");
          location.reload();
        } else {
          alert(data.message);
        }
      })
      .catch(error => console.error("Errore nell'eliminazione del nodo:", error));
  }

  /**
   * Imposta un nodo come figlio di un altro
   */
  function setParent(nodeId, parentId) {
    fetch("api/organigram/set_parent.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ node_id: nodeId, parent_id: parentId })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Relazione genitore-figlio creata con successo!");
                updateHierarchy(); // Ricarica la gerarchia
            } else {
                console.error("Errore nella relazione genitore-figlio:", data.message);
            }
        })
        .catch(error => console.error("Errore nella richiesta di assegnazione:", error));
  }
});
