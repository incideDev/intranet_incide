<h1>Lista degli Utenti</h1>
<ul>
    <?php foreach ($users as $user): ?>
        <li><?php echo htmlspecialchars($user['username']) . ' - ' . htmlspecialchars($user['email']); ?></li>
    <?php endforeach; ?>
</ul>
