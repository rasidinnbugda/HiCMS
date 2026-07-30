<?php

declare(strict_types=1);

/**
 * HiBlog — Manşet
 *
 * @var array{entry: HiCMS\Model\Entry} $data
 * @package HiBlog
 */

$entry = $data['entry'] ?? null;

if ($entry === null) {
    return;
}
?>
<section class="hero" aria-label="Öne çıkan yazı">
    <div class="wrap hero-inner">
        <div>
            <p class="hero-kicker">
                <?= hiblog_icon('star', 13) ?>Öne çıkan
            </p>

            <?= hiblog_meta($entry) ?>

            <h2 class="hero-title">
                <a href="<?= esc_url(hi_permalink($entry)) ?>"><?= hi_title($entry) ?></a>
            </h2>

            <p class="hero-lede"><?= hi_excerpt($entry, 220) ?></p>

            <a class="button button-primary" href="<?= esc_url(hi_permalink($entry)) ?>">
                Yazıyı oku <?= hiblog_icon('arrow', 15) ?>
            </a>
        </div>

        <div class="hero-media">
            <a href="<?= esc_url(hi_permalink($entry)) ?>" tabindex="-1" aria-hidden="true">
                <?= hi_thumbnail($entry, '(max-width: 1000px) 100vw, 460px') ?>
            </a>
        </div>
    </div>
</section>
