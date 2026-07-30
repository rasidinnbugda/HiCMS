<?php

declare(strict_types=1);

/**
 * HiBlog — Liste görünümündeki içerik kartı
 *
 * Kart çerçevesi yoktur: ayrımı kılçizgi yapar.
 *
 * @package HiBlog
 */

$entry = hi_entry();

if ($entry === null) {
    return;
}
?>
<article <?= hi_entry_class(['entry-card']) ?>>
    <div class="entry-card-body">
        <?= hiblog_meta() ?>

        <h2 class="entry-card-title">
            <a href="<?= esc_url(hi_permalink()) ?>"><?= hi_title() ?></a>
        </h2>

        <p class="entry-card-lede"><?= hi_excerpt(null, 200) ?></p>

        <div class="entry-card-foot">
            <a class="read-more" href="<?= esc_url(hi_permalink()) ?>">
                Devamını oku <?= hiblog_icon('arrow', 14) ?>
            </a>

            <span aria-hidden="true">·</span>
            <span><?= hi_author_name() ?></span>

            <?php if (hi_comment_count() > 0) : ?>
                <span aria-hidden="true">·</span>
                <span><?= esc_html(__f('%d yorum', hi_comment_count())) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Görsel yoksa hi_thumbnail() kategori renginde yer tutucu üretir. */ ?>
    <div class="entry-card-media">
        <a href="<?= esc_url(hi_permalink()) ?>" tabindex="-1" aria-hidden="true">
            <?= hi_thumbnail(null, '152px') ?>
        </a>
    </div>
</article>
