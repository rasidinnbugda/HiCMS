<?php

declare(strict_types=1);

/**
 * HiAdmin — Medya kitaplığı
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('media.upload');

$selfUrl = static fn(array $extra = []): string => 'media.php' . (($q = http_build_query(array_filter(array_merge([
    'tur'   => $_GET['tur'] ?? '',
    'ara'   => $_GET['ara'] ?? '',
    'sayfa' => $_GET['sayfa'] ?? '',
], $extra)))) !== '' ? '?' . $q : '');

/* -------------------------------------------------------------------------
 * Yükleme / güncelleme / silme
 * ---------------------------------------------------------------------- */

if ($app->request()->isPost()) {
    admin_verify($selfUrl());

    $action = (string) ($_POST['islem'] ?? 'upload');

    if ($action === 'upload') {
        $files    = $_FILES['dosyalar'] ?? null;
        $uploaded = 0;
        $errors   = [];

        if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);

            for ($i = 0; $i < $count; $i++) {
                if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $result = $app->uploader()->store([
                    'name'     => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ], $app->auth()->id());

                if ($result['ok']) {
                    $uploaded++;
                } else {
                    $errors[] = $files['name'][$i] . ': ' . $result['error'];
                }
            }
        }

        if ($uploaded > 0) {
            $app->audit()->record(
                action: 'media.upload',
                userId: $app->auth()->id(),
                actor: (string) $app->auth()->user()?->displayName,
                subjectType: 'media',
                summary: Str::format('%d dosya yüklendi', $uploaded),
                ip: $app->request()->ip(),
            );

            admin_flash('success', Str::format('%d dosya yüklendi.', $uploaded));
        }

        foreach ($errors as $error) {
            admin_flash('error', $error);
        }

        if ($uploaded === 0 && $errors === []) {
            admin_flash('warning', 'Dosya seçilmedi.');
        }

        admin_redirect($selfUrl());
    }

    if ($action === 'update') {
        $item = $app->mediaRepo()->find((int) ($_POST['id'] ?? 0));

        if ($item === null) {
            admin_redirect($selfUrl(), 'error', 'Dosya bulunamadı.');
        }

        $item->title = trim((string) ($_POST['baslik'] ?? ''));
        $item->alt   = trim((string) ($_POST['alt'] ?? ''));

        $app->mediaRepo()->update($item);

        admin_redirect($selfUrl(), 'success', 'Dosya bilgileri güncellendi.');
    }

    if ($action === 'delete') {
        $item = $app->mediaRepo()->find((int) ($_POST['id'] ?? 0));

        if ($item === null) {
            admin_redirect($selfUrl(), 'error', 'Dosya bulunamadı.');
        }

        if ($item->authorId !== $app->auth()->id() && !$app->auth()->can('media.delete_others')) {
            admin_redirect($selfUrl(), 'error', 'Başkalarının dosyalarını silme yetkiniz yok.');
        }

        $app->uploader()->deleteFiles($item);
        $app->mediaRepo()->delete($item->id);

        $app->audit()->record(
            action: 'media.delete',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: 'media',
            subjectId: $item->id,
            summary: 'Dosya silindi: ' . $item->filename,
            ip: $app->request()->ip(),
        );

        admin_redirect($selfUrl(), 'success', 'Dosya silindi.');
    }
}

/* -------------------------------------------------------------------------
 * Liste
 * ---------------------------------------------------------------------- */

$filter  = (string) ($_GET['tur'] ?? '');
$search  = trim((string) ($_GET['ara'] ?? ''));
$pageNum = max(1, (int) ($_GET['sayfa'] ?? 1));
$openId  = (int) ($_GET['dosya'] ?? 0);

$result = $app->mediaRepo()->paginate([
    'type'    => $filter !== '' ? $filter . '/' : '',
    'search'  => $search,
    'page'    => $pageNum,
    'perPage' => 36,
]);

$stats    = $app->mediaRepo()->stats();
$oversize = $app->mediaRepo()->oversized();
$open     = $openId > 0 ? $app->mediaRepo()->find($openId) : null;

