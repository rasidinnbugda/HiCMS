<?php

declare(strict_types=1);

/**
 * HiBlog — Yazar kutusu
 *
 * @var array{author: HiCMS\Model\User|null} $data
 * @package HiBlog
 */

$author = $data['author'] ?? null;

if ($author === null || trim($author->bio) === '') {
    return;
}
?>
<aside class="author-box">
    <span class="avatar avatar-lg" aria-hidden="true"><?= esc_html($author->initials()) ?></span>

    <div>
        <p class="author-box-kicker">Yazar hakkında</p>
        <h3>
            <a href="<?= esc_url(hi()->links()->forAuthor($author)) ?>">
                <?= esc_html($author->displayName) ?>
            </a>
        </h3>
        <p><?= esc_html($author->bio) ?></p>
    </div>
</aside>
