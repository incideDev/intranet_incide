
    <h1>Modifica Email</h1>
    <form action="index.php?page=edit_email" method="post">
        <input type="hidden" name="id" value="<?php echo $email['id']; ?>">
        <label for="protocol_number">Numero di Protocollo:</label>
        <input type="text" id="protocol_number" name="protocol_number" value="<?php echo htmlspecialchars($email['protocol_number']); ?>" required>
        <label for="subject">Oggetto:</label>
        <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($email['subject']); ?>" required>
        <label for="body">Corpo del Messaggio:</label>
        <textarea id="body" name="body" required><?php echo htmlspecialchars($email['body']); ?></textarea>
        <button type="submit">Salva Modifiche</button>
    </form>
