<?php

declare(strict_types=1);

/**
 * HiBlog — Ana sayfa ve son çare şablonu
 *
 * @package HiBlog
 */

hi_header();

$featured = hi_featured_entry();

if ($featured !== null) {
    hi_part('hero', '', ['entry' => $featured]);
}
?>

<div class="wrap layout">
    <div>
        <div class="section-head">
            <h2 class="section-title">
                <?= hi_is_paged() ? esc_html(__f('Yazılar — sayfa %d', hi_view()->page)) : 'Son yazılar' ?>
            </h2>
            <span class="section-count"><?= esc_html(__f('%d yazı', hi_found())) ?></span>
        </div>

        <?php if (hi_has_entries()) : ?>
            <div class="entry-list">
                <?php while (hi_has_next()) : hi_the_entry(); ?>
                    <?php hi_part('entry-card'); ?>
                <?php endwhile; ?>
            </div>

            <?= hi_pagination() ?>
        <?php else : ?>
            <div class="empty-state">
                <span class="empty-mark"><?= hiblog_icon('compass', 24) ?></span>
                <h2>Henüz yazı yok</h2>
                <p>İlk yazı yayınlandığında burada görünecek.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php hi_sidebar(); ?>
</div>

<?php hi_footer(); ?>
