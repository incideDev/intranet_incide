<div class="main-container">
    <?php if (!empty($archivedStates)): ?>
    <div class="archived-container">
        <?php foreach ($archivedStates as $state): ?>
            <div class="archived-state">
                <h3><?php echo htmlspecialchars($state['name']); ?></h3>
                <div class="color-box" style="background-color: <?php echo htmlspecialchars($state['color']); ?>"></div>
                <p>ID: <?php echo htmlspecialchars($state['id']); ?></p>
                <div class="actions">
                    <a href="index.php?page=restore_state&id=<?php echo $state['id']; ?>" class="button">Ripristina</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p>Nessuno stato archiviato trovato.</p>
<?php endif; ?>

</div>