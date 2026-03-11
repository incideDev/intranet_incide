<div class="main-container">
    <div class="ai-processing-container">
        <h2>AI Processing</h2>
        <form id="aiProcessingForm" enctype="multipart/form-data">
            <label for="document">Carica un documento (PDF):</label>
            <input type="file" name="document" id="document" required>

            <label for="question">Inserisci una domanda:</label>
            <input type="text" name="question" id="question" required>

            <button type="submit">Elabora</button>
        </form>
        <div id="aiResult"></div>
    </div>
</div>

<script src="/assets/js/ai_processing.js"></script>

