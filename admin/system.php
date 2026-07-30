<?php

declare(strict_types=1);

/**
 * HiAdmin — Sistem: güncelleme, yedek, durum, günlük, görevler
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Install\Requirements;
use HiCMS\Kernel;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

if (!$app->auth()->can('system.update') && !$app->auth()->can('system.backup') && !$app->auth()->can('system.logs')) {
    admin_deny('Sistem bölümü için güncelleme, yedekleme veya günlük yetkisi gerekiyor.');
}

$tabs = array_filter([
    'guncelleme' => $app->auth()->can('system.update') ? 'Güncellemeler' : null,
    'yedek'      => $app->auth()->can('system.backup') ? 'Yedekler' : null,
    'durum'      => 'Durum',
    'gorev'      => $app->auth()->can('system.update') ? 'Görevler' : null,
    'gunluk'     => $app->auth()->can('system.logs') ? 'Günlük' : null,
]);

$tab = (string) ($_GET['sekme'] ?? array_key_first($tabs));

if (!isset($tabs[$tab])) {
    $tab = (string) array_key_first($tabs);
}

$selfUrl = static fn(string $section = ''): string => 'system.php?sekme=' . rawurlencode($section !== '' ? $section : 'durum');

if ($app->request()->isPost()) {
    admin_verify($selfUrl($tab));

    $action = (string) ($_POST['islem'] ?? '');

    /*
     * İzin haritası TEK yerde ve varsayılanı KAPALI.
     *
     * 0.2.0'da iki ayrı in_array listesi vardı ve listede olmayan bir işlem
     * hiçbir izin denetiminden geçmiyordu. 'run-jobs' (satır ~111, planlı
     * görevleri elle çalıştırır) her iki listede de yoktu: sistem bölümünü
     * yalnızca 'system.logs' yetkisiyle açabilen bir kullanıcı — denetim
     * günlüğünü okuması beklenen biri — planlı görevleri çalıştırabiliyordu.
     *
     * Artık bilinmeyen bir işlem eklemek izin boşluğu değil, reddedilen bir
     * istek üretir.
     */
    $needs = [
        'check'         => 'system.update',
        'update-remote' => 'system.update',
        'update-upload' => 'system.update',
        'migrate'       => 'system.update',
        'run-jobs'      => 'system.update',
        'backup'        => 'system.backup',
        'restore'       => 'system.backup',
        'backup-delete' => 'system.backup',
    ];

    if (!isset($needs[$action])) {
        admin_redirect($selfUrl($tab), 'error', 'Tanınmayan işlem.');
    }

    admin_require($needs[$action]);

    switch ($action) {
        case 'check':
            $app->updateChecker()->clearCache();
            $check = $app->updater()->check(true);

            admin_redirect($selfUrl('guncelleme'), $check['error'] !== '' ? 'error' : 'success',
                $check['error'] !== ''
                    ? $check['error']
                    : ($check['available']
                        ? Str::format('Yeni sürüm bulundu: %s', $check['version'])
                        : 'Sistem güncel.'));

        case 'update-remote':
            $result = $app->updater()->updateFromRemote();

            admin_redirect($selfUrl('guncelleme'), $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? Str::format('HiCMS %s sürümünden %s sürümüne güncellendi.', $result['from'], $result['to'])
                    : $result['error']);

        case 'update-upload':
            $result = $app->updater()->updateFromUpload($_FILES['paket'] ?? []);

            admin_redirect($selfUrl('guncelleme'), $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? Str::format('HiCMS %s sürümüne güncellendi.', $result['to'])
                    : $result['error']);

        case 'migrate':
            $result = $app->migrator()->migrate();

            admin_redirect($selfUrl('guncelleme'), $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? Str::format('%d veritabanı adımı uygulandı.', count($result['applied']))
                    : $result['error']);

        case 'backup':
            $result = $app->backup()->create(
                trim((string) ($_POST['etiket'] ?? '')),
                isset($_POST['yuklemeler'])
            );

            admin_redirect($selfUrl('yedek'), $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? Str::format('Yedek alındı: %s (%s)', $result['file'], Str::bytes($result['size']))
                    : $result['error']);

        case 'restore':
            $result = $app->backup()->restore((string) ($_POST['dosya'] ?? ''));

            admin_redirect($selfUrl('yedek'), $result['ok'] ? 'success' : 'error',
                $result['ok']
                    ? Str::format('%d tablo geri yüklendi. Oturumunuz etkilenmiş olabilir.', $result['tables'])
                    : $result['error']);

        case 'backup-delete':
            $app->backup()->delete((string) ($_POST['dosya'] ?? ''));

            admin_redirect($selfUrl('yedek'), 'success', 'Yedek silindi.');

        case 'run-jobs':
            $result = $app->scheduler()->run(5);

            admin_redirect($selfUrl('gorev'), 'success',
                Str::format('%d görev çalıştırıldı.', $result['ran'])
                . ($result['errors'] !== [] ? ' Hata: ' . implode('; ', $result['errors']) : ''));
    }
}

