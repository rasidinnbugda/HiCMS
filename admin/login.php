<?php

declare(strict_types=1);

/**
 * HiAdmin — Giriş
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Kernel;

// Giriş yapmış kullanıcıyı panele al.
if ($app->auth()->check()) {
    admin_redirect('index.php');
}

$error    = '';
$identity = '';
$return   = (string) ($_GET['donus'] ?? '');

if ($app->request()->isPost()) {
    admin_verify();

    $identity = trim((string) ($_POST['kullanici'] ?? ''));
    $password = (string) ($_POST['sifre'] ?? '');
    $remember = isset($_POST['hatirla']);

    $result = $app->auth()->attempt(
        $identity,
        $password,
        $remember,
        $app->request()->ip(),
        $app->request()->userAgent()
    );

    if ($result['ok']) {
        $target = preg_match('/^[a-z0-9_-]+\.php(\?[^\s]*)?$/i', $return) === 1 ? $return : 'index.php';

        admin_redirect($target);
    }

    $error = $result['error'];
}

$flash = admin_take_flash();
?>
<!DOCTYPE html>
<html lang="tr" data-scheme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Giriş · <?= esc_html($app->siteName()) ?></title>

    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?= esc_attr(Kernel::VERSION) ?>">

    <script>
        (function () {
            try {
                var s = localStorage.getItem('hicms-scheme');
                var dark = s ? s === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-scheme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
</head>
<body>
<div class="auth">
    <div class="auth-box">
        <div class="auth-brand">
            <span class="logo-mark" aria-hidden="true">Hi</span>
            <div>
                <h1><?= esc_html($app->siteName()) ?></h1>
                <p class="lede mb-0">Yönetim paneli</p>
            </div>
        </div>

        <?php foreach ($flash as $item) : ?>
            <?= ui_notice((string) $item['type'], (string) $item['message']) ?>
        <?php endforeach; ?>

        <?php if ($error !== '') : ?>
            <?= ui_notice('error', $error) ?>
        <?php endif; ?>

        <form class="auth-card" method="post" action="login.php<?= $return !== '' ? '?donus=' . esc_attr(rawurlencode($return)) : '' ?>">
            <?= hi_csrf_field() ?>

            <div class="field">
                <label class="label" for="kullanici">Kullanıcı adı veya e-posta</label>
                <input class="input" type="text" id="kullanici" name="kullanici" required autofocus
                       autocomplete="username" value="<?= esc_attr($identity) ?>">
            </div>

            <div class="field">
                <label class="label" for="sifre">Şifre</label>
                <input class="input" type="password" id="sifre" name="sifre" required autocomplete="current-password">
            </div>

            <div class="auth-links">
                <label class="check">
                    <input type="checkbox" name="hatirla" value="1" checked>
                    <span class="check-body">Beni hatırla</span>
                </label>
            </div>

            <button class="btn btn-primary btn-lg btn-block" type="submit">Giriş yap</button>
        </form>

        <p class="auth-foot">
            HiCMS <?= esc_html(Kernel::VERSION) ?> ·
            <a href="<?= esc_url($app->urls()->to()) ?>">Siteye dön</a>
        </p>
    </div>
</div>

<script src="assets/js/admin.js?v=<?= esc_attr(Kernel::VERSION) ?>"></script>
</body>
</html>
