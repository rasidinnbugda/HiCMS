<?php

declare(strict_types=1);

/**
 * HiAdmin — Genel bakış
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Kernel;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

$user = $app->auth()->user();

$postCounts    = $app->content()->statusCounts('post');
$pageCount     = $app->content()->countOfType('page');
$commentCounts = $app->comments()->statusCounts();
$mediaStats    = $app->mediaRepo()->stats();
$totalViews    = $app->content()->totalViews();

// Devam edilecek işler: taslak ve inceleme bekleyenler, en son dokunulan önce.
$queue = $app->content()->get([
    'type'     => 'all',
    'status'   => 'all',
    'perPage'  => 6,
    'orderBy'  => 'updated_at',
    'orderDir' => 'desc',
]);

$queue = array_values(array_filter(
    $queue,
    static fn(object $entry): bool => in_array($entry->status, ['draft', 'pending'], true)
));

$recentComments = $app->auth()->can('comments.moderate') ? $app->comments()->recent(4) : [];
$activity       = $app->auth()->can('system.logs') ? $app->audit()->recent(6) : [];

$firstName = explode(' ', trim((string) $user?->displayName))[0] ?? '';

$page = [
    'title'       => $firstName !== '' ? 'Merhaba ' . $firstName : 'Genel bakış',
    'slug'        => 'index',
    'description' => 'Sitenin durumu, bekleyen işler ve son hareketler.',
    'actions'     => '<a class="btn" href="' . esc_url($app->urls()->to()) . '" target="_blank" rel="noopener">'
        . admin_icon('external', 15) . 'Siteyi görüntüle</a>'
        . '<a class="btn btn-primary" href="content-edit.php?tur=post">' . admin_icon('plus', 15) . 'Yeni yazı</a>',
];

admin_head($page);

/* -------------------------------------------------------------------------
 * Bekleyen işler için uyarı
 * ---------------------------------------------------------------------- */

if (($commentCounts['pending'] ?? 0) > 0 && $app->auth()->can('comments.moderate')) {
    echo ui_notice(
        'warning',
        Str::format('%d yorum onay bekliyor. Onaylanmayan yorumlar sitede görünmez.', $commentCounts['pending']),
        '<a class="btn btn-sm" href="comments.php?durum=pending">Yorumları incele</a>'
    );
}

/* -------------------------------------------------------------------------
 * Metrik şeridi
 * ---------------------------------------------------------------------- */

echo ui_metrics([
    [
        'label' => 'Yayındaki yazı',
        'value' => Str::number($postCounts['published'] ?? 0),
        'note'  => ($postCounts['draft'] ?? 0) > 0
            ? Str::format('%d taslak bekliyor', $postCounts['draft'])
            : 'taslak yok',
        'href'  => 'content.php?tur=post',
    ],
    [
        'label' => 'Görüntülenme',
        'value' => Str::number($totalViews),
        'note'  => 'tüm içerikler toplamı',
    ],
    [
        'label' => 'Yorum',
        'value' => Str::number($commentCounts['all'] ?? 0),
        'note'  => ($commentCounts['pending'] ?? 0) > 0
            ? Str::format('%d beklemede', $commentCounts['pending'])
            : 'bekleyen yok',
        'href'  => $app->auth()->can('comments.moderate') ? 'comments.php' : '',
    ],
    [
        'label' => 'Medya',
        'value' => Str::number($mediaStats['count']),
        'note'  => Str::bytes($mediaStats['bytes']) . ' kullanılıyor',
        'href'  => $app->auth()->can('media.upload') ? 'media.php' : '',
    ],
]);
?>