$page = [
    'title'       => 'Sistem',
    'slug'        => 'system',
    'description' => 'Güncelleme, yedekleme, sunucu durumu ve denetim günlüğü.',
];

admin_head($page);

$tabList = [];

foreach ($tabs as $key => $label) {
    $tabList[] = ['label' => $label, 'url' => $selfUrl($key), 'active' => $tab === $key];
}

echo ui_tabs($tabList, 'line');

/* ------------------------------------------------------------------------- */

if ($tab === 'guncelleme') :
    $check      = $app->updater()->check();
    $last       = $app->updater()->lastUpdate();
    $pending    = $app->migrator()->pending();
    ?>
    <div class="cols-main">
        <div>
            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Çekirdek</h2>
                        <p class="panel-sub">Depo: <span class="mono"><?= esc_html($app->updater()->repository()) ?></span></p>
                    </div>
                    <div class="panel-actions">
                        <form method="post" action="<?= esc_url($selfUrl('guncelleme')) ?>">
                            <?= hi_csrf_field() ?>
                            <button class="btn btn-sm" type="submit" name="islem" value="check">
                                <?= admin_icon('refresh', 14) ?>Şimdi denetle
                            </button>
                        </form>
                    </div>
                </header>

                <div class="panel-body">
                    <div class="row row-wrap mb-3">
                        <span class="metric-value" style="font-size:28px"><?= esc_html(Kernel::VERSION) ?></span>
                        <?php if ($check['available']) : ?>
                            <span class="pill is-warn">v<?= esc_html($check['version']) ?> yayınlandı</span>
                        <?php elseif ($check['error'] !== '') : ?>
                            <span class="pill is-mute">denetlenemedi</span>
                        <?php else : ?>
                            <span class="pill is-ok">güncel</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($check['error'] !== '') : ?>
                        <?= ui_notice('warning', 'Sürüm bilgisi alınamadı: ' . $check['error']
                            . ' Paketi elle de yükleyebilirsiniz.') ?>
                    <?php endif; ?>

                    <?php if ($check['available']) : ?>
                        <?= ui_notice('info',
                            'Güncelleme sırasında src/ ve admin/ yenilenir; config.php, content/, themes/ ve plugins/ '
                            . 'dokunulmaz. Önce otomatik veritabanı yedeği alınır.') ?>

                        <?php if ($check['notes'] !== '') : ?>
                            <details class="mb-3">
                                <summary class="small dim" style="cursor:pointer">Sürüm notları</summary>
                                <pre class="mt-2"><?= esc_html(Str::limit($check['notes'], 2000)) ?></pre>
                            </details>
                        <?php endif; ?>

                        <form method="post" action="<?= esc_url($selfUrl('guncelleme')) ?>">
                            <?= hi_csrf_field() ?>
                            <button class="btn btn-primary" type="submit" name="islem" value="update-remote"
                                <?= ui_confirm('Güncelleme başlayacak. İşlem sırasında site kısa süre yanıt vermeyebilir. Devam edilsin mi?') ?>>
                                <?= admin_icon('download', 15) ?>
                                <?= esc_html(Str::format('%s sürümüne güncelle', $check['version'])) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Elle güncelleme</h2>
                        <p class="panel-sub">Kurulum ZIP'i güncelleme için de kullanılır</p>
                    </div>
                </header>
                <form method="post" action="<?= esc_url($selfUrl('guncelleme')) ?>" enctype="multipart/form-data">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="update-upload">

                    <div class="panel-body">
                        <label class="drop">
                            <span><?= admin_icon('upload', 20) ?></span>
                            <strong>HiCMS ZIP dosyasını seçin</strong>
                            <small>Aynı paketle hem kurulum hem güncelleme yapılır</small>
                            <input type="file" name="paket" accept=".zip" required>
                        </label>
                    </div>
                </form>
            </section>

            <?php if ($pending !== []) : ?>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Bekleyen veritabanı adımları</h2>
                            <p class="panel-sub"><?= count($pending) ?> adım uygulanmayı bekliyor</p>
                        </div>
                    </header>
                    <div class="panel-body">
                        <ul class="kv mb-3">
                            <?php foreach ($pending as $migration) : ?>
                                <li><span class="k mono"><?= esc_html($migration['key']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>

                        <form method="post" action="<?= esc_url($selfUrl('guncelleme')) ?>">
                            <?= hi_csrf_field() ?>
                            <button class="btn btn-primary btn-sm" type="submit" name="islem" value="migrate">
                                Adımları uygula
                            </button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($last !== null) : ?>
                <section class="box">
                    <header class="box-head"><?= admin_icon('clock', 15) ?>Son güncelleme</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Önce</span><span class="v"><?= esc_html((string) $last['from']) ?></span></li>
                            <li><span class="k">Sonra</span><span class="v"><?= esc_html((string) $last['to']) ?></span></li>
                            <li><span class="k">Tarih</span><span class="v"><?= esc_html(Dates::format((string) $last['at'], 'j M Y H:i')) ?></span></li>
                            <li><span class="k">Yedek</span><span class="v mono small"><?= esc_html((string) $last['backup']) ?></span></li>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>

            <section class="box">
                <header class="box-head"><?= admin_icon('shield', 15) ?>Korunan yollar</header>
                <div class="box-body">
                    <p class="hint mt-0">Güncellemede bu yollara dokunulmaz:</p>
                    <ul class="kv">
                        <?php foreach ($app->updater()->preservedPaths() as $path) : ?>
                            <li><span class="k mono"><?= esc_html($path) ?></span>
                                <span class="v"><?= admin_icon('check', 13) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>

<?php elseif ($tab === 'yedek') :
    $backups = $app->backup()->all();
    ?>
    <div class="cols-main">
        <div>
            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Yedekler</h2>
                        <p class="panel-sub">Toplam <?= esc_html(Str::bytes($app->backup()->totalSize())) ?></p>
                    </div>
                </header>

                <?php if ($backups !== []) : ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Dosya</th>
                                    <th>Etiket</th>
                                    <th>Sürüm</th>
                                    <th class="num">Boyut</th>
                                    <th>Tarih</th>
                                    <th class="fit"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup) : ?>
                                    <tr>
                                        <td class="mono small"><?= esc_html($backup['file']) ?></td>
                                        <td class="small dim"><?= esc_html($backup['label'] !== '' ? $backup['label'] : '—') ?></td>
                                        <td class="small"><?= esc_html($backup['version']) ?></td>
                                        <td class="num"><?= esc_html(Str::bytes($backup['size'])) ?></td>
                                        <td class="small muted nowrap"><?= ui_time($backup['created']) ?></td>
                                        <td class="fit">
                                            <div class="row-acts">
                                                <button class="icon-btn" type="submit" form="backup-form"
                                                        name="islem" value="restore" title="Geri yükle"
                                                        aria-label="Geri yükle"
                                                        onclick="document.getElementById('backup-file').value='<?= esc_attr($backup['file']) ?>'"
                                                    <?= ui_confirm('Veritabanı bu yedekle DEĞİŞTİRİLECEK. Mevcut veriler kaybolur. Devam edilsin mi?') ?>>
                                                    <?= admin_icon('refresh', 15) ?>
                                                </button>
                                                <button class="icon-btn" type="submit" form="backup-form"
                                                        name="islem" value="backup-delete" title="Sil" aria-label="Sil"
                                                        onclick="document.getElementById('backup-file').value='<?= esc_attr($backup['file']) ?>'"
                                                    <?= ui_confirm('Bu yedek dosyası silinecek.') ?>>
                                                    <?= admin_icon('trash', 15) ?>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <?= ui_empty('archive', 'Yedek yok', 'Sağdaki formdan ilk yedeğinizi alın.') ?>
                <?php endif; ?>
            </section>

            <form id="backup-form" method="post" action="<?= esc_url($selfUrl('yedek')) ?>" hidden>
                <?= hi_csrf_field() ?>
                <input type="hidden" name="dosya" id="backup-file" value="">
            </form>
        </div>

        <div>
            <section class="box">
                <header class="box-head"><?= admin_icon('archive', 15) ?>Yeni yedek</header>
                <form method="post" action="<?= esc_url($selfUrl('yedek')) ?>">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="backup">

                    <div class="box-body">
                        <?= ui_field('Etiket', ui_input('etiket', '', ['id' => 'b-etiket',
                            'placeholder' => 'örn. tema değişikliği öncesi']),
                            'Yedeği sonradan tanımanız için.', 'b-etiket') ?>

                        <?= ui_switch('yuklemeler', true, 'Medya dosyalarını dahil et',
                            'Kapatırsanız yalnızca veritabanı yedeklenir; çok daha hızlı olur.') ?>
                    </div>

                    <footer class="box-foot">
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit">Yedek al</button>
                    </footer>
                </form>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('info', 15) ?>Yedek içeriği</header>
                <div class="box-body">
                    <ul class="kv">
                        <li><span class="k mono">manifest.json</span><span class="v">künye</span></li>
                        <li><span class="k mono">database.sql</span><span class="v">tüm tablolar</span></li>
                        <li><span class="k mono">uploads/</span><span class="v">isteğe bağlı</span></li>
                    </ul>
                    <p class="hint">Yedekler <code>content/backups/</code> altında tutulur ve web erişimine kapalıdır.</p>
                </div>
            </section>
        </div>
    </div>

