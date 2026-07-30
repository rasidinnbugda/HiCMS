<?php

declare(strict_types=1);

/**
 * HiAdmin — Arayüz yardımcıları
 *
 * Panel sayfalarının kullandığı küçük bileşen fonksiyonları. Amaç sayfa
 * dosyalarını kısa tutmak: tablo, alan, nişan ve sayfalama gibi tekrarlayan
 * işaretlemeler burada tek yerde durur.
 *
 * @package HiCMS
 */

use HiCMS\Kernel;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/* -------------------------------------------------------------------------
 * İkonlar — satır içi SVG, harici kütüphane yok
 * ---------------------------------------------------------------------- */

/**
 * @return array<string, string>
 */
function admin_icon_paths(): array
{
    static $icons = null;

    return $icons ??= [
        'gauge'    => '<path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="m13.4 12.6 4.6-4.6"/><path d="M3.5 18a9 9 0 1 1 17 0"/>',
        'file-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
        'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'folder'   => '<path d="M4 5h4.5l2 2.5H20a1 1 0 0 1 1 1V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1z"/>',
        'tag'      => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m4 18 5-5 4 4 3-3 4 4"/>',
        'message'  => '<path d="M20 15a2 2 0 0 1-2 2H8l-4 3V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z"/>',
        'palette'  => '<path d="M12 21a9 9 0 1 1 9-9c0 2-1.5 3-3 3h-1.5a2 2 0 0 0-1.4 3.4A2 2 0 0 1 12 21z"/><circle cx="8" cy="10" r="1.1"/><circle cx="12" cy="7.5" r="1.1"/><circle cx="15.8" cy="10" r="1.1"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'layout'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16M3 9h12"/>',
        'plug'     => '<path d="M9 3v6M15 3v6"/><path d="M7 9h10v3a5 5 0 0 1-10 0z"/><path d="M12 17v4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16.5 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.3a6.5 6.5 0 0 1 3.5 5.7"/>',
        'user'     => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.5 12a7.5 7.5 0 0 0-.1-1.3l2-1.5-2-3.4-2.3 1a7.6 7.6 0 0 0-2.2-1.3L14.5 3h-4l-.4 2.5a7.6 7.6 0 0 0-2.2 1.3l-2.3-1-2 3.4 2 1.5a7.5 7.5 0 0 0 0 2.6l-2 1.5 2 3.4 2.3-1a7.6 7.6 0 0 0 2.2 1.3l.4 2.5h4l.4-2.5a7.6 7.6 0 0 0 2.2-1.3l2.3 1 2-3.4-2-1.5c.06-.43.1-.86.1-1.3z"/>',
        'server'   => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'    => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/><path d="M10 11v5M14 11v5"/>',
        'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M21 3l-9 9"/>',
        'check'    => '<path d="m20 6-11 11-5-5"/>',
        'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
        'more'     => '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M12 3v13M7 8l5-5 5 5"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M12 16V3M7 11l5 5 5-5"/>',
        'refresh'  => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 4v4h-4M3 20v-4h4"/>',
        'save'     => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'alert'    => '<path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6A2 2 0 0 0 22 18L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'log-out'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3A5 5 0 0 0 13.5 3.5l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3A5 5 0 0 0 10.5 20.5l1.7-1.7"/>',
        'code'     => '<path d="m16 18 6-6-6-6M8 6 2 12l6 6"/>',
        'quote'    => '<path d="M9 7H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2v1a3 3 0 0 1-3 3"/><path d="M19 7h-4a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h2v1a3 3 0 0 1-3 3"/>',
        'heading'  => '<path d="M6 4v16M18 4v16M6 12h12"/>',
        'text'     => '<path d="M4 6h16M4 12h16M4 18h10"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'play'     => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5 16 12l-6 3.5z"/>',
        'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
        'brackets' => '<path d="M8 4H6a2 2 0 0 0-2 2v3a2 2 0 0 1-2 2 2 2 0 0 1 2 2v3a2 2 0 0 0 2 2h2"/><path d="M16 4h2a2 2 0 0 1 2 2v3a2 2 0 0 0 2 2 2 2 0 0 0-2 2v3a2 2 0 0 1-2 2h-2"/>',
        'block'    => '<rect x="4" y="4" width="16" height="16" rx="2"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'shield'   => '<path d="M12 21s8-3.5 8-9.5V5.5L12 3 4 5.5v6c0 6 8 9.5 8 9.5z"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M20 12c0 1.7-3.6 3-8 3s-8-1.3-8-3"/>',
        'activity' => '<path d="M22 12h-4l-3 8L9 4l-3 8H2"/>',
        'trend-up' => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
        'drag'     => '<circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/>',
        'filter'   => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2z"/><path d="M4 17h15"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 1.5 1.5L18 12l-1.5-1.5"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
        'lock'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'inbox'    => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6z"/>',
        'sliders'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'archive'  => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'star'     => '<path d="m12 3 2.9 5.9 6.1.9-4.5 4.3 1.1 6.1L12 17.5 6.4 20.2l1.1-6.1L3 9.8l6.1-.9z"/>',
    ];
}

