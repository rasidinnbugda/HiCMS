<?php

declare(strict_types=1);

/**
 * HiAdmin — İçerik listesi
 *
 * Tek dosya tüm içerik türlerini yönetir: `?tur=post`, `?tur=page` ya da bir
 * eklentinin tanımladığı tür. Sütunlar ve filtreler tür tanımından üretilir.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('content.read');

$typeName = (string) ($_GET['tur'] ?? 'post');
$type     = $app->types()->get($typeName);

if ($type === null) {
    admin_redirect('content.php?tur=post', 'error', 'Bilinmeyen içerik türü.');
}

$listUrl = static fn(array $extra = []): string => 'content.php?' . http_build_query(array_filter(array_merge([
    'tur'      => $_GET['tur'] ?? 'post',
    'durum'    => $_GET['durum'] ?? '',
    'ara'      => $_GET['ara'] ?? '',
    'terim'    => $_GET['terim'] ?? '',
    'yazar'    => $_GET['yazar'] ?? '',
    'sayfa'    => $_GET['sayfa'] ?? '',
], $extra), static fn(mixed $v): bool => $v !== '' && $v !== null && $v !== 'all'));

/* -------------------------------------------------------------------------
 * Toplu işlemler
 * ---------------------------------------------------------------------- */

if ($app->request()->isPost()) {
    admin_verify($listUrl());

    $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));
    $action = (string) ($_POST['islem'] ?? '');

    if ($ids === []) {
        admin_redirect($listUrl(), 'warning', 'Hiç kayıt seçilmedi.');
    }

    if (in_array($action, ['published', 'draft', 'pending', 'private'], true)) {
        if ($action === 'published' && !$app->auth()->can('content.publish')) {
            admin_redirect($listUrl(), 'error', 'Yayınlama yetkiniz yok.');
        }

        $changed = $app->content()->bulkStatus($ids, $action);

        $app->audit()->record(
            action: 'content.bulk_status',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: $typeName,
            summary: Str::format('%d kayıt "%s" durumuna alındı', $changed, $action),
            ip: $app->request()->ip(),
        );

        admin_redirect($listUrl(), 'success', Str::format('%d kayıt güncellendi.', $changed));
    }

    if ($action === 'delete') {
        if (!$app->auth()->can('content.delete')) {
            admin_redirect($listUrl(), 'error', 'Silme yetkiniz yok.');
        }

        $deleted = $app->content()->bulkDelete($ids);

        $app->audit()->record(
            action: 'content.bulk_delete',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: $typeName,
            summary: Str::format('%d kayıt silindi', $deleted),
            ip: $app->request()->ip(),
        );

        admin_redirect($listUrl(), 'success', Str::format('%d kayıt silindi.', $deleted));
    }

    admin_redirect($listUrl(), 'warning', 'Tanınmayan işlem.');
}

/* -------------------------------------------------------------------------
 * Liste
 * ---------------------------------------------------------------------- */

$status  = (string) ($_GET['durum'] ?? 'all');
$search  = trim((string) ($_GET['ara'] ?? ''));
$term    = (string) ($_GET['terim'] ?? '');
$author  = (int) ($_GET['yazar'] ?? 0);
$pageNum = max(1, (int) ($_GET['sayfa'] ?? 1));

$counts = $app->content()->statusCounts($typeName);

$result = $app->content()->query([
    'type'     => $typeName,
    'status'   => $status,
    'search'   => $search,
    'term'     => $term,
    'author'   => $author,
    'page'     => $pageNum,
    'perPage'  => 20,
    'orderBy'  => $type->hierarchical ? 'title' : 'published_at',
    'orderDir' => $type->hierarchical ? 'asc' : 'desc',
]);

$taxonomies = $app->types()->taxonomiesFor($type);

$page = [
    'title'       => $type->plural,
    'slug'        => 'content:' . $typeName,
    'description' => $type->description,
    'actions'     => '<a class="btn btn-primary" href="' . esc_url($type->editUrl()) . '">'
        . admin_icon('plus', 15) . 'Yeni ' . esc_html(mb_strtolower($type->singular)) . '</a>',
];

