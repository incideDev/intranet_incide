<h1>Aggiungi Email</h1>
    <form action="index.php?page=add_email" method="post">
        <label for="protocol_number">Numero di Protocollo:</label>
        <input type="text" id="protocol_number" name="protocol_number" required>
        <label for="subject">Oggetto:</label>
        <input type="text" id="subject" name="subject" required>
        <label for="body">Corpo del Messaggio:</label>
        <textarea id="body" name="body" required></textarea>
        <button type="submit">Salva Email</button>
    </form>

