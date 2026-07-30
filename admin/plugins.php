<?php

declare(strict_types=1);

/**
 * HiAdmin — Eklentiler
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('plugins.manage');

if ($app->request()->isPost()) {
    admin_verify('plugins.php');

    $action = (string) ($_POST['islem'] ?? '');
    $slug   = (string) ($_POST['eklenti'] ?? '');

    if ($action === 'activate') {
        $result = $app->plugins()->activate($slug);

        if ($result['ok']) {
            $app->audit()->record(
                action: 'plugin.activate',
                userId: $app->auth()->id(),
                actor: (string) $app->auth()->user()?->displayName,
                subjectType: 'plugin',
                summary: 'Etkinleştirildi: ' . $slug,
                ip: $app->request()->ip(),
            );
        }

        admin_redirect('plugins.php', $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? Str::format('"%s" etkinleştirildi.', $slug)
                    . ($result['migrations'] !== [] ? ' Tabloları kuruldu.' : '')
                : $result['error']);
    }

    if ($action === 'deactivate') {
        $app->plugins()->deactivate($slug);

        $app->audit()->record(
            action: 'plugin.deactivate',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: 'plugin',
            summary: 'Devre dışı bırakıldı: ' . $slug,
            ip: $app->request()->ip(),
        );

        admin_redirect('plugins.php', 'success', Str::format('"%s" devre dışı bırakıldı.', $slug));
    }

    if ($action === 'delete') {
        $result = $app->plugins()->delete($slug);

        admin_redirect('plugins.php', $result['ok'] ? 'success' : 'error',
            $result['ok'] ? Str::format('"%s" kaldırıldı.', $slug) : $result['error']);
    }

    if ($action === 'upload') {
        $result = $app->packages()->installFromUpload($_FILES['paket'] ?? [], 'plugin');

        $app->plugins()->flush();

        admin_redirect('plugins.php', $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? Str::format('"%s" %s kuruldu. Etkinleştirmeyi unutmayın.', $result['slug'], $result['version'])
                : $result['error']);
    }

    if ($action === 'update') {
        $manifest = $app->plugins()->get($slug);

        if ($manifest === null || !$manifest->canSelfUpdate()) {
            admin_redirect('plugins.php', 'error', 'Bu eklenti kendi deposunu bildirmemiş.');
        }

        $check = $app->updateChecker()->check($manifest->repository, $manifest->version, true);

        if (!$check['available']) {
            admin_redirect('plugins.php', 'warning', $check['error'] !== '' ? $check['error'] : 'Eklenti güncel.');
        }

        $result = $app->packages()->installFromUrl($check['zip'], 'plugin');

        $app->plugins()->flush();

        admin_redirect('plugins.php', $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? Str::format('"%s" %s sürümüne güncellendi.', $result['slug'], $result['version'])
                : $result['error']);
    }
}

$plugins = $app->plugins()->available();
$activeSlugs = $app->plugins()->activeSlugs();

$page = [
    'title'       => 'Eklentiler',
    'slug'        => 'plugins',
    'description' => 'Eklentiler çekirdeğe dokunmadan özellik ekler. Her biri tek başına çalışır.',
    'actions'     => '<button class="btn btn-primary" type="button" data-modal="#plugin-upload">'
        . admin_icon('upload', 15) . 'Eklenti yükle</button>',
];

admin_head($page);

echo ui_metrics([
    ['label' => 'Yüklü', 'value' => Str::number(count($plugins))],
    ['label' => 'Etkin', 'value' => Str::number(count($activeSlugs))],
    ['label' => 'Kanca noktası', 'value' => Str::number(
        count($app->events()->registry()['events']) + count($app->events()->registry()['hooks'])
    )],
    ['label' => 'İçerik türü', 'value' => Str::number(count($app->types()->all()))],
]);
?>

<section class="panel mt-3">
    <header class="panel-head">
        <div>
            <h2 class="panel-title">Yüklü eklentiler</h2>
            <p class="panel-sub">Etkin eklentiler her istekte temadan önce yüklenir</p>
        </div>
    </header>

    <?php if ($plugins !== []) : ?>
        <ul class="ext-list">
            <?php foreach ($plugins as $slug => $plugin) : ?>
                <?php
                $isOn   = in_array($slug, $activeSlugs, true);
                $update = $plugin->canSelfUpdate()
                    ? $app->updateChecker()->check($plugin->repository, $plugin->version)
                    : ['available' => false, 'version' => ''];
                ?>
                <li class="ext-row<?= $isOn ? ' is-on' : '' ?>">
                    <span class="ext-ico"><?= admin_icon('plug', 18) ?></span>

                    <div class="ext-body">
                        <h3>
                            <?= esc_html($plugin->name) ?>
                            <?= $isOn ? ui_status('active') : ui_status('inactive') ?>
                            <?php if ($update['available']) : ?>
                                <span class="pill is-warn">v<?= esc_html($update['version']) ?> hazır</span>
                            <?php endif; ?>
                        </h3>

                        <?php if (!$plugin->valid) : ?>
                            <p style="color:var(--err)"><?= esc_html($plugin->error) ?></p>
                        <?php else : ?>
                            <p><?= esc_html($plugin->description) ?></p>
                        <?php endif; ?>

                        <div class="ext-meta">
                            <span>Sürüm <?= esc_html($plugin->version) ?></span>
                            <?php if ($plugin->author !== '') : ?>
                                <span><?= esc_html($plugin->author) ?></span>
                            <?php endif; ?>
                            <span class="mono">plugins/<?= esc_html($slug) ?>/</span>
                            <?php if ($plugin->repository !== '') : ?>
                                <span class="mono"><?= esc_html($plugin->repository) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ext-acts">
                        <?php if ($update['available']) : ?>
                            <button class="btn btn-sm btn-primary" type="submit" form="plugin-form"
                                    name="islem" value="update"
                                    onclick="document.getElementById('plugin-slug').value='<?= esc_attr($slug) ?>'">
                                Güncelle
                            </button>
                        <?php endif; ?>

                        <?php if ($isOn) : ?>
                            <button class="btn btn-sm" type="submit" form="plugin-form"
                                    name="islem" value="deactivate"
                                    onclick="document.getElementById('plugin-slug').value='<?= esc_attr($slug) ?>'">
                                Devre dışı bırak
                            </button>
                        <?php elseif ($plugin->valid) : ?>
                            <button class="btn btn-sm btn-primary" type="submit" form="plugin-form"
                                    name="islem" value="activate"
                                    onclick="document.getElementById('plugin-slug').value='<?= esc_attr($slug) ?>'">
                                Etkinleştir
                            </button>
                        <?php endif; ?>

                        <?php if (!$isOn) : ?>
                            <button class="icon-btn" type="submit" form="plugin-form"
                                    name="islem" value="delete" title="Kaldır" aria-label="Kaldır"
                                    onclick="document.getElementById('plugin-slug').value='<?= esc_attr($slug) ?>'"
                                <?= ui_confirm(Str::format('"%s" klasörü ve tabloları silinecek. Devam edilsin mi?', $plugin->name)) ?>>
                                <?= admin_icon('trash', 15) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <?= ui_empty('plug', 'Eklenti yok',
            'plugins/ klasörüne bir eklenti kopyalayın ya da ZIP yükleyin.',
            '<button class="btn btn-sm btn-primary" type="button" data-modal="#plugin-upload">Eklenti yükle</button>') ?>
    <?php endif; ?>
</section>

<form id="plugin-form" method="post" action="plugins.php" hidden>
    <?= hi_csrf_field() ?>
    <input type="hidden" name="eklenti" id="plugin-slug" value="">
</form>

<section class="panel">
    <header class="panel-head">
        <div>
            <h2 class="panel-title">Eklenti yazmak</h2>
            <p class="panel-sub">En küçük eklenti iki dosyadan oluşur</p>
        </div>
    </header>
    <div class="panel-body">
        <p class="small dim mb-2">
            <code>plugins/eklentim/hicms.json</code> künyeyi, <code>plugin.php</code> kodu taşır.
            Tipli olaylara bağlanmak IDE'de otomatik tamamlama sağlar:
        </p>
<pre><code>use HiCMS\Events\Content\Saved;

hi_listen(Saved::class, function (Saved $event): void {
    if ($event-&gt;justPublished()) {
        // yayınlandı: önbelleği temizle, bildirim gönder…
    }
});</code></pre>
        <p class="small dim mt-2 mb-0">
            İnce taneli arayüz noktaları için adlandırılmış kancalar kullanılır:
            <code>hi_on('admin.notices', …)</code>, <code>hi_add_filter('content.body', …)</code>.
        </p>
    </div>
</section>

<div class="modal" id="plugin-upload" hidden role="dialog" aria-modal="true" aria-labelledby="plugin-upload-title">
    <div class="modal-box">
        <header class="modal-head">
            <h2 id="plugin-upload-title">Eklenti yükle</h2>
            <button class="icon-btn" type="button" data-close aria-label="Kapat"><?= admin_icon('x', 16) ?></button>
        </header>
        <form method="post" action="plugins.php" enctype="multipart/form-data">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="upload">

            <div class="modal-body">
                <label class="drop">
                    <span><?= admin_icon('upload', 20) ?></span>
                    <strong>Eklenti ZIP dosyasını seçin</strong>
                    <small>ZIP içinde <code>hicms.json</code> bulunmalı</small>
                    <input type="file" name="paket" accept=".zip" required>
                </label>

                <?= ui_notice('warning', 'Eklentiler PHP kodu çalıştırır. Yalnızca kaynağına güvendiğiniz eklentileri kurun.') ?>
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
