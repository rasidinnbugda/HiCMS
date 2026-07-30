<?php

declare(strict_types=1);

/**
 * HiAdmin — Ayarlar
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Auth\Roles;
use HiCMS\Content\Permalinks;
use HiCMS\Support\Str;

admin_require('settings.manage');

$tabs = [
    'genel'     => 'Genel',
    'okuma'     => 'Okuma',
    'tartisma'  => 'Tartışma',
    'medya'     => 'Medya',
    'baglanti'  => 'Bağlantılar',
    'roller'    => 'Roller',
];

$tab = (string) ($_GET['sekme'] ?? 'genel');

if (!isset($tabs[$tab])) {
    $tab = 'genel';
}

$options = $app->options();

if ($app->request()->isPost()) {
    admin_verify('settings.php?sekme=' . $tab);

    $section = (string) ($_POST['bolum'] ?? $tab);
    $saved   = [];

    switch ($section) {
        case 'genel':
            $saved = [
                'site_title'       => trim((string) ($_POST['site_title'] ?? '')),
                'site_tagline'     => trim((string) ($_POST['site_tagline'] ?? '')),
                'site_description' => trim((string) ($_POST['site_description'] ?? '')),
                'admin_email'      => trim((string) ($_POST['admin_email'] ?? '')),
                'footer_note'      => trim((string) ($_POST['footer_note'] ?? '')),
                'default_scheme'   => in_array($_POST['default_scheme'] ?? 'auto', ['auto', 'light', 'dark'], true)
                    ? (string) $_POST['default_scheme'] : 'auto',
                'social'           => array_map(
                    static fn(mixed $url): string => is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL) !== false
                        ? trim($url) : '',
                    (array) ($_POST['social'] ?? [])
                ),
            ];
            break;

        case 'okuma':
            $saved = [
                'posts_per_page'      => max(1, min(50, (int) ($_POST['posts_per_page'] ?? 8))),
                'feed_count'          => max(1, min(50, (int) ($_POST['feed_count'] ?? 15))),
                'show_featured'       => isset($_POST['show_featured']),
                'search_engine_index' => isset($_POST['search_engine_index']),
            ];
            break;

        case 'tartisma':
            $saved = [
                'comments_open'      => isset($_POST['comments_open']),
                'comment_moderation' => isset($_POST['comment_moderation']),
                'comment_depth'      => max(1, min(6, (int) ($_POST['comment_depth'] ?? 3))),
                'comment_blocklist'  => trim((string) ($_POST['comment_blocklist'] ?? '')),
            ];
            break;

        case 'medya':
            $saved = [
                'upload_max_bytes' => max(262144, min(67108864, (int) ($_POST['upload_max_mb'] ?? 16) * 1048576)),
                'allow_svg'        => isset($_POST['allow_svg']),
            ];
            break;

        case 'baglanti':
            $structure = (string) ($_POST['permalink_structure'] ?? 'route');

            $saved = [
                'permalink_structure' => isset(Permalinks::STRUCTURES[$structure]) ? $structure : 'route',
                'cron_key'            => trim((string) ($_POST['cron_key'] ?? '')) !== ''
                    ? trim((string) $_POST['cron_key'])
                    : Str::random(16),
                'log_retention_days'  => max(7, min(3650, (int) ($_POST['log_retention_days'] ?? 180))),
            ];
            break;

        case 'roller':
            $custom = [];

            foreach ((array) ($_POST['caps'] ?? []) as $role => $capabilities) {
                if (!is_string($role) || $role === 'admin') {
                    continue;
                }

                $custom[$role] = [
                    'label'        => $app->roles()->label($role),
                    'capabilities' => array_values(array_intersect(
                        array_map('strval', (array) $capabilities),
                        array_keys(Roles::CAPABILITIES)
                    )),
                ];
            }

            $saved = ['custom_roles' => $custom];
            break;
    }

    if ($saved !== []) {
        $options->setMany($saved);

        $app->audit()->record(
            action: 'settings.update',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: 'settings',
            summary: $tabs[$section] ?? $section,
            ip: $app->request()->ip(),
        );
    }

    admin_redirect('settings.php?sekme=' . $tab, 'success', 'Ayarlar kaydedildi.');
}

$page = [
    'title'       => 'Ayarlar',
    'slug'        => 'settings',
    'description' => 'Site kimliği, okuma davranışı, tartışma kuralları ve yetkiler.',
    'actions'     => '<button class="btn btn-primary" type="submit" form="settings-form" data-primary-save>'
        . admin_icon('save', 15) . 'Kaydet</button>',
];

admin_head($page);

$tabList = [];

foreach ($tabs as $key => $label) {
    $tabList[] = ['label' => $label, 'url' => 'settings.php?sekme=' . $key, 'active' => $tab === $key];
}

echo ui_tabs($tabList, 'line');
?>

<form id="settings-form" method="post" action="settings.php?sekme=<?= esc_attr($tab) ?>">
    <?= hi_csrf_field() ?>
    <input type="hidden" name="bolum" value="<?= esc_attr($tab) ?>">

    <div class="cols-main">
        <div>
            <?php if ($tab === 'genel') : ?>
                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Site kimliği</h2></div></header>
                    <div class="panel-body">
                        <?= ui_field('Site başlığı', ui_input('site_title', (string) $options->get('site_title', ''),
                            ['id' => 's-title', 'required' => true]),
                            'Tarayıcı sekmesinde ve temada görünür.', 's-title', true) ?>

                        <?= ui_field('Slogan', ui_input('site_tagline', (string) $options->get('site_tagline', ''),
                            ['id' => 's-tagline']), 'Sitenizi bir cümleyle anlatın.', 's-tagline') ?>

                        <?= ui_field('Site açıklaması',
                            '<textarea class="input" id="s-desc" name="site_description" rows="3">'
                            . esc_html((string) $options->get('site_description', '')) . '</textarea>',
                            'Arama sonuçlarında ve paylaşım kartlarında kullanılır.', 's-desc') ?>

                        <div class="field-row">
                            <?= ui_field('Yönetici e-postası', ui_input('admin_email',
                                (string) $options->get('admin_email', ''), ['type' => 'email', 'id' => 's-mail']),
                                'Sistem bildirimleri bu adrese gider.', 's-mail') ?>

                            <?= ui_field('Varsayılan tema şeması',
                                ui_select('default_scheme', ['auto' => 'Ziyaretçinin tercihi', 'light' => 'Açık', 'dark' => 'Karanlık'],
                                    (string) $options->get('default_scheme', 'auto'), ['id' => 's-scheme']),
                                'Ön yüzün ilk açılışta hangi şemayla görüneceği.', 's-scheme') ?>
                        </div>

                        <?= ui_field('Alt bilgi notu', ui_input('footer_note',
                            (string) $options->get('footer_note', ''), ['id' => 's-footer']),
                            'Temanın altında telif satırının yanında görünür.', 's-footer') ?>
                    </div>
                </section>

                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Sosyal medya</h2>
                        <p class="panel-sub">Boş bırakılanlar temada gösterilmez</p></div></header>
                    <div class="panel-body">
                        <?php
                        $social = (array) $options->get('social', []);

                        foreach (['x' => 'X (Twitter)', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
                                  'github' => 'GitHub', 'youtube' => 'YouTube'] as $key => $label) {
                            echo ui_field($label, ui_input('social[' . $key . ']',
                                (string) ($social[$key] ?? ''),
                                ['type' => 'url', 'id' => 'social-' . $key, 'placeholder' => 'https://']),
                                '', 'social-' . $key);
                        }
                        ?>
                    </div>
                </section>

            <?php elseif ($tab === 'okuma') : ?>
                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Listeleme ve besleme</h2></div></header>
                    <div class="panel-body">
                        <div class="field-row">
                            <?= ui_field('Sayfa başına içerik', ui_input('posts_per_page',
                                (string) $options->get('posts_per_page', 8),
                                ['type' => 'number', 'id' => 's-per', 'min' => '1', 'max' => '50']),
                                'Ana sayfa ve tüm arşivlerde geçerli.', 's-per') ?>

                            <?= ui_field('Beslemede içerik', ui_input('feed_count',
                                (string) $options->get('feed_count', 15),
                                ['type' => 'number', 'id' => 's-feed', 'min' => '1', 'max' => '50']),
                                'RSS beslemesinde kaç kayıt olacağı.', 's-feed') ?>
                        </div>

                        <?= ui_switch('show_featured', (bool) $options->get('show_featured', true),
                            'Manşet alanını göster', 'Ana sayfada öne çıkan içerik büyük olarak gösterilir.') ?>

                        <?= ui_switch('search_engine_index', (bool) $options->get('search_engine_index', true),
                            'Arama motorlarına izin ver', 'Kapatırsanız siteye noindex etiketi eklenir.') ?>
                    </div>
                </section>

            <?php elseif ($tab === 'tartisma') : ?>
                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Yorum kuralları</h2></div></header>
                    <div class="panel-body">
                        <?= ui_switch('comments_open', (bool) $options->get('comments_open', true),
                            'Yorumlara izin ver', 'Yeni içeriklerde varsayılan davranış.') ?>

                        <?= ui_switch('comment_moderation', (bool) $options->get('comment_moderation', true),
                            'Yorumlar önce onaylanmalı', 'Onaylanmayan yorumlar sitede görünmez.') ?>

                        <div class="mt-3">
                            <?= ui_field('İç içe yanıt derinliği', ui_input('comment_depth',
                                (string) $options->get('comment_depth', 3),
                                ['type' => 'number', 'id' => 's-depth', 'min' => '1', 'max' => '6']),
                                '', 's-depth') ?>
                        </div>

                        <?= ui_field('İstenmeyen kelime listesi',
                            '<textarea class="input mono" id="s-block" name="comment_blocklist" rows="5">'
                            . esc_html((string) $options->get('comment_blocklist', '')) . '</textarea>',
                            'Her satıra bir kelime ya da alan adı. Bu kelimeleri içeren yorumlar istenmeyen klasörüne düşer.',
                            's-block') ?>
                    </div>
                </section>

            <?php elseif ($tab === 'medya') : ?>
                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Yükleme</h2></div></header>
                    <div class="panel-body">
                        <?= ui_field('En büyük dosya boyutu (MB)', ui_input('upload_max_mb',
                            (string) (int) ((int) $options->get('upload_max_bytes', 16777216) / 1048576),
                            ['type' => 'number', 'id' => 's-max', 'min' => '1', 'max' => '64']),
                            'Sunucu sınırı: ' . esc_html((string) (ini_get('upload_max_filesize') ?: '?')),
                            's-max') ?>

                        <?= ui_switch('allow_svg', (bool) $options->get('allow_svg', false),
                            'SVG yüklemeye izin ver',
                            'SVG dosyaları betik taşıyabilir. HiCMS yüklerken temizler, ama yalnızca güvendiğiniz dosyaları yükleyin.') ?>

                        <?php if (!$app->plugins()->isActive('hi-media')) : ?>
                            <div class="mt-3">
                                <?= ui_notice('info',
                                    'Görsel küçültme ve WebP/AVIF üretimi HiMedia eklentisiyle gelir. Çekirdek yalnızca yükler ve srcset basar.',
                                    '<a class="btn btn-sm" href="plugins.php">Eklentiler</a>') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            <?php elseif ($tab === 'baglanti') : ?>
                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Kalıcı bağlantı yapısı</h2>
                        <p class="panel-sub">Yayına aldıktan sonra değiştirmek eski bağlantıları kırar</p></div></header>
                    <div class="panel-body">
                        <?php
                        $current = (string) $options->get('permalink_structure', 'route');

                        $labels = [
                            'route' => ['/yazi/{kisa-ad}', 'Varsayılan — kısa ve okunur'],
                            'date'  => ['/2026/07/{kisa-ad}', 'Tarih tabanlı — haber siteleri için'],
                            'flat'  => ['/{kisa-ad}', 'En kısa — sayfa adlarıyla çakışma riski var'],
                        ];

                        foreach ($labels as $key => [$pattern, $note]) :
                            ?>
                            <label class="check">
                                <input type="radio" name="permalink_structure" value="<?= esc_attr($key) ?>"
                                    <?= $current === $key ? 'checked' : '' ?>>
                                <span class="check-body">
                                    <strong class="mono"><?= esc_html($pattern) ?></strong>
                                    <small><?= esc_html($note) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel">
                    <header class="panel-head"><div><h2 class="panel-title">Planlı görevler ve günlük</h2></div></header>
                    <div class="panel-body">
                        <?= ui_field('Cron anahtarı', ui_input('cron_key',
                            (string) $options->get('cron_key', ''), ['id' => 's-cron', 'class' => 'input mono']),
                            'HTTP üzerinden hi-cron.php çağırmak için gerekir. Boş bırakırsanız yenisi üretilir.',
                            's-cron') ?>

                        <div class="hint mb-3">
                            Gerçek cron kurmak isterseniz:
                            <code>*/5 * * * * php <?= esc_html($app->rootDir()) ?>/hi-cron.php</code>
                        </div>

                        <?= ui_field('Günlük saklama süresi (gün)', ui_input('log_retention_days',
                            (string) $options->get('log_retention_days', 180),
                            ['type' => 'number', 'id' => 's-log', 'min' => '7', 'max' => '3650']),
                            'Denetim günlüğündeki daha eski kayıtlar otomatik silinir.', 's-log') ?>
                    </div>
                </section>

            <?php else : ?>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Roller ve izinler</h2>
                            <p class="panel-sub">Yönetici rolü değiştirilemez; her zaman tüm izinlere sahiptir</p>
                        </div>
                    </header>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>İzin</th>
                                    <?php foreach ($app->roles()->options() as $role => $label) : ?>
                                        <th class="center"><?= esc_html($label) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (Roles::CAPABILITIES as $capability => $label) : ?>
                                    <tr>
                                        <td>
                                            <span class="cell-title"><?= esc_html($label) ?></span>
                                            <span class="cell-sub mono"><?= esc_html($capability) ?></span>
                                        </td>
                                        <?php foreach (array_keys($app->roles()->options()) as $role) : ?>
                                            <td class="center">
                                                <label class="check" style="justify-content:center">
                                                    <input type="checkbox"
                                                           name="caps[<?= esc_attr($role) ?>][]"
                                                           value="<?= esc_attr($capability) ?>"
                                                        <?= $app->roles()->roleCan($role, $capability) ? 'checked' : '' ?>
                                                        <?= $role === 'admin' ? 'disabled' : '' ?>>
                                                    <span class="sr-only"><?= esc_html($label) ?></span>
                                                </label>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <footer class="panel-foot">
                        <span class="muted small">Değişiklikler kaydedildikten sonra tüm oturumlarda geçerli olur.</span>
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit">İzinleri kaydet</button>
                    </footer>
                </section>
            <?php endif; ?>
        </div>

        <div>
            <section class="box">
                <header class="box-head"><?= admin_icon('info', 15) ?>Bu bölüm</header>
                <div class="box-body">
                    <p class="small dim mb-0">
                        <?php
                        echo esc_html(match ($tab) {
                            'genel'    => 'Site kimliği temanın her sayfasında kullanılır; başlık ve slogan arama sonuçlarında görünen ilk metindir.',
                            'okuma'    => 'Sayfa başına içerik sayısı ana sayfa ve tüm arşivlerde geçerlidir. Değeri artırmak sayfa boyutunu büyütür.',
                            'tartisma' => 'Onay zorunluluğu, istenmeyen yorumların siteye hiç düşmemesini sağlar. Küçük sitelerde açık tutulması önerilir.',
                            'medya'    => 'Yükleme sınırı sunucu ayarlarını aşamaz. SVG desteği yalnızca gerekiyorsa açılmalıdır.',
                            'baglanti' => 'Bağlantı yapısı hem üretilen adresleri hem çözümlenen rotaları belirler; ikisi birlikte değişir.',
                            default    => 'İzinler ince tanelidir: yayınlama ile başkalarının içeriğini düzenleme ayrı yetkilerdir.',
                        });
                        ?>
                    </p>
                </div>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('server', 15) ?>Ortam</header>
                <div class="box-body">
                    <ul class="kv">
                        <?php foreach (HiCMS\Install\Requirements::environment($app) as $key => $value) : ?>
                            <li><span class="k"><?= esc_html($key) ?></span><span class="v"><?= esc_html($value) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>
</form>

<?php admin_foot(); ?>
