document.addEventListener("DOMContentLoaded", function () {
    const width = 1200;
    const height = 800;

    // Creazione dello spazio SVG per l'organigramma
    const svg = d3.select("#orgChartDiv")
        .append("svg")
        .attr("width", width)
        .attr("height", height)
        .append("g")
        .attr("transform", "translate(50, 50)");

    let selectedOrphanNode = null; // Nodo orfano selezionato per il collegamento

    // Funzione per ottenere i dati dall'API
    function fetchData() {
        return fetch("api/organigram/get_organigram_data.php")
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const rootNode = { user_id: "azienda", name: "Azienda XYZ", parent_id: null };
                    const enrichedData = [rootNode, ...data.data.map(node => ({
                        ...node,
                        parent_id: node.parent_id || "azienda"
                    }))];
                    return enrichedData;
                } else {
                    throw new Error(data.message);
                }
            });
    }

    // Funzione per salvare la posizione dei nodi
    function saveNodePosition(nodeId, parentId) {
        fetch("api/organigram/link_orphan_node.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ orphan_id: nodeId, parent_id: parentId })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log(`Nodo ${nodeId} collegato al genitore ${parentId}.`);
                    updateOrganigram();
                } else {
                    console.error("Errore nel salvataggio del collegamento:", data.message);
                }
            })
            .catch(error => console.error("Errore nella richiesta di salvataggio:", error));
    }

    // Funzione per creare l'organigramma
    function createOrganigram(data) {
        if (!data || data.length === 0) {
            console.error("Nessun dato disponibile per creare l'organigramma.");
            return;
        }

        // Rimuovi duplicati di "Azienda XYZ"
        data = data.filter((node, index, self) =>
            index === self.findIndex((n) => n.user_id === node.user_id)
        );

        // Usa D3 per creare la gerarchia dai dati
        const root = d3.stratify()
            .id(d => d.user_id)
            .parentId(d => d.parent_id)(data);

        const treeLayout = d3.tree().size([width - 200, height - 100]);
        treeLayout(root);

        // Rimuovi elementi precedenti
        svg.selectAll(".link").remove();
        svg.selectAll(".node").remove();

        // Disegna i collegamenti
        svg.selectAll(".link")
            .data(root.links())
            .join("line")
            .attr("class", "link")
            .attr("x1", d => d.source.x)
            .attr("y1", d => d.source.y)
            .attr("x2", d => d.target.x)
            .attr("y2", d => d.target.y)
            .attr("stroke", "#ccc")
            .attr("stroke-width", 2)
            .attr("marker-end", "url(#arrowhead)");

        // Disegna i nodi
        const nodes = svg.selectAll(".node")
            .data(root.descendants())
            .join("g")
            .attr("class", "node")
            .attr("transform", d => `translate(${d.x},${d.y})`)
            .call(d3.drag()
                .on("start", function (event, d) {
                    d3.select(this).raise().attr("stroke", "black");
                })
                .on("drag", function (event, d) {
                    d3.select(this).attr("transform", `translate(${event.x},${event.y})`);
                })
                .on("end", function (event, d) {
                    if (selectedOrphanNode) {
                        // Collegamento del nodo orfano a questo nodo
                        saveNodePosition(selectedOrphanNode.user_id, d.data.user_id);
                        selectedOrphanNode = null; // Resetta il nodo selezionato
                    }
                }));

        nodes.append("circle")
            .attr("r", 20)
            .attr("fill", d => (d.data.user_id === "azienda" ? "blue" : "orange"))
            .on("contextmenu", function (event, d) {
                showContextMenu(event, d);
            });

        nodes.append("text")
            .attr("dy", -30)
            .attr("text-anchor", "middle")
            .text(d => d.data.name || "Sconosciuto");

        // Disegna i nodi orfani separatamente
        const orphanNodes = data.filter(node => !node.parent_id || !data.some(n => n.user_id === node.parent_id));
        const orphanGroup = svg.append("g").attr("class", "orphan-nodes");

        orphanGroup.selectAll(".orphan-node")
            .data(orphanNodes)
            .join("g")
            .attr("class", "orphan-node")
            .attr("transform", (d, i) => `translate(${50 + i * 150}, ${height - 100})`)
            .call(d3.drag()
                .on("start", function (event, d) {
                    d3.select(this).raise().attr("stroke", "black");
                })
                .on("drag", function (event, d) {
                    d3.select(this).attr("transform", `translate(${event.x},${event.y})`);
                })
                .on("end", function (event, d) {
                    selectedOrphanNode = d; // Seleziona il nodo orfano per collegarlo
                    console.log(`Nodo orfano selezionato: ${d.name}`);
                }));

        orphanGroup.selectAll(".orphan-node")
            .append("circle")
            .attr("r", 20)
            .attr("fill", "red")
            .on("contextmenu", function (event, d) {
                showContextMenu(event, d);
            });

        orphanGroup.selectAll(".orphan-node")
            .append("text")
            .attr("dy", -30)
            .attr("text-anchor", "middle")
            .text(d => d.name || "Orfano");
    }

    // Definisce una freccia per i collegamenti
    svg.append("defs").append("marker")
        .attr("id", "arrowhead")
        .attr("viewBox", "0 0 10 10")
        .attr("refX", 5)
        .attr("refY", 5)
        .attr("markerWidth", 6)
        .attr("markerHeight", 6)
        .attr("orient", "auto")
        .append("path")
        .attr("d", "M 0 0 L 10 5 L 0 10 Z")
        .attr("fill", "#ccc");

    // Funzione per mostrare il menu contestuale
    function showContextMenu(event, d) {
        event.preventDefault();

        const contextMenu = document.getElementById("contextMenu");
        const { clientX: mouseX, clientY: mouseY } = event;

        contextMenu.style.top = `${mouseY}px`;
        contextMenu.style.left = `${mouseX}px`;
        contextMenu.style.display = "block";

        // Assegna ID al menu contestuale
        contextMenu.setAttribute("data-node-id", d.user_id);
    }

    // Nasconde il menu contestuale quando si clicca fuori
    document.addEventListener("click", function (event) {
        const contextMenu = document.getElementById("contextMenu");
        if (!contextMenu.contains(event.target)) {
            contextMenu.style.display = "none";
        }
    });

    // Gestisce le azioni del menu contestuale
    document.getElementById("contextMenu").addEventListener("click", function (event) {
        const nodeId = this.getAttribute("data-node-id");

        if (event.target.id === "deleteNode") {
            deleteNode(nodeId);
        } else if (event.target.id === "editNode") {
            const newName = prompt("Inserisci il nuovo nome del nodo:");
            if (newName) {
                editNode(nodeId, newName);
            }
        } else if (event.target.id === "addNode") {
            addNode(nodeId);
        }
    });

    // Funzione per eliminare un nodo
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
                    updateOrganigram();
                } else {
                    console.error("Errore nell'eliminazione del nodo:", data.message);
                }
            })
            .catch(error => console.error("Errore nella richiesta di eliminazione:", error));
    }

    // Funzione per modificare il nome di un nodo
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
                    updateOrganigram();
                } else {
                    console.error("Errore nella modifica del nodo:", data.message);
                }
            })
            .catch(error => console.error("Errore nella richiesta di modifica:", error));
    }

    // Funzione per aggiungere un nodo
    function addNode(parentId) {
        const name = prompt("Inserisci il nome del nuovo nodo:");
        if (!name) return;

        fetch("api/organigram/add_organigram_node.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user_id: null, parent_id: parentId, name: name })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Nodo aggiunto con successo!");
                    updateOrganigram();
                } else {
                    console.error("Errore nell'aggiunta del nodo:", data.message);
                }
            })
            .catch(error => console.error("Errore nella richiesta di aggiunta:", error));
    }

    // Funzione per aggiornare l'organigramma
    function updateOrganigram() {
        fetchData()
            .then(createOrganigram)
            .catch(error => console.error("Errore durante il caricamento dell'organigramma:", error));
    }

    // Recupera i dati e crea l'organigramma iniziale
    updateOrganigram();
});
