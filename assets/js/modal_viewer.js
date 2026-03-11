function showPdf(filePath) {
    console.log("Visualizzando PDF:", filePath);  // Debug
    var modal = document.getElementById('pdf-modal');
    var modalContent = document.getElementById('pdf-modal-content');
    modalContent.src = filePath;
    modal.style.display = 'block';
}

function showImage(filePath) {
    console.log("Visualizzando Immagine:", filePath);  // Debug
    var modal = document.getElementById('image-modal');
    var modalContent = document.getElementById('image-modal-content');
    modalContent.src = filePath;
    modal.style.display = 'block';
}

// Funzione per chiudere il modal
function closeModal(modalId) {
    var modal = document.getElementById(modalId);
    modal.style.display = 'none';
}

// Chiudi il modal se l'utente clicca fuori dal contenuto
window.onclick = function(event) {
    var pdfModal = document.getElementById('pdf-modal');
    var imageModal = document.getElementById('image-modal');
    if (event.target == pdfModal) {
        pdfModal.style.display = 'none';
    }
    if (event.target == imageModal) {
        imageModal.style.display = 'none';
    }
}

// Funzione per mostrare i PDF
function showPdf(filePath) {
    var modal = document.getElementById("pdf-modal");
    var iframe = document.getElementById("pdf-modal-content");
    iframe.src = filePath;
    modal.style.display = "block";
}

// Funzione per mostrare le immagini
function showImage(filePath) {
    var modal = document.getElementById("image-modal");
    var img = document.getElementById("image-modal-content");
    img.src = filePath;
    modal.style.display = "block";
}

// Funzione per chiudere i modali
function closeModal(modalId) {
    var modal = document.getElementById(modalId);
    modal.style.display = "none";
}
