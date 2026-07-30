<?php

declare(strict_types=1);

/**
 * HiAdmin — Panel önyükleyici
 *
 * Her panel sayfası ilk satırda bunu yükler. Çekirdeği başlatır, oturumu açar,
 * girişi zorunlu kılar ve panelin ortak yardımcılarını tanımlar.
 *
 * @package HiCMS
 */

use HiCMS\Kernel;
use HiCMS\Support\Str;

if (!is_readable(dirname(__DIR__, 2) . '/config.php')) {
    header('Location: ../install.php', true, 302);
    exit;
}

require dirname(__DIR__, 2) . '/src/Kernel.php';

$app = Kernel::boot(dirname(__DIR__, 2));

$app->auth()->startSession();

require __DIR__ . '/ui.php';

/* -------------------------------------------------------------------------
 * Geçerli sayfa
 * ---------------------------------------------------------------------- */

/**
 * İstenen panel sayfasının adı (uzantısız). Yerleşik sunucu gibi tek
 * yönlendirici betiği kullanan ortamlarda da doğru çalışır.
 */
function admin_page(): string
{
    static $page = null;

    if ($page !== null) {
        return $page;
    }

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $name = basename($path);

    if (str_ends_with($name, '.php')) {
        return $page = basename($name, '.php');
    }

    if ($name === '' || $name === 'admin') {
        return $page = 'index';
    }

    return $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '.php');
}

/* -------------------------------------------------------------------------
 * Bildirimler (flash)
 * ---------------------------------------------------------------------- */

/**
 * Yönlendirme sonrası gösterilecek bildirim bırakır.
 */
function admin_flash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['hicms.flash'][] = ['type' => $type, 'message' => $message];
    }
}

/**
 * Bekleyen bildirimleri alır ve kuyruğu temizler.
 *
 * @return list<array{type: string, message: string}>
 */
function admin_take_flash(): array
{
    $flash = $_SESSION['hicms.flash'] ?? [];

    unset($_SESSION['hicms.flash']);

    return is_array($flash) ? $flash : [];
}

/**
 * Bildirim bırakıp yönlendirir.
 */
function admin_redirect(string $url, string $type = '', string $message = ''): never
{
    if ($message !== '') {
        admin_flash($type, $message);
    }

    header('Location: ' . $url, true, 303);
    exit;
}

/* -------------------------------------------------------------------------
 * Güvenlik
 * ---------------------------------------------------------------------- */

/**
 * POST isteğinde CSRF anahtarını doğrular; geçersizse işlemi durdurur.
 */
function admin_verify(string $redirectTo = ''): void
{
    $token = (string) ($_POST['_token'] ?? '');

    if (hi()->csrf()->verify($token)) {
        return;
    }

    if ($redirectTo !== '') {
        admin_redirect($redirectTo, 'error', 'Güvenlik doğrulaması başarısız. Formu yeniden gönderin.');
    }

    /*
     * Yönlendirme hedefi verilmediğinde 0.2.0 `exit('…')` ile çıplak bir metin
     * basıyordu. En sık düşülen yer giriş sayfasıydı (login.php:25 hedefsiz
     * çağırıyor): formu açıp bir süre bekleyen kullanıcı, anahtarın süresi
     * dolduğu için stilsiz bir hata metniyle karşılaşıyor ve ne yapacağını
     * bilmiyordu. Sebep genellikle saldırı değil, süre aşımı.
     */
    http_response_code(419);
    admin_deny(
        'Güvenlik doğrulaması başarısız. Form çok uzun süre açık kaldıysa anahtarın '
        . 'süresi dolmuş olabilir; sayfayı yenileyip yeniden deneyin.'
    );
}

/**
 * İzin denetimi. Yetki yoksa açıklamalı bir sayfa gösterir.
 */
function admin_require(string $capability): void
{
    if (hi()->auth()->can($capability)) {
        return;
    }

    admin_deny(Str::format(
        'Bu bölüm için "%s" yetkisi gerekiyor. Rolünüz: %s.',
        $capability,
        hi()->roles()->label(hi()->auth()->user()?->role ?? '')
    ));
}

function admin_deny(string $reason): never
{
    http_response_code(403);

    admin_head(['title' => 'Yetki yok', 'slug' => admin_page()]);

    echo '<div class="panel panel-pad" style="max-width:520px">'
        . '<h2 class="h3">Bu sayfaya erişiminiz yok</h2>'
        . '<p class="muted">' . esc_html($reason) . '</p>'
        . '<a class="btn mt-3" href="index.php">Panele dön</a>'
        . '</div>';

    admin_foot();
    exit;
}

/* -------------------------------------------------------------------------
 * Giriş zorunluluğu
 * ---------------------------------------------------------------------- */