<?php elseif ($tab === 'durum') :
    $checks  = Requirements::check($app->rootDir());
    $summary = Requirements::summary($checks);
    ?>
    <?= ui_metrics([
        ['label' => 'Uygun', 'value' => (string) $summary['ok']],
        ['label' => 'Uyarı', 'value' => (string) $summary['warn']],
        ['label' => 'Eksik', 'value' => (string) $summary['fail']],
        ['label' => 'PHP', 'value' => PHP_VERSION],
    ]) ?>

    <div class="cols-main mt-3">
        <section class="panel">
            <header class="panel-head"><div><h2 class="panel-title">Sunucu denetimi</h2></div></header>
            <div class="panel-body">
                <ul class="checks">
                    <?php foreach ($checks as $check) : ?>
                        <?php $state = $check['ok'] ? 'is-ok' : ($check['required'] ? 'is-err' : 'is-warn'); ?>
                        <li>
                            <span class="mark <?= $state ?>">
                                <?= admin_icon($check['ok'] ? 'check' : 'alert', 11) ?>
                            </span>
                            <span class="body">
                                <?= esc_html($check['label']) ?>
                                <?php if (!$check['ok']) : ?>
                                    <small><?= esc_html($check['hint']) ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="meta"><?= esc_html($check['value']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <div>
            <section class="box">
                <header class="box-head"><?= admin_icon('server', 15) ?>Ortam</header>
                <div class="box-body">
                    <ul class="kv">
                        <?php foreach (Requirements::environment($app) as $key => $value) : ?>
                            <li><span class="k"><?= esc_html($key) ?></span><span class="v"><?= esc_html($value) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('database', 15) ?>Veritabanı</header>
                <div class="box-body">
                    <ul class="kv">
                        <li><span class="k">Önek</span><span class="v mono"><?= esc_html($app->db()->prefix()) ?></span></li>
                        <li><span class="k">Tablo</span><span class="v"><?= count($app->db()->ownTables()) ?></span></li>
                        <li><span class="k">Bu istekte sorgu</span><span class="v"><?= $app->db()->queryCount() ?></span></li>
                    </ul>
                </div>
            </section>
        </div>
    </div>

<?php elseif ($tab === 'gorev') :
    $jobs = $app->scheduler()->pending(40);
    ?>
    <section class="panel">
        <header class="panel-head">
            <div>
                <h2 class="panel-title">Planlı görevler</h2>
                <p class="panel-sub">
                    Süresi gelmiş <?= $app->scheduler()->dueCount() ?> görev ·
                    her ziyarette bir görev, yanıt gönderildikten sonra çalışır
                </p>
            </div>
            <div class="panel-actions">
                <form method="post" action="<?= esc_url($selfUrl('gorev')) ?>">
                    <?= hi_csrf_field() ?>
                    <button class="btn btn-sm" type="submit" name="islem" value="run-jobs">
                        <?= admin_icon('play', 14) ?>Şimdi çalıştır
                    </button>
                </form>
            </div>
        </header>

        <?php if ($jobs !== []) : ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Görev</th>
                            <th>Sıradaki çalışma</th>
                            <th>Aralık</th>
                            <th class="num">Deneme</th>
                            <th>Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job) : ?>
                            <tr>
                                <td class="mono small"><?= esc_html((string) $job['name']) ?></td>
                                <td class="small muted"><?= ui_time((string) $job['run_at']) ?></td>
                                <td class="small"><?= esc_html((string) ($job['interval_spec'] ?? '— tek seferlik')) ?></td>
                                <td class="num"><?= (int) $job['attempts'] ?></td>
                                <td>
                                    <?php if (($job['last_error'] ?? '') !== '') : ?>
                                        <span class="pill is-err" title="<?= esc_attr((string) $job['last_error']) ?>">hata</span>
                                    <?php elseif ($app->scheduler()->hasHandler((string) $job['name'])) : ?>
                                        <span class="pill is-ok">hazır</span>
                                    <?php else : ?>
                                        <span class="pill is-warn">işleyici yok</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <?= ui_empty('clock', 'Kuyruk boş', 'Planlı görev bulunmuyor.') ?>
        <?php endif; ?>
    </section>

