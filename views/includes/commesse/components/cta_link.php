<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

/**
 * Componente CTA Link riusabile
 *
 * Parametri richiesti:
 * - $ctaUrl: URL destinazione
 * - $ctaLabel: Testo del bottone
 * - $ctaStyle: 'primary' | 'secondary' (default: 'primary')
 * - $ctaIcon: path icona SVG opzionale
 */

$ctaStyle = $ctaStyle ?? 'primary';
$ctaIcon = $ctaIcon ?? null;

$styleMap = [
    'primary' => 'background: #007bff; color: white; border: none;',
    'secondary' => 'background: transparent; color: #007bff; border: 1px solid #007bff;'
];

$btnStyle = $styleMap[$ctaStyle] ?? $styleMap['primary'];
?>

<a href="<?= htmlspecialchars($ctaUrl, ENT_QUOTES) ?>"
   class="cta-link-btn"
   style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 0.95em; font-weight: 500; text-decoration: none; transition: all 0.2s; <?= $btnStyle ?>">
    <?php if ($ctaIcon): ?>
        <img src="<?= htmlspecialchars($ctaIcon, ENT_QUOTES) ?>" alt="" style="width: 16px; height: 16px; opacity: 0.9;">
    <?php endif; ?>
    <span><?= htmlspecialchars($ctaLabel, ENT_QUOTES) ?></span>
</a>

<style>
.cta-link-btn:hover {
    opacity: 0.85;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
</style>
