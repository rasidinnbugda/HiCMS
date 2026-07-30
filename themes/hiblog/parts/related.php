<?php

declare(strict_types=1);

/**
 * HiBlog — Benzer içerikler
 *
 * @var array{entry: HiCMS\Model\Entry} $data
 * @package HiBlog
 */

$entry   = $data['entry'] ?? hi_entry();
$related = $entry !== null ? hi_related(3, $entry) : [];

if ($related === []) {
    return;
}
?>
<section class="related">
    <div class="section-head">
        <h2 class="section-title">Bunlar da ilginizi çekebilir</h2>
    </div>

    <div class="related-grid">
        <?php foreach ($related as $item) : ?>
            <article class="related-item">
                <a href="<?= esc_url(hi_permalink($item)) ?>" tabindex="-1" aria-hidden="true">
                    <?= hi_thumbnail($item, '(max-width: 620px) 100vw, 300px') ?>
                </a>

                <?= hiblog_meta($item, false) ?>

                <h3 class="related-title">
                    <a href="<?= esc_url(hi_permalink($item)) ?>"><?= hi_title($item) ?></a>
                </h3>
            </article>
        <?php endforeach; ?>
    </div>
</section>
