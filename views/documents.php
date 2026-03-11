<div class="main-container">
    <div class="view-toggle">
        <button id="gridViewButton" class="view-tab active">Vista Griglia</button>
        <button id="tableViewButton" class="view-tab">Vista Tabella</button>
    </div>

    <div id="gridView" class="document-grid title">
        <?php if (isset($documents) && is_array($documents)): ?>
            <?php foreach ($documents as $item): ?>
                <?php 
                $filePath = $fullPath . '/' . $item['name'];
                $extension = pathinfo($item['name'], PATHINFO_EXTENSION);

                // Determina l'icona da usare in base al tipo di file
                if (is_dir($filePath)) {
                    $icon = 'assets/icons/folder.png'; // Cartella
                } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $icon = 'assets/icons/image.png'; // Immagine
                } elseif ($extension === 'pdf') {
                    $icon = 'assets/icons/pdf.png'; // PDF
                } else {
                    $icon = 'assets/icons/file.png'; // Altri file
                }
                ?>
                <div class="document-card" onclick="window.location.href='<?php echo is_dir($filePath) ? 'index.php?page=' . htmlspecialchars($section) . '&path=' . urlencode($currentPath . $item['name'] . '/') : $filePath; ?>'">
                    <div class="document-icon">
                        <img src="<?php echo $icon; ?>" alt="Icona file">
                    </div>
                    <div class="document-info">
                        <p><?php echo htmlspecialchars($item['name']); ?></p>
                        <p><?php echo htmlspecialchars($item['type']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Nessun documento trovato.</p>
        <?php endif; ?>
    </div>

    <!-- Vista Tabella -->
    <div id="tableView" style="display:none;">
                <?php
                    $selectedFolder = isset($_GET['folder']) ? $_GET['folder'] : null;

                    $table_columns = [
                        "Nome",
                        "Tipo",
                        "Data"
                    ];

                    include 'views/components/table_component.php';
                    renderTable("documentsTable", $table_columns, "api/documents/get_documents.php?folder=" . urlencode($selectedFolder));
                ?>
    </div>
</div>

<!-- Modali per i vari tipi di file -->
<div id="pdfModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <iframe id="pdf-modal-content" style="width:100%;height:80vh;"></iframe>
    </div>
</div>

<div id="imageModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <img id="image-modal-content" style="width:100%;height:auto;">
    </div>
</div>

<div id="cadModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <div id="cad-viewer-content" style="width:100%;height:80vh;"></div>
    </div>
</div>

<div id="videoModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <video id="video-modal-content" controls style="width:100%;height:auto;"></video>
    </div>
</div>

<script src="assets/js/documents.js" defer></script>
<script src="assets/js/modal_viewer.js" defer></script>