$page = [
    'title'       => 'Medya',
    'slug'        => 'media',
    'description' => 'Görseller ve dosyalar. Sürükleyip bırakarak yükleyebilirsiniz.',
];

admin_head($page);

echo ui_metrics([
    ['label' => 'Görsel', 'value' => Str::number($stats['images'])],
    ['label' => 'Diğer dosya', 'value' => Str::number($stats['documents'])],
    ['label' => 'Kullanılan alan', 'value' => Str::bytes($stats['bytes'])],
    [
        'label' => 'Büyük görsel',
        'value' => Str::number(count($oversize)),
        'note'  => count($oversize) > 0 ? '500 KB üzeri' : 'sorun yok',
    ],
]);

if ($oversize !== [] && !$app->plugins()->isActive('hi-media')) {
    echo '<div class="mt-3">' . ui_notice(
        'warning',
        Str::format('%d görsel 500 KB üzerinde. HiMedia eklentisi bunları otomatik küçültüp WebP üretir.', count($oversize)),
        '<a class="btn btn-sm" href="plugins.php">Eklentilere git</a>'
    ) . '</div>';
}
?>

<section class="panel mt-3">
    <div class="filters">
        <?= ui_tabs([
            ['label' => 'Tümü', 'url' => $selfUrl(['tur' => '', 'sayfa' => '']), 'active' => $filter === ''],
            ['label' => 'Görseller', 'url' => $selfUrl(['tur' => 'image', 'sayfa' => '']), 'active' => $filter === 'image'],
            ['label' => 'Belgeler', 'url' => $selfUrl(['tur' => 'application', 'sayfa' => '']), 'active' => $filter === 'application'],
        ]) ?>

        <span class="spacer"></span>

        <form class="row" method="get" action="media.php">
            <input type="hidden" name="tur" value="<?= esc_attr($filter) ?>">
            <label class="sr-only" for="m-ara">Ara</label>
            <input class="input" type="search" id="m-ara" name="ara" style="width:180px"
                   value="<?= esc_attr($search) ?>" placeholder="Dosya adında ara">
            <button class="btn" type="submit"><?= admin_icon('search', 15) ?></button>
        </form>
    </div>

    <div class="panel-body">
        <form method="post" action="<?= esc_url($selfUrl()) ?>" enctype="multipart/form-data">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="upload">

            <label class="drop">
                <span class="ico-wrap"><?= admin_icon('upload', 20) ?></span>
                <strong>Dosyaları buraya bırakın</strong>
                <small>ya da seçmek için tıklayın · JPG, PNG, WEBP, AVIF, PDF · en fazla
                    <?= esc_html(Str::bytes((int) $app->options()->get('upload_max_bytes', 16777216))) ?></small>
                <input type="file" name="dosyalar[]" multiple accept="image/*,application/pdf,.zip,.csv,.txt">
            </label>
        </form>

        <?php if ($result['items'] !== []) : ?>
            <div class="media-grid mt-3">
                <?php foreach ($result['items'] as $item) : ?>
                    <a class="media-item" href="<?= esc_url($selfUrl(['dosya' => (string) $item->id])) ?>">
                        <span class="media-thumb">
                            <?php if ($item->isImage()) : ?>
                                <img src="<?= esc_url($app->urls()->uploads($item->path)) ?>"
                                     alt="<?= esc_attr($item->alt) ?>" loading="lazy">
                            <?php else : ?>
                                <?= admin_icon('file', 24) ?>
                            <?php endif; ?>
                            <span class="media-ext"><?= esc_html($item->extension()) ?></span>
                        </span>
                        <span class="media-meta">
                            <strong><?= esc_html($item->title !== '' ? $item->title : $item->filename) ?></strong>
                            <small>
                                <?php if ($item->width > 0) : ?>
                                    <?= (int) $item->width ?>×<?= (int) $item->height ?> ·
                                <?php endif; ?>
                                <?= esc_html(Str::bytes($item->size)) ?>
                            </small>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?= ui_empty('image', 'Dosya yok', 'Yukarıdaki alandan ilk dosyanızı yükleyin.') ?>
        <?php endif; ?>
    </div>

    <?= ui_pagination($result['page'], $result['pages'],
        static fn(int $n): string => $selfUrl(['sayfa' => (string) $n]), $result['total']) ?>
