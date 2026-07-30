<?php

declare(strict_types=1);

/**
 * HiBlog — Önceki / sonraki içerik
 *
 * @var array{entry: HiCMS\Model\Entry} $data
 * @package HiBlog
 */

$entry = $data['entry'] ?? hi_entry();

if ($entry === null) {
    return;
}

$previous = hi_adjacent('previous', $entry);
$next     = hi_adjacent('next', $entry);

if ($previous === null && $next === null) {
    return;
}
?>
<nav class="entry-nav" aria-label="İçerik gezintisi">
    <?php if ($previous !== null) : ?>
        <a class="entry-nav-link is-prev" href="<?= esc_url(hi_permalink($previous)) ?>" rel="prev">
            <span class="entry-nav-dir">← Önceki</span>
            <span class="entry-nav-title"><?= hi_title($previous) ?></span>
        </a>
    <?php else : ?>
        <div class="entry-nav-empty"></div>
    <?php endif; ?>

    <?php if ($next !== null) : ?>
        <a class="entry-nav-link is-next" href="<?= esc_url(hi_permalink($next)) ?>" rel="next">
            <span class="entry-nav-dir">Sonraki →</span>
            <span class="entry-nav-title"><?= hi_title($next) ?></span>
        </a>
    <?php else : ?>
        <div class="entry-nav-empty"></div>
    <?php endif; ?>
</nav>
