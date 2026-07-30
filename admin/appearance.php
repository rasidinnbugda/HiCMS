<?php

declare(strict_types=1);

/**
 * HiAdmin — Temalar
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Kernel;
use HiCMS\Support\Fs;
use HiCMS\Support\Str;

admin_require('appearance.manage');

if ($app->request()->isPost()) {
    admin_verify('appearance.php');

    $action = (string) ($_POST['islem'] ?? '');
    $slug   = (string) ($_POST['tema'] ?? '');

    if ($action === 'activate') {
        $result = $app->themes()->activate($slug);

        if ($result['ok']) {
            $app->audit()->record(
                action: 'theme.activate',
                userId: $app->auth()->id(),
                actor: (string) $app->auth()->user()?->displayName,
                subjectType: 'theme',
                summary: 'Tema etkinleştirildi: ' . $slug,
                ip: $app->request()->ip(),
            );
        }

        admin_redirect('appearance.php', $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Tema etkinleştirildi.' : $result['error']);
    }

    if ($action === 'delete') {
        $can = $app->themes()->canDelete($slug);

        if (!$can['ok']) {
            admin_redirect('appearance.php', 'error', $can['error']);
        }

        $directory = $app->themes()->themesDir() . '/' . basename($slug);

        if (!Fs::deleteDir($directory)) {
            admin_redirect('appearance.php', 'error', 'Tema klasörü silinemedi. Dosya izinlerini kontrol edin.');
        }

        $app->themes()->flush();

        admin_redirect('appearance.php', 'success', 'Tema silindi.');
    }

    if ($action === 'upload') {
        $result = $app->packages()->installFromUpload($_FILES['paket'] ?? [], 'theme');

        if (!$result['ok']) {
            admin_redirect('appearance.php', 'error', $result['error']);
        }

        $app->themes()->flush();

        admin_redirect('appearance.php', 'success', $result['updated']
            ? Str::format('"%s" %s sürümüne güncellendi.', $result['slug'], $result['version'])
            : Str::format('"%s" kuruldu (%s).', $result['slug'], $result['version']));
    }

    if ($action === 'update') {
        $manifest = $app->themes()->available()[$slug] ?? null;

        if ($manifest === null || !$manifest->canSelfUpdate()) {
            admin_redirect('appearance.php', 'error', 'Bu tema kendi deposunu bildirmemiş.');
        }

        $check = $app->updateChecker()->check($manifest->repository, $manifest->version, true);

        if (!$check['available']) {
            admin_redirect('appearance.php', 'warning',
                $check['error'] !== '' ? $check['error'] : 'Tema güncel.');
        }

        $result = $app->packages()->installFromUrl($check['zip'], 'theme');

        $app->themes()->flush();

        admin_redirect('appearance.php', $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? Str::format('"%s" %s sürümüne güncellendi.', $result['slug'], $result['version'])
                : $result['error']);
    }
}

$themes = $app->themes()->available();
$active = $app->themes()->activeSlug();

$page = [
    'title'       => 'Temalar',
    'slug'        => 'appearance',
    'description' => 'Etkin tema, şablon hiyerarşisine göre sayfaları oluşturur.',
    'actions'     => '<button class="btn btn-primary" type="button" data-modal="#theme-upload">'
        . admin_icon('upload', 15) . 'Tema yükle</button>',
];

admin_head($page);
?>

<div class="theme-grid">
    <?php foreach ($themes as $slug => $theme) : ?>
        <?php
        $isActive = $slug === $active;
        $update   = $theme->canSelfUpdate()
            ? $app->updateChecker()->check($theme->repository, $theme->version)
            : ['available' => false, 'version' => '', 'error' => ''];
        ?>
        <article class="theme-card<?= $isActive ? ' is-active' : '' ?>">
            <div class="theme-shot">
                <?php if ($theme->hasScreenshot()) : ?>
                    <img src="<?= esc_url($app->urls()->theme($slug, $theme->screenshot)) ?>"
                         alt="<?= esc_attr($theme->name . ' önizlemesi') ?>" loading="lazy">
                <?php else : ?>
                    <?= admin_icon('palette', 28) ?>
                <?php endif; ?>

                <?php if ($isActive) : ?>
                    <span class="theme-flag"><?= admin_icon('check', 12) ?>Etkin</span>
                <?php endif; ?>
            </div>

            <div class="theme-info">
                <h3>
                    <?= esc_html($theme->name) ?>
                    <span>v<?= esc_html($theme->version) ?></span>
                </h3>

                <?php if (!$theme->valid) : ?>
                    <p style="color:var(--err)"><?= esc_html($theme->error) ?></p>
                <?php else : ?>
                    <p><?= esc_html(Str::limit($theme->description, 150)) ?></p>
                <?php endif; ?>

                <?php if ($theme->tags !== []) : ?>
                    <div class="theme-tags">
                        <?php foreach (array_slice($theme->tags, 0, 5) as $tag) : ?>
                            <span><?= esc_html($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($update['available']) : ?>
                    <div class="mt-2">
                        <span class="pill is-warn">v<?= esc_html($update['version']) ?> hazır</span>
                    </div>
                <?php endif; ?>

                <div class="theme-foot">
                    <span class="muted small"><?= esc_html($theme->author !== '' ? $theme->author : 'Bilinmeyen') ?></span>
                    <span class="spacer"></span>

                    <?php if ($update['available']) : ?>
                        <button class="btn btn-sm btn-primary" type="submit" form="theme-form"
                                formaction="appearance.php" name="islem" value="update"
                                onclick="document.getElementById('theme-slug').value='<?= esc_attr($slug) ?>'">
                            Güncelle
                        </button>
                    <?php elseif (!$isActive && $theme->valid) : ?>
                        <button class="btn btn-sm btn-primary" type="submit" form="theme-form"
                                name="islem" value="activate"
                                onclick="document.getElementById('theme-slug').value='<?= esc_attr($slug) ?>'">
                            Etkinleştir
                        </button>
                        <button class="btn btn-sm btn-danger" type="submit" form="theme-form"
                                name="islem" value="delete"
                                onclick="document.getElementById('theme-slug').value='<?= esc_attr($slug) ?>'"
                            <?= ui_confirm(Str::format('"%s" teması klasörüyle birlikte silinecek. Devam edilsin mi?', $theme->name)) ?>>
                            <?= admin_icon('trash', 14) ?>
                        </button>
                    <?php elseif ($isActive) : ?>
                        <a class="btn btn-sm" href="menus.php">Özelleştir</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <article class="theme-card">
        <button class="theme-shot" type="button" data-modal="#theme-upload"
                style="width:100%;border:0;cursor:pointer;background:var(--surface-2)">
            <span style="display:grid;place-items:center;gap:6px;color:var(--ink-3)">
                <?= admin_icon('plus', 24) ?>
                <span class="small">Tema yükle</span>
            </span>
        </button>
        <div class="theme-info">
            <h3>Yeni tema ekle</h3>
            <p>ZIP yükleyin veya <code>themes/</code> klasörüne kopyalayın. HiCMS
                <code>hicms.json</code> dosyasını görünce temayı tanır.</p>
        </div>
    </article>
</div>

<form id="theme-form" method="post" action="appearance.php" hidden>
    <?= hi_csrf_field() ?>
    <input type="hidden" name="tema" id="theme-slug" value="">
</form>

<section class="panel mt-3">
    <header class="panel-head">
        <div>
            <h2 class="panel-title">Etkin temanın sağladıkları</h2>
            <p class="panel-sub">Tema <code>functions.php</code> içinde bildirir</p>
        </div>
    </header>
    <div class="panel-body">
        <div class="grid grid-3">
            <div>
                <h3 class="h3 mb-2">Menü konumları</h3>
                <ul class="kv">
                    <?php foreach ($app->menus()->locations() as $location => $label) : ?>
                        <li><span class="k mono"><?= esc_html($location) ?></span><span class="v"><?= esc_html($label) ?></span></li>
                    <?php endforeach; ?>
                    <?php if ($app->menus()->locations() === []) : ?>
                        <li><span class="k">Tanımlı konum yok</span></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div>
                <h3 class="h3 mb-2">Bileşen alanları</h3>
                <ul class="kv">
                    <?php foreach ($app->widgets()->areas() as $area) : ?>
                        <li><span class="k mono"><?= esc_html($area['id']) ?></span><span class="v"><?= esc_html($area['name']) ?></span></li>
                    <?php endforeach; ?>
                    <?php if ($app->widgets()->areas() === []) : ?>
                        <li><span class="k">Tanımlı alan yok</span></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div>
                <h3 class="h3 mb-2">Tema destekleri</h3>
                <ul class="kv">
                    <?php foreach ($app->themes()->allSupports() as $feature => $value) : ?>
                        <li><span class="k mono"><?= esc_html($feature) ?></span>
                            <span class="v"><?= admin_icon('check', 13) ?></span></li>
                    <?php endforeach; ?>
                    <?php if ($app->themes()->allSupports() === []) : ?>
                        <li><span class="k">Bildirilmemiş</span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<div class="modal" id="theme-upload" hidden role="dialog" aria-modal="true" aria-labelledby="theme-upload-title">
    <div class="modal-box">
        <header class="modal-head">
            <h2 id="theme-upload-title">Tema yükle</h2>
            <button class="icon-btn" type="button" data-close aria-label="Kapat"><?= admin_icon('x', 16) ?></button>
        </header>
        <form method="post" action="appearance.php" enctype="multipart/form-data">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="upload">

            <div class="modal-body">
                <label class="drop">
                    <span><?= admin_icon('upload', 20) ?></span>
                    <strong>Tema ZIP dosyasını seçin</strong>
                    <small>ZIP içinde <code>hicms.json</code> ve <code>index.php</code> bulunmalı</small>
                    <input type="file" name="paket" accept=".zip" required>
                </label>
                <p class="hint">Aynı kısa ada sahip bir tema varsa güncelleme olarak kurulur; kurulum başarısız olursa eski sürüm geri getirilir.</p>
            </div>

            <footer class="modal-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm" type="button" data-close>Vazgeç</button>
                <button class="btn btn-sm btn-primary" type="submit">Yükle ve kur</button>
            </footer>
        </form>
    </div>
</div>

<?php admin_foot(); ?>
