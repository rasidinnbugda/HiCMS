<?php

declare(strict_types=1);

/**
 * HiBlog — Arşiv, taksonomi, yazar ve arama sonuçları
 *
 * Şablon hiyerarşisi taxonomy/author/search isteklerini de buraya düşürür;
 * başlık bağlama göre değişir.
 *
 * @package HiBlog
 */

hi_header();

$head = hiblog_archive_header();
?>

<header class="archive-head">
    <div class="wrap">
        <p class="archive-kicker"><?= esc_html($head['kicker']) ?></p>
        <h1 class="archive-title"><?= esc_html($head['title']) ?></h1>

        <?php if (trim($head['text']) !== '') : ?>
            <p class="archive-text"><?= esc_html($head['text']) ?></p>
        <?php endif; ?>

        <?php if (hi_is_author() && hi_queried_author() !== null) : ?>
            <div class="byline" style="justify-content:flex-start;border-top:0;margin-top:1rem;padding-top:0">
                <span class="avatar" aria-hidden="true">
                    <?= esc_html(HiCMS\Support\Str::initials(hi_queried_author()->displayName)) ?>
                </span>
                <span><?= esc_html(__f('%d yazı yayınladı', hi_found())) ?></span>
            </div>
        <?php endif; ?>

        <?php if (hi_is_search()) : ?>
            <form class="search-form archive-search" role="search" method="get"
                  action="<?= esc_url(hi_url('arama')) ?>">
                <label class="sr-only" for="arama-alani">Arama terimi</label>
                <input type="search" id="arama-alani" name="q" value="<?= esc_attr(hi_search_term()) ?>"
                       placeholder="örn. tipografi" required
                    <?= hi_search_term() === '' ? 'autofocus' : '' ?>>
                <button type="submit" aria-label="Ara"><?= hiblog_icon('search', 17) ?></button>
            </form>
        <?php endif; ?>
    </div>
</header>

<div class="wrap layout">
    <div>
        <?php if (hi_has_entries()) : ?>
            <?php if (!hi_is_search()) : ?>
                <div class="section-head">
                    <h2 class="section-title">Yazılar</h2>
                    <span class="section-count"><?= esc_html(__f('%d yazı', hi_found())) ?></span>
                </div>
            <?php endif; ?>

            <div class="entry-list">
                <?php while (hi_has_next()) : hi_the_entry(); ?>
                    <?php hi_part('entry-card'); ?>
                <?php endwhile; ?>
            </div>

            <?= hi_pagination() ?>

        <?php elseif (hi_is_search() && hi_search_term() !== '') : ?>
            <div class="empty-state">
                <span class="empty-mark"><?= hiblog_icon('search', 24) ?></span>
                <h2>Sonuç bulunamadı</h2>
                <p><?= esc_html(__f('“%s” için eşleşen içerik yok. Daha genel bir terim deneyin.', hi_search_term())) ?></p>
                <div class="empty-actions">
                    <?php foreach (array_slice(hi_all_terms('category'), 0, 3) as $suggest) : ?>
                        <a class="button" href="<?= esc_url(hi_term_url($suggest)) ?>"><?= esc_html($suggest->name) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif (hi_is_search()) : ?>
            <div class="section-head">
                <h2 class="section-title">Popüler yazılar</h2>
            </div>

            <div class="entry-list">
                <?php foreach (hi_query(['type' => 'post', 'orderBy' => 'views', 'perPage' => 4]) as $popular) : ?>
                    <?php hi_setup_entry($popular); ?>
                    <?php hi_part('entry-card'); ?>
                <?php endforeach; ?>
            </div>

        <?php else : ?>
            <div class="empty-state">
                <span class="empty-mark"><?= hiblog_icon('inbox', 24) ?></span>
                <h2>Bu arşivde içerik yok</h2>
                <p>Başka bir kategoriye göz atabilir veya ana sayfaya dönebilirsiniz.</p>
                <div class="empty-actions">
                    <a class="button" href="<?= esc_url(hi_url()) ?>">Ana sayfa</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php hi_sidebar(); ?>
</div>

<?php hi_footer(); ?>