<?php else :
    $logPage = max(1, (int) ($_GET['sayfa'] ?? 1));
    $log     = $app->audit()->paginate([
        'action' => (string) ($_GET['eylem'] ?? ''),
        'search' => trim((string) ($_GET['ara'] ?? '')),
        'page'   => $logPage,
        'perPage' => 40,
    ]);
    ?>
    <section class="panel">
        <div class="filters">
            <form class="row" method="get" action="system.php">
                <input type="hidden" name="sekme" value="gunluk">

                <?php
                $actions = ['' => 'Tüm eylemler'];

                foreach ($app->audit()->actions() as $actionName) {
                    $actions[$actionName] = $actionName;
                }
                ?>
                <label class="sr-only" for="l-eylem">Eylem</label>
                <?= ui_select('eylem', $actions, (string) ($_GET['eylem'] ?? ''),
                    ['id' => 'l-eylem', 'class' => 'input select w-auto']) ?>

                <label class="sr-only" for="l-ara">Ara</label>
                <input class="input" type="search" id="l-ara" name="ara" style="width:200px"
                       value="<?= esc_attr((string) ($_GET['ara'] ?? '')) ?>" placeholder="Kişi veya özet">

                <button class="btn" type="submit"><?= admin_icon('filter', 15) ?>Süz</button>
            </form>
            <span class="spacer"></span>
            <span class="muted small"><?= esc_html(Str::format('%s kayıt', Str::number($log['total']))) ?></span>
        </div>

        <?php if ($log['items'] !== []) : ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Kişi</th>
                            <th>Eylem</th>
                            <th>Özet</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log['items'] as $row) : ?>
                            <tr>
                                <td class="small muted nowrap"><?= ui_time((string) $row['created_at']) ?></td>
                                <td class="small"><?= esc_html((string) ($row['actor'] ?? 'Sistem')) ?></td>
                                <td class="small mono"><?= esc_html((string) $row['action']) ?></td>
                                <td class="small dim"><?= esc_html((string) ($row['summary'] ?? '')) ?></td>
                                <td class="small muted mono"><?= esc_html((string) ($row['ip'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= ui_pagination($log['page'], $log['pages'],
                static fn(int $n): string => 'system.php?sekme=gunluk&sayfa=' . $n, $log['total']) ?>
        <?php else : ?>
            <?= ui_empty('clock', 'Kayıt yok', 'Denetim günlüğünde bu süzgeçlere uyan kayıt bulunmuyor.') ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php admin_foot(); ?>