<div class="cols-main mt-3">
    <div>
        <?php /* Devam edilecek içerikler */ ?>
        <section class="panel">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Devam edilecekler</h2>
                    <p class="panel-sub">Taslak ve inceleme bekleyen içerikler</p>
                </div>
                <div class="panel-actions">
                    <a class="btn btn-sm" href="content.php?tur=post&amp;durum=draft">Tümü</a>
                </div>
            </header>

            <?php if ($queue !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Başlık</th>
                                <th>Tür</th>
                                <th>Durum</th>
                                <th>Güncellendi</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue as $entry) : ?>
                                <?php $type = $app->types()->get($entry->type); ?>
                                <tr>
                                    <td>
                                        <a class="cell-title" href="content-edit.php?id=<?= (int) $entry->id ?>">
                                            <?= esc_html($entry->title) ?>
                                        </a>
                                        <span class="cell-sub">
                                            <?= esc_html($entry->author?->displayName ?? '—') ?>
                                        </span>
                                    </td>
                                    <td class="muted small"><?= esc_html($type?->singular ?? $entry->type) ?></td>
                                    <td><?= ui_status($entry->status) ?></td>
                                    <td class="muted small nowrap"><?= ui_time($entry->updatedAt) ?></td>
                                    <td class="fit">
                                        <a class="btn btn-sm" href="content-edit.php?id=<?= (int) $entry->id ?>">
                                            Düzenle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?= ui_empty(
                    'check',
                    'Bekleyen iş yok',
                    'Tüm içerikleriniz yayında. Yeni bir fikir için yazı ekleyebilirsiniz.',
                    '<a class="btn btn-primary btn-sm" href="content-edit.php?tur=post">Yeni yazı</a>'
                ) ?>
            <?php endif; ?>
        </section>

        <?php /* Son yorumlar */ ?>
        <?php if ($recentComments !== []) : ?>
            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Son yorumlar</h2>
                        <p class="panel-sub">Okuyuculardan gelen son hareketler</p>
                    </div>
                    <div class="panel-actions">
                        <a class="btn btn-sm" href="comments.php">Tümü</a>
                    </div>
                </header>
                <div class="panel-body">
                    <ul class="feed">
                        <?php foreach ($recentComments as $comment) : ?>
                            <li>
                                <span class="ico-wrap"><?= admin_icon('message', 15) ?></span>
                                <div class="body">
                                    <p>
                                        <strong><?= esc_html($comment->authorName) ?></strong>
                                        <?= ui_status($comment->status) ?>
                                    </p>
                                    <p class="muted small mb-0">
                                        <?= esc_html(Str::limit($comment->body, 120)) ?>
                                    </p>
                                    <time><?= esc_html(Dates::ago($comment->createdAt)) ?>
                                        <?php if ($comment->entryTitle !== '') : ?>
                                            · <?= esc_html($comment->entryTitle) ?>
                                        <?php endif; ?>
                                    </time>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <?php /* Yan sütun */ ?>
    <div>
        <section class="box">
            <header class="box-head"><?= admin_icon('activity', 15) ?>Sistem</header>
            <div class="box-body">
                <ul class="kv">
                    <li><span class="k">HiCMS</span><span class="v"><?= esc_html(Kernel::VERSION) ?></span></li>
                    <li><span class="k">PHP</span><span class="v"><?= esc_html(PHP_VERSION) ?></span></li>
                    <li><span class="k">Tema</span><span class="v"><?= esc_html($app->themes()->active()?->name ?? '—') ?></span></li>
                    <li><span class="k">Eklenti</span><span class="v"><?= count($app->plugins()->activeSlugs()) ?> etkin</span></li>
                    <li><span class="k">Sayfa</span><span class="v"><?= Str::number($pageCount) ?></span></li>
                </ul>
            </div>
            <?php if ($app->auth()->can('system.update')) : ?>
                <?php $update = $app->updater()->check(); ?>
                <footer class="box-foot">
                    <?php if ($update['available']) : ?>
                        <span class="pill is-warn">v<?= esc_html($update['version']) ?> hazır</span>
                        <span class="spacer"></span>
                        <a class="btn btn-sm btn-primary" href="system.php">Güncelle</a>
                    <?php else : ?>
                        <span class="muted small">Sistem güncel</span>
                        <span class="spacer"></span>
                        <a class="btn btn-sm" href="system.php">Sistem</a>
                    <?php endif; ?>
                </footer>
            <?php endif; ?>
        </section>

        <section class="box">
            <header class="box-head"><?= admin_icon('plus', 15) ?>Hızlı işlem</header>
            <div class="box-body col">
                <a class="btn btn-block" href="content-edit.php?tur=post">
                    <?= admin_icon('file-text', 15) ?>Yeni yazı
                </a>
                <a class="btn btn-block" href="content-edit.php?tur=page">
                    <?= admin_icon('file', 15) ?>Yeni sayfa
                </a>
                <?php if ($app->auth()->can('media.upload')) : ?>
                    <a class="btn btn-block" href="media.php">
                        <?= admin_icon('upload', 15) ?>Dosya yükle
                    </a>
                <?php endif; ?>
                <?php if ($app->auth()->can('appearance.manage')) : ?>
                    <a class="btn btn-block" href="menus.php">
                        <?= admin_icon('list', 15) ?>Menüleri düzenle
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($activity !== []) : ?>
            <section class="box">
                <header class="box-head"><?= admin_icon('clock', 15) ?>Son hareketler</header>
                <div class="box-body">
                    <ul class="feed">
                        <?php foreach ($activity as $row) : ?>
                            <li>
                                <div class="body">
                                    <p class="mb-0">
                                        <strong><?= esc_html((string) ($row['actor'] ?? 'Sistem')) ?></strong>
                                        <span class="muted"><?= esc_html((string) ($row['summary'] ?? $row['action'])) ?></span>
                                    </p>
                                    <time><?= esc_html(Dates::ago((string) $row['created_at'])) ?></time>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <footer class="box-foot">
                    <span class="spacer"></span>
                    <a class="btn btn-sm btn-ghost" href="system.php?sekme=gunluk">Tüm günlük</a>
                </footer>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php admin_foot(); ?>