if (!in_array(admin_page(), ['login', 'logout'], true)) {
    if (!$app->auth()->check()) {
        $target = basename((string) ($_SERVER['REQUEST_URI'] ?? ''));

        header('Location: login.php' . ($target !== '' && $target !== 'index.php'
            ? '?donus=' . rawurlencode($target) : ''), true, 302);
        exit;
    }

    // Bekleyen migration varsa (elle dosya güncellemesi sonrası) uyar.
    if (admin_page() !== 'system' && $app->auth()->can('system.update') && $app->updater()->hasPendingMigrations()) {
        admin_flash('warning', 'Veritabanı güncellemesi bekliyor. Sistem → Güncellemeler bölümünden tamamlayın.');
    }
}

/* -------------------------------------------------------------------------
 * Panel menüsü
 * ---------------------------------------------------------------------- */

/**
 * Kenar menüsünü kurar. Eklentiler `Admin\MenuBuilding` olayıyla ekleme yapar.
 *
 * @return array<string, array{label: string, items: list<array<string, mixed>>}>
 */
function admin_menu(): array
{
    static $menu = null;

    if ($menu !== null) {
        return $menu;
    }

    $app  = hi();
    $auth = $app->auth();

    $groups = [
        'main' => ['label' => '', 'items' => [
            ['slug' => 'index', 'label' => 'Genel Bakış', 'icon' => 'gauge', 'url' => 'index.php'],
        ]],
    ];

    // İçerik türleri kayıttan üretilir: yeni tür ekleyen eklenti menüsünü de kazanır.
    $contentItems = [];

    foreach ($app->types()->menuTypes() as $type) {
        if (!$auth->can('content.read')) {
            break;
        }

        $pending = $app->content()->countOfType($type->name, 'pending');

        $contentItems[] = [
            'slug'  => 'content:' . $type->name,
            'label' => $type->plural,
            'icon'  => $type->icon,
            'url'   => $type->adminUrl(),
            'badge' => $pending > 0 ? $pending : null,
        ];
    }

    if ($contentItems !== []) {
        $groups['content'] = ['label' => 'İçerik', 'items' => $contentItems];
    }

    if ($auth->can('terms.manage')) {
        foreach ($app->types()->taxonomies() as $taxonomy) {
            $groups['content']['items'][] = [
                'slug'  => 'terms:' . $taxonomy->name,
                'label' => $taxonomy->plural,
                'icon'  => $taxonomy->name === 'tag' ? 'tag' : 'folder',
                'url'   => $taxonomy->adminUrl(),
            ];
        }
    }

    if ($auth->can('media.upload')) {
        $groups['content']['items'][] = [
            'slug' => 'media', 'label' => 'Medya', 'icon' => 'image', 'url' => 'media.php',
        ];
    }

    if ($auth->can('comments.moderate')) {
        $waiting = $app->comments()->statusCounts()['pending'] ?? 0;

        $groups['content']['items'][] = [
            'slug'  => 'comments',
            'label' => 'Yorumlar',
            'icon'  => 'message',
            'url'   => 'comments.php',
            'badge' => $waiting > 0 ? $waiting : null,
            'alert' => true,
        ];
    }

    if ($auth->can('appearance.manage')) {
        $groups['appearance'] = ['label' => 'Görünüm', 'items' => [
            ['slug' => 'appearance', 'label' => 'Temalar', 'icon' => 'palette', 'url' => 'appearance.php'],
            ['slug' => 'menus', 'label' => 'Menüler', 'icon' => 'list', 'url' => 'menus.php'],
            ['slug' => 'widgets', 'label' => 'Bileşenler', 'icon' => 'layout', 'url' => 'widgets.php'],
        ]];
    }

    $system = [];

    if ($auth->can('plugins.manage')) {
        $system[] = ['slug' => 'plugins', 'label' => 'Eklentiler', 'icon' => 'plug', 'url' => 'plugins.php'];
    }

    if ($auth->can('users.manage')) {
        $system[] = ['slug' => 'users', 'label' => 'Kullanıcılar', 'icon' => 'users', 'url' => 'users.php'];
    }

    if ($auth->can('settings.manage')) {
        $system[] = ['slug' => 'settings', 'label' => 'Ayarlar', 'icon' => 'settings', 'url' => 'settings.php'];
    }

    if ($auth->can('system.update') || $auth->can('system.backup') || $auth->can('system.logs')) {
        $update = $app->updateChecker()->check(
            $app->updater()->repository(),
            Kernel::VERSION
        );

        $system[] = [
            'slug'  => 'system',
            'label' => 'Sistem',
            'icon'  => 'server',
            'url'   => 'system.php',
            'badge' => $update['available'] ? 1 : null,
            'alert' => true,
        ];
    }

    if ($system !== []) {
        $groups['system'] = ['label' => 'Yönetim', 'items' => $system];
    }

    $event = $app->events()->dispatch(new HiCMS\Events\Admin\MenuBuilding($groups));

    return $menu = $event->groups;
}

/**
 * Menüde etkin sayılacak anahtar. Sayfalar bunu `$page['slug']` ile bildirir.
 */
function admin_active_slug(): string
{
    return $GLOBALS['hicms_admin_slug'] ?? admin_page();
}