admin_head($page);
?>

<section class="panel">
    <div class="filters">
        <?= ui_tabs([
            ['label' => 'Tümü', 'url' => $listUrl(['durum' => 'all', 'sayfa' => '']),
             'active' => $status === 'all', 'count' => $counts['all']],
            ['label' => 'Yayında', 'url' => $listUrl(['durum' => 'published', 'sayfa' => '']),
             'active' => $status === 'published', 'count' => $counts['published']],
            ['label' => 'Taslak', 'url' => $listUrl(['durum' => 'draft', 'sayfa' => '']),
             'active' => $status === 'draft', 'count' => $counts['draft']],
            ['label' => 'İncelemede', 'url' => $listUrl(['durum' => 'pending', 'sayfa' => '']),
             'active' => $status === 'pending', 'count' => $counts['pending']],
        ]) ?>

        <span class="spacer"></span>

        <form class="row" method="get" action="content.php">
            <input type="hidden" name="tur" value="<?= esc_attr($typeName) ?>">
            <input type="hidden" name="durum" value="<?= esc_attr($status) ?>">

            <?php foreach ($taxonomies as $taxonomy) : ?>
                <?php
                $options = ['' => $taxonomy->plural . ': tümü'];

                foreach ($app->terms()->forTaxonomy($taxonomy->name, false) as $option) {
                    $options[$option->slug] = $option->name;
                }
                ?>
                <label class="sr-only" for="f-<?= esc_attr($taxonomy->name) ?>"><?= esc_html($taxonomy->plural) ?></label>
                <?= ui_select('terim', $options, $term, ['id' => 'f-' . $taxonomy->name, 'class' => 'input select w-auto']) ?>
                <?php break; /* Tek taksonomi süzgeci yeterli; fazlası çubuğu kalabalıklaştırır. */ ?>
            <?php endforeach; ?>

            <label class="sr-only" for="f-ara">Ara</label>
            <input class="input" type="search" id="f-ara" name="ara" style="width:180px"
                   value="<?= esc_attr($search) ?>" placeholder="Başlıkta ara">

            <button class="btn" type="submit"><?= admin_icon('filter', 15) ?>Süz</button>

            <?php if ($search !== '' || $term !== '' || $status !== 'all') : ?>
                <a class="btn btn-ghost" href="content.php?tur=<?= esc_attr($typeName) ?>">Sıfırla</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($result['items'] !== []) : ?>
        <form method="post" action="<?= esc_url($listUrl()) ?>">
            <?= hi_csrf_field() ?>

            <div class="bulk" data-bulk="content-table">
                <span><strong data-bulk-count>0</strong> seçildi</span>

                <?php if ($app->auth()->can('content.publish')) : ?>
                    <button class="btn btn-sm is-off" type="submit" name="islem" value="published" data-bulk-action>
                        Yayınla
                    </button>
                <?php endif; ?>

                <button class="btn btn-sm is-off" type="submit" name="islem" value="draft" data-bulk-action>
                    Taslağa al
                </button>

                <?php if ($app->auth()->can('content.delete')) : ?>
                    <button class="btn btn-sm btn-danger is-off" type="submit" name="islem" value="delete"
                            data-bulk-action <?= ui_confirm('Seçili kayıtlar kalıcı olarak silinecek. Devam edilsin mi?') ?>>
                        Sil
                    </button>
                <?php endif; ?>

                <span class="spacer"></span>
                <span class="muted"><?= esc_html(Str::format('%d kayıt', $result['total'])) ?></span>
            </div>

            <div class="table-wrap">
                <table class="data" id="content-table">
                    <thead>
                        <tr>
                            <th class="pick">
                                <label class="check">
                                    <input type="checkbox" data-check-all="content-table">
                                    <span class="sr-only">Tümünü seç</span>
                                </label>
                            </th>
                            <th>Başlık</th>
                            <th>Yazar</th>
                            <?php if ($taxonomies !== []) : ?>
                                <th><?= esc_html($taxonomies[0]->singular) ?></th>
                            <?php endif; ?>
                            <?php if ($type->hasComments) : ?>
                                <th class="num">Yorum</th>
                            <?php endif; ?>
                            <th class="num">Okunma</th>
                            <th>Tarih</th>
                            <th>Durum</th>
                            <th class="fit"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['items'] as $entry) : ?>
                            <?php $canEdit = $app->auth()->canEdit($entry->authorId); ?>
                            <tr>
                                <td class="pick">
                                    <label class="check">
                                        <input type="checkbox" name="ids[]" value="<?= (int) $entry->id ?>">
                                        <span class="sr-only"><?= esc_html($entry->title) ?> seç</span>
                                    </label>
                                </td>
                                <td>
                                    <?php if ($canEdit) : ?>
                                        <a class="cell-title" href="content-edit.php?id=<?= (int) $entry->id ?>">
                                            <?= esc_html($entry->title) ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="cell-title"><?= esc_html($entry->title) ?></span>
                                    <?php endif; ?>
                                    <span class="cell-sub mono">
                                        /<?= esc_html($type->route !== '' ? $type->route . '/' : '') ?><?= esc_html($entry->slug) ?>
                                        <?php if ($entry->featured) : ?>
                                            · <span class="pill is-info no-dot">öne çıkan</span>
                                        <?php endif; ?>
                                        <?php if ($entry->isScheduled()) : ?>
                                            · <span class="pill is-warn no-dot">zamanlanmış</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="cell-person">
                                        <?= ui_avatar((string) ($entry->author?->displayName ?? '?')) ?>
                                        <span class="small"><?= esc_html($entry->author?->displayName ?? '—') ?></span>
                                    </div>
                                </td>
                                <?php if ($taxonomies !== []) : ?>
                                    <td class="small">
                                        <?php $primary = $entry->primaryTerm($taxonomies[0]->name); ?>
                                        <?php if ($primary !== null) : ?>
                                            <a href="<?= esc_url($listUrl(['terim' => $primary->slug])) ?>"
                                               style="color:var(--ink-2)">
                                                <span class="dot" style="--term-color: <?= esc_attr($primary->color ?: 'var(--accent)') ?>"></span>
                                                <?= esc_html($primary->name) ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($type->hasComments) : ?>
                                    <td class="num"><?= (int) $entry->commentCount ?></td>
                                <?php endif; ?>
                                <td class="num"><?= esc_html(Str::number($entry->views)) ?></td>
                                <td class="small muted nowrap"><?= ui_time($entry->publishedAt ?? $entry->createdAt) ?></td>
                                <td><?= ui_status($entry->status) ?></td>
                                <td class="fit">
                                    <div class="row-acts">
                                        <?php if ($canEdit) : ?>
                                            <a class="icon-btn" href="content-edit.php?id=<?= (int) $entry->id ?>"
                                               title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                        <?php endif; ?>
                                        <a class="icon-btn" href="<?= esc_url($app->links()->forEntry($entry)) ?>"
                                           target="_blank" rel="noopener" title="Görüntüle"
                                           aria-label="Görüntüle"><?= admin_icon('eye', 15) ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?= ui_pagination(
            $result['page'],
            $result['pages'],
            static fn(int $n): string => $listUrl(['sayfa' => (string) $n]),
            $result['total']
        ) ?>
    <?php else : ?>
        <?= ui_empty(
            $type->icon,
            $search !== '' || $term !== '' ? 'Eşleşen kayıt yok' : 'Henüz içerik yok',
            $search !== '' || $term !== ''
                ? 'Bu süzgeçlere uyan kayıt bulunamadı. Süzgeçleri sıfırlayıp yeniden deneyin.'
                : Str::format('İlk %s kaydınızı oluşturun.', mb_strtolower($type->singular)),
            ($search !== '' || $term !== ''
                ? '<a class="btn btn-sm" href="content.php?tur=' . esc_attr($typeName) . '">Süzgeçleri sıfırla</a>'
                : '')
            . '<a class="btn btn-sm btn-primary" href="' . esc_url($type->editUrl()) . '">Yeni '
            . esc_html(mb_strtolower($type->singular)) . '</a>'
        ) ?>
    <?php endif; ?>
</section>

<?php admin_foot(); ?>