</section>

<?php if ($open !== null) : ?>
    <div class="modal" id="file-modal" role="dialog" aria-modal="true" aria-labelledby="file-modal-title">
        <div class="modal-box is-wide">
            <header class="modal-head">
                <h2 id="file-modal-title">Dosya bilgileri</h2>
                <a class="icon-btn" href="<?= esc_url($selfUrl()) ?>" aria-label="Kapat"><?= admin_icon('x', 16) ?></a>
            </header>

            <div class="modal-body">
                <div class="grid grid-2">
                    <div>
                        <?php if ($open->isImage()) : ?>
                            <img src="<?= esc_url($app->urls()->uploads($open->path)) ?>"
                                 alt="<?= esc_attr($open->alt) ?>"
                                 style="width:100%;border:1px solid var(--line);border-radius:var(--r-sm)">
                        <?php else : ?>
                            <div class="empty"><?= admin_icon('file', 28) ?></div>
                        <?php endif; ?>

                        <ul class="kv mt-3">
                            <li><span class="k">Dosya</span><span class="v mono"><?= esc_html($open->filename) ?></span></li>
                            <li><span class="k">Tür</span><span class="v"><?= esc_html($open->mime) ?></span></li>
                            <li><span class="k">Boyut</span><span class="v"><?= esc_html(Str::bytes($open->size)) ?></span></li>
                            <?php if ($open->width > 0) : ?>
                                <li><span class="k">Ölçü</span><span class="v"><?= (int) $open->width ?>×<?= (int) $open->height ?></span></li>
                            <?php endif; ?>
                            <li><span class="k">Türev</span><span class="v"><?= count($open->sizes) ?></span></li>
                            <li><span class="k">Kimlik</span><span class="v mono">#<?= (int) $open->id ?></span></li>
                        </ul>
                    </div>

                    <div>
                        <form method="post" action="<?= esc_url($selfUrl()) ?>">
                            <?= hi_csrf_field() ?>
                            <input type="hidden" name="islem" value="update">
                            <input type="hidden" name="id" value="<?= (int) $open->id ?>">

                            <?= ui_field('Başlık', ui_input('baslik', $open->title, ['id' => 'f-baslik']), '', 'f-baslik') ?>

                            <?= ui_field(
                                'Alternatif metin',
                                '<textarea class="input" id="f-alt" name="alt" rows="3">' . esc_html($open->alt) . '</textarea>',
                                'Ekran okuyucular ve arama motorları için. Süs amaçlı görsellerde boş bırakın.',
                                'f-alt'
                            ) ?>

                            <?= ui_field(
                                'Adres',
                                ui_input('url', $app->urls()->uploads($open->path), [
                                    'id' => 'f-url', 'readonly' => true, 'class' => 'input mono',
                                ]),
                                '',
                                'f-url'
                            ) ?>

                            <div class="row mt-3">
                                <button class="btn btn-primary btn-sm" type="submit">Kaydet</button>
                                <a class="btn btn-sm" href="<?= esc_url($selfUrl()) ?>">Kapat</a>
                            </div>
                        </form>

                        <form method="post" action="<?= esc_url($selfUrl()) ?>" class="mt-3">
                            <?= hi_csrf_field() ?>
                            <input type="hidden" name="islem" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $open->id ?>">
                            <button class="btn btn-sm btn-danger" type="submit"
                                <?= ui_confirm('Dosya ve türevleri diskten silinecek. Devam edilsin mi?') ?>>
                                <?= admin_icon('trash', 14) ?>Dosyayı sil
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php admin_foot(); ?>
