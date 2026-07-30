<?php

declare(strict_types=1);

/**
 * HiAdmin — Yorum denetimi
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('comments.moderate');

$selfUrl = static fn(array $extra = []): string => 'comments.php' . (($q = http_build_query(array_filter(array_merge([
    'durum'  => $_GET['durum'] ?? '',
    'ara'    => $_GET['ara'] ?? '',
    'icerik' => $_GET['icerik'] ?? '',
    'sayfa'  => $_GET['sayfa'] ?? '',
], $extra), static fn(mixed $v): bool => $v !== '' && $v !== 'all'))) !== '' ? '?' . $q : '');

if ($app->request()->isPost()) {
    admin_verify($selfUrl());

    $action = (string) ($_POST['islem'] ?? '');
    $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
    $single = (int) ($_POST['id'] ?? 0);

    // Satır işlemleri kimliği sorgu dizesinde taşır.
    if ($single === 0) {
        $single = (int) ($_GET['id'] ?? 0);
    }

    if ($single > 0) {
        $ids = [$single];
    }

    if ($action === 'reply') {
        $result = $app->comments()->reply($single, $app->auth()->id(), (string) ($_POST['yanit'] ?? ''));

        admin_redirect($selfUrl(), $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Yanıtınız yayınlandı.' : $result['error']);
    }

    if ($ids === []) {
        admin_redirect($selfUrl(), 'warning', 'Hiç yorum seçilmedi.');
    }

    if (in_array($action, ['approved', 'pending', 'spam'], true)) {
        $changed = $app->comments()->bulkStatus($ids, $action);

        admin_redirect($selfUrl(), 'success', Str::format('%d yorum güncellendi.', $changed));
    }

    if ($action === 'delete') {
        $deleted = $app->comments()->bulkDelete($ids);

        $app->audit()->record(
            action: 'comments.delete',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: 'comment',
            summary: Str::format('%d yorum silindi', $deleted),
            ip: $app->request()->ip(),
        );

        admin_redirect($selfUrl(), 'success', Str::format('%d yorum silindi.', $deleted));
    }

    if ($action === 'empty-spam') {
        $deleted = $app->comments()->emptySpam();

        admin_redirect($selfUrl(), 'success', Str::format('%d istenmeyen yorum silindi.', $deleted));
    }

    admin_redirect($selfUrl(), 'warning', 'Tanınmayan işlem.');
}

$status  = (string) ($_GET['durum'] ?? 'all');
$search  = trim((string) ($_GET['ara'] ?? ''));
$entryId = (int) ($_GET['icerik'] ?? 0);
$pageNum = max(1, (int) ($_GET['sayfa'] ?? 1));

$counts = $app->comments()->statusCounts();
$result = $app->comments()->paginate([
    'status'  => $status,
    'search'  => $search,
    'entry'   => $entryId,
    'page'    => $pageNum,
    'perPage' => 20,
]);

$page = [
    'title'       => 'Yorumlar',
    'slug'        => 'comments',
    'description' => 'Onaylanmayan yorumlar sitede görünmez.',
    'actions'     => ($counts['spam'] ?? 0) > 0
        ? '<form method="post" action="' . esc_url($selfUrl()) . '" style="display:inline">' . hi_csrf_field()
            . '<input type="hidden" name="islem" value="empty-spam">'
            . '<button class="btn btn-danger" type="submit"'
            . ui_confirm('İstenmeyen klasöründeki tüm yorumlar kalıcı olarak silinecek. Devam edilsin mi?')
            . '>' . admin_icon('trash', 15) . 'İstenmeyenleri boşalt</button></form>'
        : '',
];

admin_head($page);
?>

<section class="panel">
    <div class="filters">
        <?= ui_tabs([
            ['label' => 'Tümü', 'url' => $selfUrl(['durum' => 'all', 'sayfa' => '']),
             'active' => $status === 'all', 'count' => $counts['all']],
            ['label' => 'Onaylı', 'url' => $selfUrl(['durum' => 'approved', 'sayfa' => '']),
             'active' => $status === 'approved', 'count' => $counts['approved']],
            ['label' => 'Beklemede', 'url' => $selfUrl(['durum' => 'pending', 'sayfa' => '']),
             'active' => $status === 'pending', 'count' => $counts['pending']],
            ['label' => 'İstenmeyen', 'url' => $selfUrl(['durum' => 'spam', 'sayfa' => '']),
             'active' => $status === 'spam', 'count' => $counts['spam']],
        ]) ?>

        <span class="spacer"></span>

        <form class="row" method="get" action="comments.php">
            <input type="hidden" name="durum" value="<?= esc_attr($status) ?>">
            <label class="sr-only" for="c-ara">Ara</label>
            <input class="input" type="search" id="c-ara" name="ara" style="width:200px"
                   value="<?= esc_attr($search) ?>" placeholder="Yazar veya metin">
            <button class="btn" type="submit"><?= admin_icon('search', 15) ?></button>
        </form>
    </div>

    <?php if ($result['items'] !== []) : ?>
        <form method="post" action="<?= esc_url($selfUrl()) ?>">
            <?= hi_csrf_field() ?>

            <div class="bulk" data-bulk="comments-table">
                <span><strong data-bulk-count>0</strong> seçildi</span>
                <button class="btn btn-sm is-off" type="submit" name="islem" value="approved" data-bulk-action>Onayla</button>
                <button class="btn btn-sm is-off" type="submit" name="islem" value="pending" data-bulk-action>Beklemeye al</button>
                <button class="btn btn-sm is-off" type="submit" name="islem" value="spam" data-bulk-action>İstenmeyen</button>
                <button class="btn btn-sm btn-danger is-off" type="submit" name="islem" value="delete" data-bulk-action
                    <?= ui_confirm('Seçili yorumlar kalıcı olarak silinecek. Devam edilsin mi?') ?>>Sil</button>
                <span class="spacer"></span>
                <span class="muted"><?= esc_html(Str::format('%d yorum', $result['total'])) ?></span>
            </div>

            <div class="table-wrap">
                <table class="data" id="comments-table">
                    <thead>
                        <tr>
                            <th class="pick">
                                <label class="check">
                                    <input type="checkbox" data-check-all="comments-table">
                                    <span class="sr-only">Tümünü seç</span>
                                </label>
                            </th>
                            <th>Yorum</th>
                            <th>İçerik</th>
                            <th>Tarih</th>
                            <th>Durum</th>
                            <th class="fit"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['items'] as $comment) : ?>
                            <tr>
                                <td class="pick">
                                    <label class="check">
                                        <input type="checkbox" name="ids[]" value="<?= (int) $comment->id ?>">
                                        <span class="sr-only">Seç</span>
                                    </label>
                                </td>
                                <td style="max-width:520px">
                                    <div class="cell-person mb-1">
                                        <?= ui_avatar($comment->authorName) ?>
                                        <div>
                                            <strong><?= esc_html($comment->authorName) ?></strong>
                                            <small>
                                                <?= esc_html($comment->authorEmail) ?>
                                                <?php if ($comment->byStaff) : ?>
                                                    · <span class="pill is-info no-dot">ekip</span>
                                                <?php endif; ?>
                                                <?php if ($comment->parentId > 0) : ?>
                                                    · yanıt
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <p class="small dim mb-0"><?= esc_html(Str::limit($comment->body, 260)) ?></p>
                                </td>
                                <td class="small">
                                    <?php if ($comment->entryTitle !== '') : ?>
                                        <a href="<?= esc_url($selfUrl(['icerik' => (string) $comment->entryId])) ?>"
                                           style="color:var(--ink-2)">
                                            <?= esc_html(Str::limit($comment->entryTitle, 40)) ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="muted">silinmiş</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small muted nowrap"><?= ui_time($comment->createdAt) ?></td>
                                <td><?= ui_status($comment->status) ?></td>
                                <td class="fit">
                                    <?php /* Satır işlemleri sayfanın altındaki forma gönderilir: iç içe form olmaz,
                                             kimlik formaction sorgu dizesiyle taşınır. */ ?>
                                    <div class="row-acts">
                                        <?php $rowAction = esc_url($selfUrl(['id' => (string) $comment->id])); ?>

                                        <?php if ($comment->status !== 'approved') : ?>
                                            <button class="icon-btn" type="submit" form="row-action"
                                                    formaction="<?= $rowAction ?>" name="islem" value="approved"
                                                    title="Onayla" aria-label="Onayla"><?= admin_icon('check', 15) ?></button>
                                        <?php else : ?>
                                            <button class="icon-btn" type="submit" form="row-action"
                                                    formaction="<?= $rowAction ?>" name="islem" value="pending"
                                                    title="Onayı kaldır" aria-label="Onayı kaldır"><?= admin_icon('x', 15) ?></button>
                                        <?php endif; ?>

                                        <a class="icon-btn" href="<?= esc_url($selfUrl(['yanit' => (string) $comment->id])) ?>"
                                           title="Yanıtla" aria-label="Yanıtla"><?= admin_icon('message', 15) ?></a>

                                        <?php if ($comment->status !== 'spam') : ?>
                                            <button class="icon-btn" type="submit" form="row-action"
                                                    formaction="<?= $rowAction ?>" name="islem" value="spam"
                                                    title="İstenmeyen olarak işaretle" aria-label="İstenmeyen"
                                                    <?= ui_confirm('Bu yorum istenmeyen olarak işaretlenecek.') ?>>
                                                <?= admin_icon('alert', 15) ?>
                                            </button>
                                        <?php endif; ?>

                                        <button class="icon-btn" type="submit" form="row-action"
                                                formaction="<?= $rowAction ?>" name="islem" value="delete"
                                                title="Sil" aria-label="Sil"
                                                <?= ui_confirm('Bu yorum ve yanıtları kalıcı olarak silinecek.') ?>>
                                            <?= admin_icon('trash', 15) ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?= ui_pagination($result['page'], $result['pages'],
            static fn(int $n): string => $selfUrl(['sayfa' => (string) $n]), $result['total']) ?>
    <?php else : ?>
        <?= ui_empty('message', 'Yorum yok',
            $status === 'all' ? 'İlk yorum geldiğinde burada görünecek.' : 'Bu bölümde yorum bulunmuyor.') ?>
    <?php endif; ?>
