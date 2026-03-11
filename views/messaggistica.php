<?php
// Leggi URL WebSocket da env (default: localhost:8080 per retrocompatibilità)
$env = \Services\GareService::expandEnvPlaceholders(\Services\GareService::loadEnvConfig());
$wsUrl = $env['WEBSOCKET_URL'] ?? 'ws://localhost:8080';
?>
<div class="main-container" data-user-id="<?php echo $_SESSION['user_id']; ?>" data-username="<?php echo $_SESSION['username']; ?>" data-websocket-url="<?php echo htmlspecialchars($wsUrl); ?>">
    <div id="chat-container" class="chat-container">
        <div class="user-list">
            <h3>Utenti e Gruppi</h3>
            <ul id="user-list">
                <!-- Gli utenti e i gruppi saranno aggiunti qui dinamicamente -->
            </ul>
        </div>
        <div class="chat-area">
            <h2 id="chat-title">Chat</h2> <!-- Aggiorniamo dinamicamente questo titolo -->
            <div id="chat-box" class="chat-box">
                <!-- I messaggi appariranno qui -->
            </div>
            <form id="chat-form" class="chat-form">
                <input type="text" id="message-input" placeholder="Scrivi il tuo messaggio..." required>
                <button type="submit">Invia</button>
            </form>
        </div>
    </div>
</div>
<script src="assets/js/websocket.js"></script>
