<?php

declare(strict_types=1);

/**
 * HiBlog — Statik sayfa
 *
 * @package HiBlog
 */

hi_header();

$entry = hi_has_next() ? hi_the_entry() : hi_view()->entry;

if ($entry === null) {
    hi_footer();

    return;
}
?>

<article <?= hi_entry_class(['single-page']) ?>>
    <header class="entry-header wrap">
        <div class="entry-meta">
            <time datetime="<?= esc_attr(hi_date_iso($entry)) ?>">
                <?= esc_html(HiCMS\Support\Dates::format($entry->updatedAt, 'j F Y')) ?> güncellendi
            </time>
        </div>

        <h1 class="entry-title"><?= hi_title($entry) ?></h1>

        <?php if (trim($entry->excerpt) !== '') : ?>
            <p class="entry-lede"><?= esc_html($entry->excerpt) ?></p>
        <?php endif; ?>
    </header>

    <div class="wrap">
        <?php if (hi_has_thumbnail($entry)) : ?>
            <figure class="entry-figure">
                <?= hi_thumbnail($entry, '(max-width: 1140px) 100vw, 1100px') ?>
            </figure>
        <?php endif; ?>

        <div class="entry-body">
            <?= hi_content($entry) ?>
        </div>

        <?php if (hi_comments_open($entry)) : ?>
            <?php hi_comments_template(); ?>
        <?php endif; ?>
    </div>
</article>

<?php hi_footer(); ?>
