<?php

declare(strict_types=1);

/**
 * HiBlog — Yorumlar
 *
 * `hi_comments_template()` tarafından yüklenir; $data['entry'] hazırdır.
 *
 * @package HiBlog
 */

use HiCMS\Model\Comment;
use HiCMS\Support\Dates;

$entry = $data['entry'] ?? hi_entry();

if ($entry === null) {
    return;
}

$comments = hi_comments($entry);
$total    = hi_comment_count($entry);
$maxDepth = max(1, (int) hi_option('comment_depth', 3));

/**
 * Yorum ağacını basar.
 *
 * @param list<Comment> $nodes
 */
if (!function_exists('hiblog_comments')) {
    function hiblog_comments(array $nodes, int $level, int $maxDepth): void
    {
        foreach ($nodes as $comment) {
            ?>
            <li class="comment" id="yorum-<?= (int) $comment->id ?>">
                <div class="comment-head">
                    <span class="avatar" aria-hidden="true">
                        <?= esc_html(HiCMS\Support\Str::initials($comment->authorName)) ?>
                    </span>

                    <div>
                        <span class="comment-author">
                            <?php if ($comment->authorUrl !== '') : ?>
                                <a href="<?= esc_url($comment->authorUrl) ?>" rel="nofollow noopener external">
                                    <?= esc_html($comment->authorName) ?>
                                </a>
                            <?php else : ?>
                                <?= esc_html($comment->authorName) ?>
                            <?php endif; ?>
                        </span>

                        <?php if ($comment->byStaff) : ?>
                            <span class="comment-badge">yazar</span>
                        <?php endif; ?>

                        <span class="comment-time">
                            <time datetime="<?= esc_attr(Dates::iso($comment->createdAt)) ?>">
                                <?= esc_html(Dates::ago($comment->createdAt)) ?>
                            </time>
                        </span>
                    </div>
                </div>

                <div class="comment-body">
                    <p><?= nl2br(esc_html($comment->body), false) ?></p>
                </div>

                <?php if ($level < $maxDepth) : ?>
                    <a class="comment-reply" href="#yorum-formu"
                       data-reply-to="<?= (int) $comment->id ?>"
                       data-reply-name="<?= esc_attr($comment->authorName) ?>">
                        <?= hiblog_icon('reply', 13) ?>Yanıtla
                    </a>
                <?php endif; ?>

                <?php if ($comment->children !== []) : ?>
                    <ol>
                        <?php hiblog_comments($comment->children, $level + 1, $maxDepth); ?>
                    </ol>
                <?php endif; ?>
            </li>
            <?php
        }
    }
}
?>

<section class="comments" id="yorumlar">
    <div class="section-head">
        <h2 class="section-title">
            <?= $total > 0 ? esc_html(__f('%d yorum', $total)) : 'Yorumlar' ?>
        </h2>
        <?php if ($total > 0) : ?>
            <span class="section-count">Sohbete katılın</span>
        <?php endif; ?>
    </div>

    <?php if ($comments !== []) : ?>
        <ol class="comment-list">
            <?php hiblog_comments($comments, 1, $maxDepth); ?>
        </ol>
    <?php else : ?>
        <p style="color:var(--ink-3);margin-bottom:2rem">
            Bu içeriğe henüz yorum yapılmamış. İlk yorumu siz bırakın.
        </p>
    <?php endif; ?>

    <?php if (hi_comments_open($entry)) : ?>
        <div class="comment-form-wrap">
            <h3 class="comment-form-title">Yorum yap</h3>
            <p class="comment-form-note">
                E-posta adresiniz yayınlanmaz.
                <?= hi_option('comment_moderation', true) ? 'Yorumunuz onaylandıktan sonra görünür.' : '' ?>
            </p>

            <p class="form-note" data-replying-to hidden></p>

            <form id="yorum-formu" method="post" action="<?= esc_url(hi_url('yorum')) ?>">
                <?= hi_csrf_field() ?>
                <input type="hidden" name="yazi" value="<?= (int) $entry->id ?>">
                <input type="hidden" name="ust_yorum" value="0" data-comment-parent>

                <?php /* Bal küpü: görünmez alan. Botlar doldurur, insanlar görmez. */ ?>
                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label for="web_adresi">Web adresi</label>
                    <input type="text" id="web_adresi" name="web_adresi" tabindex="-1" autocomplete="off">
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="yorum-ad">Adınız <span class="req">*</span></label>
                        <input class="input" type="text" id="yorum-ad" name="ad" required autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="yorum-eposta">E-posta <span class="req">*</span></label>
                        <input class="input" type="email" id="yorum-eposta" name="eposta" required autocomplete="email">
                    </div>
                </div>

                <div class="field">
                    <label for="yorum-site">Web siteniz <span style="color:var(--ink-3);font-weight:400">(isteğe bağlı)</span></label>
                    <input class="input" type="url" id="yorum-site" name="site" placeholder="https://" autocomplete="url">
                </div>

                <div class="field">
                    <label for="yorum-metin">Yorumunuz <span class="req">*</span></label>
                    <textarea class="textarea" id="yorum-metin" name="yorum" required
                              placeholder="Düşüncelerinizi paylaşın…"></textarea>
                </div>

                <div class="field">
                    <label class="consent" for="yorum-onay">
                        <input type="checkbox" id="yorum-onay" name="onay" required>
                        <span>
                            Adımın ve e-posta adresimin bu yorumu yayınlamak amacıyla saklanmasını kabul ediyorum.
                        </span>
                    </label>
                </div>

                <div class="field" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                    <button class="button button-primary" type="submit">Yorumu gönder</button>
                    <button class="button" type="button" data-cancel-reply hidden>Yanıtı iptal et</button>
                </div>
            </form>
        </div>
    <?php else : ?>
        <p class="comments-closed">Bu içerikte yorumlar kapatılmış.</p>
    <?php endif; ?>
</section>