</section>

<?php /* Satır işlemlerinin gönderildiği form: tablonun dışında durur. */ ?>
<form id="row-action" method="post" action="<?= esc_url($selfUrl()) ?>" hidden>
    <?= hi_csrf_field() ?>
</form>

<?php
/* Yanıt formu — ayrı bir pencere yerine sayfa altında açılır, böylece
   yanıtlanan yorum bağlamı ekranda kalır. */
$replyId = (int) ($_GET['yanit'] ?? 0);
$reply   = $replyId > 0 ? $app->comments()->find($replyId) : null;
?>

<?php if ($reply !== null) : ?>
    <section class="panel mt-3">
        <header class="panel-head">
            <div>
                <h2 class="panel-title">Yanıtla: <?= esc_html($reply->authorName) ?></h2>
                <p class="panel-sub">Yanıtınız site yöneticisi adına, yorumun altında görünür.</p>
            </div>
        </header>
        <form method="post" action="<?= esc_url($selfUrl()) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="reply">
            <input type="hidden" name="id" value="<?= (int) $reply->id ?>">

            <div class="panel-body">
                <blockquote class="small dim" style="margin:0 0 14px;padding-left:12px;border-left:2px solid var(--line)">
                    <?= esc_html(Str::limit($reply->body, 320)) ?>
                </blockquote>

                <label class="sr-only" for="yanit">Yanıtınız</label>
                <textarea class="input" id="yanit" name="yanit" rows="4" required
                          placeholder="Yanıtınızı yazın…"></textarea>
            </div>

            <footer class="panel-foot">
                <a class="btn btn-sm" href="<?= esc_url($selfUrl()) ?>">Vazgeç</a>
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit">Yanıtı yayınla</button>
            </footer>
        </form>
    </section>
<?php endif; ?>

<?php admin_foot(); ?>
