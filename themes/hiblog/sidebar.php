<?php

declare(strict_types=1);

/**
 * HiBlog — Yan sütun
 *
 * Bileşen alanı boşsa makul bir varsayılan gösterilir; tema kurulur kurulmaz
 * yan sütun boş kalmaz.
 *
 * @package HiBlog
 */
?>
<aside class="sidebar" aria-label="Yan sütun">
    <?php if (hi_has_widgets('sidebar')) : ?>
        <?= hi_widgets('sidebar') ?>
    <?php else : ?>
        <section class="widget">
            <h2 class="widget-title">Arama</h2>
            <?= hi()->widgets()->renderWidget('search', ['placeholder' => 'Yazılarda ara…']) ?>
        </section>

        <section class="widget">
            <h2 class="widget-title">Son yazılar</h2>
            <?= hi()->widgets()->renderWidget('recent', ['count' => 4, 'numbered' => true]) ?>
        </section>

        <section class="widget">
            <h2 class="widget-title">Kategoriler</h2>
            <?= hi()->widgets()->renderWidget('terms', ['taxonomy' => 'category', 'show_count' => true]) ?>
        </section>
    <?php endif; ?>
</aside>
