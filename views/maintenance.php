<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aggiornamento in corso</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #cccccc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .maintenance-container {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            padding: 48px 40px;
            max-width: 600px;
            width: 90%;
            text-align: center;
            border-top: 4px solid #cd211d;
        }
        .maintenance-logo {
            max-width: 200px;
            margin: 0 auto 32px;
            display: block;
        }
        .maintenance-title {
            font-size: 2.5rem;
            color: #cd211d;
            margin: 0 0 24px 0;
            font-weight: 700;
        }
        .maintenance-message {
            font-size: 1.1rem;
            color: #666666;
            line-height: 1.6;
            margin: 0;
        }
        .maintenance-default {
            font-size: 1.1rem;
            color: #666666;
            line-height: 1.6;
            margin: 0;
        }
        @media (max-width: 600px) {
            .maintenance-container {
                padding: 32px 24px;
            }
            .maintenance-title {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <?php
        // Logo aziendale (se esiste)
        $logoPath = 'assets/logo/logo.png';
        if (file_exists($logoPath)) {
            echo '<img src="' . htmlspecialchars($logoPath) . '" alt="Logo" class="maintenance-logo">';
        }
        ?>
        <h1 class="maintenance-title">Aggiornamento in corso</h1>
        <?php if (!empty($message)): ?>
            <p class="maintenance-message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <p class="maintenance-default">Il sito è temporaneamente in manutenzione.<br>Torneremo online a breve.</p>
        <?php endif; ?>
    </div>
</body>
</html>

