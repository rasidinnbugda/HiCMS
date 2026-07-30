<?php

declare(strict_types=1);

/**
 * HiBlog — Sayfa altı
 *
 * @package HiBlog
 */
?>
</main>

<footer class="site-footer">
    <div class="wrap footer-top">
        <div>
            <p class="site-title"><a href="<?= esc_url(hi_url()) ?>"><?= esc_html(hi_site_name()) ?></a></p>
            <p class="footer-about">
                <?= esc_html((string) hi_option('site_description', hi_site_tagline())) ?>
            </p>
            <?= hiblog_social() ?>
        </div>

        <div>
            <h2 class="footer-heading">Keşfet</h2>
            <ul class="footer-links">
                <?php foreach (hi_all_terms('category') as $footerTerm) : ?>
                    <li>
                        <a href="<?= esc_url(hi_term_url($footerTerm)) ?>">
                            <?= esc_html($footerTerm->name) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li><a href="<?= esc_url(hi_url('feed')) ?>">RSS beslemesi</a></li>
            </ul>
        </div>

        <div>
            <?php if (hi_has_widgets('footer')) : ?>
                <?= hi_widgets('footer') ?>
            <?php endif; ?>

            <h2 class="footer-heading" style="margin-top:1.25rem">Site</h2>
            <ul class="footer-links">
                <?php if (hi_has_menu('footer')) : ?>
                    <?php foreach (hi()->menus()->items('footer') as $footerItem) : ?>
                        <li>
                            <a href="<?= esc_url(hi_url((string) $footerItem['url'])) ?>">
                                <?= esc_html((string) $footerItem['label']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php else : ?>
                    <li><a href="<?= esc_url(hi_url('hakkinda')) ?>">Hakkında</a></li>
                    <li><a href="<?= esc_url(hi_url('iletisim')) ?>">İletişim</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="wrap">
        <div class="footer-bottom">
            <span>© <?= esc_html(date('Y')) ?> <?= esc_html(hi_site_name()) ?></span>
            <?php if ((string) hi_option('footer_note', '') !== '') : ?>
                <span aria-hidden="true">·</span>
                <span><?= esc_html((string) hi_option('footer_note')) ?></span>
            <?php endif; ?>
            <span class="spacer"></span>
            <a href="#content">Başa dön</a>
        </div>
    </div>
</footer>

<?php hi_foot(); ?>
</body>
</html>
