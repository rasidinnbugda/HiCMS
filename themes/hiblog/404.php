<?php

declare(strict_types=1);

/**
 * HiBlog — Bulunamadı
 *
 * @package HiBlog
 */

hi_header();
?>

<div class="wrap layout-narrow">
    <div class="empty-state">
        <p class="error-code" aria-hidden="true">404</p>

        <h1>Bu sayfa bulunamadı</h1>
        <p>
            Aradığınız adres taşınmış, silinmiş ya da hiç var olmamış olabilir.
            Aşağıdan arama yapabilir veya son yazılara göz atabilirsiniz.
        </p>

        <form class="search-form archive-search" role="search" method="get"
              action="<?= esc_url(hi_url('arama')) ?>" style="margin-inline:auto">
            <label class="sr-only" for="hata-arama">Arama terimi</label>
            <input type="search" id="hata-arama" name="q" placeholder="Ne arıyordunuz?" required>
            <button type="submit" aria-label="Ara"><?= hiblog_icon('search', 17) ?></button>
        </form>

        <div class="empty-actions">
            <a class="button button-primary" href="<?= esc_url(hi_url()) ?>">Ana sayfaya dön</a>
        </div>
    </div>

    <?php $recent = hi_query(['type' => 'post', 'perPage' => 3]); ?>

    <?php if ($recent !== []) : ?>
        <section class="related" style="margin-top:1.5rem">
            <div class="section-head">
                <h2 class="section-title">Son yazılar</h2>
            </div>

            <div class="related-grid">
                <?php foreach ($recent as $item) : ?>
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
    <?php endif; ?>
</div>

<?php hi_footer(); ?>
