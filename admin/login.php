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
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Giriş · <?= esc_html($app->siteName()) ?></title>

    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= esc_attr(admin_asset('assets/css/admin.css')) ?>">

    <?php // Yalnızca kullanıcının açık seçimi; şemanın kendisi CSS'ten gelir. ?>
    <script>
        (function () {
            try {
                var s = localStorage.getItem('hicms-scheme');
                if (s === 'dark' || s === 'light') {
                    document.documentElement.setAttribute('data-scheme', s);
                }
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

<script src="<?= esc_attr(admin_asset('assets/js/admin.js')) ?>"></script>
</body>
</html>
