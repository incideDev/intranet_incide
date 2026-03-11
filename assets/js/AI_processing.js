document.getElementById('aiProcessingForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const formData = new FormData(this);

    try {
        const response = await fetch('index.php?page=ai_processing', {
            method: 'POST',
            body: formData,
        });

        const result = await response.json();
        const resultDiv = document.getElementById('aiResult');

        if (result.error) {
            resultDiv.innerHTML = `<strong>Errore:</strong> ${result.error}`;
        } else {
            const bestResult = result.best_result;
            if (bestResult) {
                resultDiv.innerHTML = `
                    <strong>Risultato:</strong><br>
                    <strong>Risposta:</strong> ${bestResult.answer}<br>
                    <strong>Confidenza:</strong> ${(bestResult.score * 100).toFixed(2)}%<br>
                    <strong>Contesto:</strong> ${bestResult.context_window}<br>
                    <strong>Posizione nel testo:</strong> ${bestResult.start} - ${bestResult.end}<br>
                `;
            } else {
                resultDiv.innerHTML = `<strong>Errore:</strong> Nessun risultato trovato.`;
            }
        }
    } catch (error) {
        document.getElementById('aiResult').innerHTML = `<strong>Errore durante il fetch:</strong> ${error.message}`;
    }
});