/**
 * İkon HTML'i döndürür.
 */
function admin_icon(string $name, int $size = 16, string $class = ''): string
{
    $paths = admin_icon_paths();
    $body  = $paths[$name] ?? $paths['block'];

    return sprintf(
        '<svg class="ico%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
        $class !== '' ? ' ' . Str::attr($class) : '',
        $size,
        $size,
        $body
    );
}

/* -------------------------------------------------------------------------
 * Sayfa iskeleti
 * ---------------------------------------------------------------------- */

/**
 * Panel sayfasının başını basar.
 *
 * @param array{title: string, slug?: string, description?: string,
 *              breadcrumb?: list<array{label: string, url?: string}>,
 *              actions?: string, wide?: bool, bare?: bool} $page
 */
function admin_head(array $page): void
{
    $app  = hi();
    $user = $app->auth()->user();

    $GLOBALS['hicms_admin_slug'] = $page['slug'] ?? admin_page();

    $title = (string) ($page['title'] ?? 'Panel');
    ?>
<!DOCTYPE html>
<html lang="tr" data-scheme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc_html($title) ?> · <?= esc_html($app->siteName()) ?></title>

    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= esc_attr(Kernel::VERSION) ?>">

    <?php // Şemayı boyamadan önce uygula: sayfa geçişlerinde beyaz parlama olmasın. ?>
    <script>
        (function () {
            try {
                var s = localStorage.getItem('hicms-scheme');
                var dark = s ? s === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-scheme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <?php $app->events()->emit('admin.head'); ?>
</head>
<body class="page-<?= esc_attr($GLOBALS['hicms_admin_slug']) ?>">
<a class="skip" href="#main">İçeriğe geç</a>

<div class="shell">
    <aside class="side" id="sidebar">
        <div class="side-top">
            <a class="logo" href="index.php">
                <span class="logo-mark" aria-hidden="true">Hi</span>
                <span class="logo-text">
                    <strong><?= esc_html($app->siteName()) ?></strong>
                    <span>HiCMS <?= esc_html(Kernel::VERSION) ?></span>
                </span>
            </a>
        </div>

        <nav class="side-nav" aria-label="Panel menüsü">
            <?php foreach (admin_menu() as $group) : ?>
                <?php if (($group['items'] ?? []) === []) { continue; } ?>

                <?php if (($group['label'] ?? '') !== '') : ?>
                    <p class="nav-group"><?= esc_html($group['label']) ?></p>
                <?php endif; ?>

                <ul class="nav-list">
                    <?php foreach ($group['items'] as $item) : ?>
                        <?php $active = admin_active_slug() === ($item['slug'] ?? ''); ?>
                        <li>
                            <a class="nav-link<?= $active ? ' is-active' : '' ?>"
                               href="<?= esc_url((string) ($item['url'] ?? '#')) ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <?= admin_icon((string) ($item['icon'] ?? 'block'), 17) ?>
                                <span><?= esc_html((string) ($item['label'] ?? '')) ?></span>
                                <?php if (!empty($item['badge'])) : ?>
                                    <em class="nav-badge<?= !empty($item['alert']) ? ' is-alert' : '' ?>">
                                        <?= (int) $item['badge'] ?>
                                    </em>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="side-foot">
            <a class="side-site" href="<?= esc_url($app->urls()->to()) ?>" target="_blank" rel="noopener">
                <?= admin_icon('external', 15) ?>
                <span>Siteyi görüntüle</span>
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="bar">
            <button class="icon-btn only-mobile" type="button" data-toggle="sidebar" aria-label="Menü">
                <?= admin_icon('list', 18) ?>
            </button>

            <form class="bar-search" method="get" action="content.php" role="search">
                <?= admin_icon('search', 16) ?>
                <label class="sr-only" for="bar-q">İçerikte ara</label>
                <input type="search" id="bar-q" name="ara" placeholder="İçerikte ara"
                       value="<?= esc_attr((string) ($_GET['ara'] ?? '')) ?>">
            </form>

            <span class="bar-gap"></span>

            <a class="btn btn-sm" href="content-edit.php?tur=post">
                <?= admin_icon('plus', 15) ?><span class="only-wide">Yeni yazı</span>
            </a>

            <button class="icon-btn" type="button" data-toggle="scheme" aria-pressed="false" aria-label="Temayı değiştir">
                <span data-ico="moon"><?= admin_icon('moon', 17) ?></span>
                <span data-ico="sun" hidden><?= admin_icon('sun', 17) ?></span>
            </button>

            <div class="menu" data-menu>
                <button class="user-btn" type="button" data-menu-trigger aria-expanded="false">
                    <span class="avatar"><?= esc_html($user?->initials() ?? '?') ?></span>
                    <span class="user-meta only-wide">
                        <strong><?= esc_html($user?->displayName ?? '') ?></strong>
                        <small><?= esc_html($app->roles()->label($user?->role ?? '')) ?></small>
                    </span>
                    <?= admin_icon('chevron-down', 14) ?>
                </button>

                <div class="menu-pop" data-menu-pop hidden>
                    <div class="menu-head">
                        <strong><?= esc_html($user?->displayName ?? '') ?></strong>
                        <small><?= esc_html($user?->email ?? '') ?></small>
                    </div>
                    <a class="menu-item" href="profile.php"><?= admin_icon('user', 15) ?>Profilim</a>
                    <?php if ($app->auth()->can('settings.manage')) : ?>
                        <a class="menu-item" href="settings.php"><?= admin_icon('settings', 15) ?>Ayarlar</a>
                    <?php endif; ?>
                    <div class="menu-sep"></div>
                    <a class="menu-item is-danger" href="logout.php"><?= admin_icon('log-out', 15) ?>Çıkış yap</a>
                </div>
            </div>
        </header>

        <main class="content<?= !empty($page['wide']) ? ' is-wide' : '' ?>" id="main">
            <?php if (empty($page['bare'])) : ?>
                <div class="page-head">
                    <div>
                        <?php if (!empty($page['breadcrumb'])) : ?>
                            <nav class="crumbs" aria-label="Konum">
                                <?php foreach ($page['breadcrumb'] as $index => $crumb) : ?>
                                    <?php if ($index > 0) : ?><span aria-hidden="true">/</span><?php endif; ?>
                                    <?php if (!empty($crumb['url'])) : ?>
                                        <a href="<?= esc_url((string) $crumb['url']) ?>"><?= esc_html((string) $crumb['label']) ?></a>
                                    <?php else : ?>
                                        <span><?= esc_html((string) $crumb['label']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </nav>
                        <?php endif; ?>

                        <h1 class="page-title"><?= esc_html($title) ?></h1>

                        <?php if (($page['description'] ?? '') !== '') : ?>
                            <p class="page-desc"><?= esc_html((string) $page['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if (($page['actions'] ?? '') !== '') : ?>
                        <div class="page-actions"><?= $page['actions'] ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php admin_notices(); ?>
    <?php
}

/**
 * Bekleyen bildirimleri ve eklenti uyarılarını basar.
 */
function admin_notices(): void
{
    foreach (admin_take_flash() as $flash) {
        echo ui_notice((string) $flash['type'], (string) $flash['message']);
    }

    // Yüklenemeyen eklentiler sessizce kaybolmasın.
    foreach (hi()->plugins()->failures() as $slug => $error) {
        echo ui_notice('error', Str::format('"%s" eklentisi yüklenemedi ve devre dışı bırakıldı: %s', $slug, $error));
    }

    hi()->events()->emit('admin.notices');
}

function admin_foot(): void
{
    ?>
        </main>

        <footer class="foot">
            <span>HiCMS <?= esc_html(Kernel::VERSION) ?></span>
            <span aria-hidden="true">·</span>
            <span>Tema: <?= esc_html(hi()->themes()->active()?->name ?? '—') ?></span>
            <span class="bar-gap"></span>
            <?php if (hi()->isDebug()) : ?>
                <span><?= (int) hi()->db()->queryCount() ?> sorgu</span>
            <?php endif; ?>
        </footer>
    </div>
</div>

<div class="scrim" data-scrim hidden></div>

<script src="assets/js/admin.js?v=<?= esc_attr(Kernel::VERSION) ?>"></script>
<?php hi()->events()->emit('admin.footer'); ?>
</body>
</html>
    <?php
}

/* -------------------------------------------------------------------------
 * Bileşenler
 * ---------------------------------------------------------------------- */

function ui_notice(string $type, string $message, string $actionHtml = ''): string
{
    $icon = match ($type) {
        'success' => 'check',
        'warning' => 'alert',
        'error'   => 'alert',
        default   => 'info',
    };

    $class = match ($type) {
        'success' => 'is-ok',
        'warning' => 'is-warn',
        'error'   => 'is-err',
        default   => 'is-info',
    };

    return '<div class="notice ' . $class . '" role="status">'
        . admin_icon($icon, 16)
        . '<p>' . esc_html($message) . '</p>'
        . ($actionHtml !== '' ? '<span class="notice-action">' . $actionHtml . '</span>' : '')
        . '<button class="icon-btn notice-x" type="button" data-dismiss aria-label="Kapat">'
        . admin_icon('x', 14) . '</button>'
        . '</div>';
}

/**
 * Durum nişanı.
 */
function ui_status(string $status): string
{
    [$class, $label] = match ($status) {
        'published' => ['is-ok', 'Yayında'],
        'draft'     => ['is-mute', 'Taslak'],
        'pending'   => ['is-warn', 'İncelemede'],
        'private'   => ['is-info', 'Özel'],
        'approved'  => ['is-ok', 'Onaylı'],
        'spam'      => ['is-err', 'İstenmeyen'],
        'active'    => ['is-ok', 'Etkin'],
        'inactive'  => ['is-mute', 'Devre dışı'],
        default     => ['is-mute', $status],
    };

    return '<span class="pill ' . $class . '">' . esc_html($label) . '</span>';
}

function ui_avatar(string $name, string $size = ''): string
{
    return '<span class="avatar' . ($size !== '' ? ' avatar-' . Str::attr($size) : '') . '">'
        . esc_html(Str::initials($name)) . '</span>';
}

/**
 * Form alanı sarmalayıcı.
 */
function ui_field(string $label, string $control, string $hint = '', string $for = '', bool $required = false): string
{
    return '<div class="field">'
        . ($label !== '' ? '<label class="label"' . ($for !== '' ? ' for="' . Str::attr($for) . '"' : '') . '>'
            . esc_html($label) . ($required ? ' <span class="req">*</span>' : '') . '</label>' : '')
        . $control
        . ($hint !== '' ? '<p class="hint">' . $hint . '</p>' : '')
        . '</div>';
}

/**
 * Metin girdisi.
 *
 * @param array<string, string|bool> $attrs
 */
function ui_input(string $name, string $value = '', array $attrs = []): string
{
    $defaults = ['type' => 'text', 'id' => $name, 'class' => 'input'];
    $attrs    = array_merge($defaults, $attrs);

    $html = '<input name="' . Str::attr($name) . '" value="' . Str::attr($value) . '"';

    foreach ($attrs as $key => $val) {
        if ($val === false || $val === null) {
            continue;
        }

        $html .= $val === true ? ' ' . Str::attr($key) : ' ' . Str::attr($key) . '="' . Str::attr((string) $val) . '"';
    }

    return $html . '>';
}

/**
 * Açılır liste.
 *
 * @param array<string|int, string> $options
 * @param array<string, string|bool> $attrs
 */
function ui_select(string $name, array $options, string $selected = '', array $attrs = []): string
{
    $attrs = array_merge(['id' => $name, 'class' => 'input select'], $attrs);

    $html = '<select name="' . Str::attr($name) . '"';

    foreach ($attrs as $key => $val) {
        if ($val === false || $val === null) {
            continue;
        }

        $html .= $val === true ? ' ' . Str::attr($key) : ' ' . Str::attr($key) . '="' . Str::attr((string) $val) . '"';
    }

    $html .= '>';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . Str::attr((string) $value) . '"'
            . ((string) $value === $selected ? ' selected' : '') . '>'
            . esc_html($label) . '</option>';
    }

    return $html . '</select>';
}

/**
 * Anahtar (switch).
 */
function ui_switch(string $name, bool $on, string $title, string $hint = ''): string
{
    return '<label class="switch">'
        . '<input type="checkbox" name="' . Str::attr($name) . '" value="1"' . ($on ? ' checked' : '') . '>'
        . '<span class="switch-body"><strong>' . esc_html($title) . '</strong>'
        . ($hint !== '' ? '<small>' . esc_html($hint) . '</small>' : '') . '</span>'
        . '</label>';
}

/**
 * Boş durum bloğu.
 */
function ui_empty(string $icon, string $title, string $text, string $actionHtml = ''): string
{
    return '<div class="empty">'
        . '<span class="empty-ico">' . admin_icon($icon, 22) . '</span>'
        . '<h3>' . esc_html($title) . '</h3>'
        . '<p>' . esc_html($text) . '</p>'
        . ($actionHtml !== '' ? '<div class="empty-actions">' . $actionHtml . '</div>' : '')
        . '</div>';
}

/**
 * Sekme şeridi.
 *
 * @param list<array{label: string, url: string, active?: bool, count?: int|null}> $tabs
 */
function ui_tabs(array $tabs, string $variant = ''): string
{
    $html = '<div class="tabs' . ($variant !== '' ? ' tabs-' . Str::attr($variant) : '') . '">';

    foreach ($tabs as $tab) {
        $html .= '<a class="tab' . (!empty($tab['active']) ? ' is-active' : '') . '" href="'
            . esc_url($tab['url']) . '">' . esc_html($tab['label']);

        if (($tab['count'] ?? null) !== null) {
            $html .= '<em>' . (int) $tab['count'] . '</em>';
        }

        $html .= '</a>';
    }

    return $html . '</div>';
}

/**
 * Sayfalama.
 */
function ui_pagination(int $page, int $pages, callable $urlFor, int $total = 0): string
{
    if ($pages < 2) {
        return $total > 0
            ? '<div class="pager"><span class="pager-info">' . Str::format('%s kayıt', Str::number($total)) . '</span></div>'
            : '';
    }

    $html = '<div class="pager">';

    if ($total > 0) {
        $html .= '<span class="pager-info">' . Str::format('%s kayıt · sayfa %d/%d', Str::number($total), $page, $pages) . '</span>';
    }

    $html .= '<span class="bar-gap"></span><div class="pager-links">';

    $html .= $page > 1
        ? '<a class="pager-link" href="' . esc_url($urlFor($page - 1)) . '" rel="prev">' . admin_icon('chevron-left', 14) . '</a>'
        : '<span class="pager-link is-off">' . admin_icon('chevron-left', 14) . '</span>';

    for ($i = 1; $i <= $pages; $i++) {
        $edge = $i === 1 || $i === $pages;
        $near = abs($i - $page) <= 1;

        if (!$edge && !$near) {
            if (abs($i - $page) === 2) {
                $html .= '<span class="pager-gap">…</span>';
            }

            continue;
        }

        $html .= $i === $page
            ? '<span class="pager-link is-current">' . $i . '</span>'
            : '<a class="pager-link" href="' . esc_url($urlFor($i)) . '">' . $i . '</a>';
    }

    $html .= $page < $pages
        ? '<a class="pager-link" href="' . esc_url($urlFor($page + 1)) . '" rel="next">' . admin_icon('chevron-right', 14) . '</a>'
        : '<span class="pager-link is-off">' . admin_icon('chevron-right', 14) . '</span>';

    return $html . '</div></div>';
}

/**
 * Metrik şeridi — panelin imza öğesi. Kutular yerine kılçizgiyle bölünmüş
 * tek bir satır; sayılar tabular rakamla hizalanır.
 *
 * @param list<array{label: string, value: string, note?: string, trend?: string, href?: string}> $metrics
 */
function ui_metrics(array $metrics): string
{
    $html = '<div class="metrics">';

    foreach ($metrics as $metric) {
        $inner = '<span class="metric-label">' . esc_html($metric['label']) . '</span>'
            . '<span class="metric-value">' . esc_html($metric['value']) . '</span>'
            . (($metric['note'] ?? '') !== ''
                ? '<span class="metric-note">' . esc_html((string) $metric['note']) . '</span>' : '');

        $html .= ($metric['href'] ?? '') !== ''
            ? '<a class="metric" href="' . esc_url((string) $metric['href']) . '">' . $inner . '</a>'
            : '<div class="metric">' . $inner . '</div>';
    }

    return $html . '</div>';
}

/**
 * Panel kutusu açar.
 */
function ui_panel_open(string $title = '', string $actionsHtml = '', string $sub = ''): string
{
    $html = '<section class="panel">';

    if ($title !== '' || $actionsHtml !== '') {
        $html .= '<header class="panel-head"><div><h2 class="panel-title">' . esc_html($title) . '</h2>'
            . ($sub !== '' ? '<p class="panel-sub">' . esc_html($sub) . '</p>' : '') . '</div>'
            . ($actionsHtml !== '' ? '<div class="panel-actions">' . $actionsHtml . '</div>' : '')
            . '</header>';
    }

    return $html;
}

function ui_panel_close(string $footHtml = ''): string
{
    return ($footHtml !== '' ? '<footer class="panel-foot">' . $footHtml . '</footer>' : '') . '</section>';
}

/**
 * Onay isteyen bağlantı/düğme için öznitelik.
 */
function ui_confirm(string $message): string
{
    return ' data-confirm="' . Str::attr($message) . '"';
}

/**
 * Göreli zaman + tam tarih ipucu.
 */
function ui_time(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '<span class="muted">—</span>';
    }

    return '<time datetime="' . Str::attr(Dates::iso($datetime)) . '" title="'
        . Str::attr(Dates::format($datetime, 'j F Y, H:i')) . '">'
        . esc_html(Dates::ago($datetime)) . '</time>';
}
