<?php

declare(strict_types=1);

/**
 * HiBlog — Tek içerik
 *
 * Okuma odaklı olduğu için yan sütun kullanılmaz; gövde 66 karakterlik kolona
 * sabitlenir.
 *
 * @package HiBlog
 */

hi_header();

$entry = hi_has_next() ? hi_the_entry() : hi_view()->entry;

if ($entry === null) {
    hi_footer();

    return;
}

$notice = (string) (hi()->request()->query('yorum') ?? '');
?>

<article <?= hi_entry_class(['single']) ?>>
    <header class="entry-header wrap">
        <?= hiblog_meta($entry) ?>

        <h1 class="entry-title"><?= hi_title($entry) ?></h1>

        <?php if (trim($entry->excerpt) !== '') : ?>
            <p class="entry-lede"><?= esc_html($entry->excerpt) ?></p>
        <?php endif; ?>

        <div class="byline">
            <span class="avatar" aria-hidden="true"><?= hi_author_initials($entry) ?></span>
            <span>
                Yazan
                <a href="<?= esc_url(hi_author_url($entry)) ?>"><?= hi_author_name($entry) ?></a>
            </span>

            <?php if ($entry->updatedAt !== '' && $entry->updatedAt !== ($entry->publishedAt ?? '')) : ?>
                <span class="sep" aria-hidden="true">·</span>
                <span><?= esc_html(HiCMS\Support\Dates::ago($entry->updatedAt)) ?> güncellendi</span>
            <?php endif; ?>
        </div>
    </header>

    <div class="wrap">
        <?php if (hi_has_thumbnail($entry)) : ?>
            <figure class="entry-figure">
                <?= hi_thumbnail($entry, '(max-width: 1140px) 100vw, 1100px') ?>
                <?php if ($entry->image !== null && $entry->image->alt !== '') : ?>
                    <figcaption><?= esc_html($entry->image->alt) ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endif; ?>

        <div class="entry-body">
            <?= hi_content($entry) ?>
        </div>

        <footer class="entry-footer">
            <?php if (hi_terms('tag', $entry) !== []) : ?>
                <div class="tag-row">
                    <span class="tag-row-label">Etiketler</span>
                    <?= hi_the_terms('tag', 'entry-tag', $entry) ?>
                </div>
            <?php endif; ?>

            <div class="share-row">
                <span class="share-label">Paylaş</span>
                <?php
                $shareUrl   = hi_permalink($entry);
                $shareTitle = $entry->title;
                ?>
                <a class="share-link" target="_blank" rel="noopener" aria-label="X'te paylaş"
                   href="https://x.com/intent/tweet?text=<?= rawurlencode($shareTitle) ?>&amp;url=<?= rawurlencode($shareUrl) ?>">
                    <?= hiblog_icon('x-social', 15) ?>
                </a>
                <a class="share-link" target="_blank" rel="noopener" aria-label="LinkedIn'de paylaş"
                   href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($shareUrl) ?>">
                    <?= hiblog_icon('linkedin', 15) ?>
                </a>
                <a class="share-link" aria-label="E-posta ile gönder"
                   href="mailto:?subject=<?= rawurlencode($shareTitle) ?>&amp;body=<?= rawurlencode($shareUrl) ?>">
                    <?= hiblog_icon('mail', 15) ?>
                </a>
                <a class="share-link" href="<?= esc_url($shareUrl) ?>" aria-label="Bağlantıyı kopyala">
                    <?= hiblog_icon('link', 15) ?>
                </a>
            </div>

            <?php hi_part('author-box', '', ['author' => hi_author($entry)]); ?>
            <?php hi_part('entry-nav', '', ['entry' => $entry]); ?>
        </footer>

        <?php hi_part('related', '', ['entry' => $entry]); ?>

        <?php if ($notice !== '') : ?>
            <div class="comments">
                <p class="form-note">
                    <?php
                    echo esc_html(match ($notice) {
                        'yayinda'   => 'Yorumunuz yayınlandı. Teşekkürler!',
                        'beklemede' => 'Yorumunuz alındı ve onay bekliyor.',
                        default     => (string) (hi()->request()->query('mesaj') ?? 'Yorum gönderilemedi.'),
                    });
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php hi_comments_template(); ?>
    </div>
</article>

<?php hi_footer(); ?>
