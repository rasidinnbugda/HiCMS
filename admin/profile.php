<?php

declare(strict_types=1);

/**
 * HiAdmin — Kendi profilim
 *
 * Her kullanıcı, rolü ne olursa olsun kendi hesabını düzenleyebilir.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Dates;
use HiCMS\Support\Str;

$me = $app->auth()->user();

if ($me === null) {
    admin_redirect('login.php');
}

if ($app->request()->isPost()) {
    admin_verify('profile.php');

    $section = (string) ($_POST['bolum'] ?? 'profile');

    if ($section === 'password') {
        $current = (string) ($_POST['mevcut'] ?? '');
        $new     = (string) ($_POST['yeni'] ?? '');
        $repeat  = (string) ($_POST['yeni_tekrar'] ?? '');

        if (!$app->users()->verifyPassword($me, $current)) {
            admin_redirect('profile.php', 'error', 'Mevcut şifre hatalı.');
        }

        if ($new !== $repeat) {
            admin_redirect('profile.php', 'error', 'Yeni şifreler birbiriyle uyuşmuyor.');
        }

        $result = $app->users()->update($me, $new);

        admin_redirect('profile.php', $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Şifreniz güncellendi.' : $result['error']);
    }

    $me->displayName = trim((string) ($_POST['ad'] ?? ''));
    $me->email       = trim((string) ($_POST['eposta'] ?? ''));
    $me->bio         = trim((string) ($_POST['kunye'] ?? ''));
    $me->slug        = trim((string) ($_POST['kisa_ad'] ?? ''));

    $me->preferences['scheme'] = (string) ($_POST['sema'] ?? 'auto');
    $me->preferences['notify'] = isset($_POST['bildirim']);

    $result = $app->users()->update($me);

    admin_redirect('profile.php', $result['ok'] ? 'success' : 'error',
        $result['ok'] ? 'Profiliniz güncellendi.' : $result['error']);
}

$entryCount = $app->content()->query([
    'type'          => 'all',
    'status'        => 'all',
    'author'        => $me->id,
    'perPage'       => 1,
    'withRelations' => false,
])['total'];

$page = [
    'title'       => 'Profilim',
    'slug'        => 'profile',
    'description' => 'Hesap bilgileriniz ve panel tercihleriniz.',
];

admin_head($page);
?>

<div class="cols-main">
    <div>
        <form class="panel" method="post" action="profile.php">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="bolum" value="profile">

            <header class="panel-head">
                <div><h2 class="panel-title">Hesap</h2></div>
            </header>

            <div class="panel-body">
                <div class="field-row">
                    <?= ui_field('Görünen ad', ui_input('ad', $me->displayName,
                        ['id' => 'p-ad', 'required' => true]), 'Yazılarda bu ad görünür.', 'p-ad', true) ?>

                    <?= ui_field('Kullanıcı adı', ui_input('kadi', $me->username,
                        ['id' => 'p-kadi', 'readonly' => true, 'class' => 'input mono']),
                        'Değiştirilemez.', 'p-kadi') ?>
                </div>

                <div class="field-row">
                    <?= ui_field('E-posta', ui_input('eposta', $me->email,
                        ['type' => 'email', 'id' => 'p-eposta', 'required' => true]), '', 'p-eposta', true) ?>

                    <?= ui_field('Yazar bağlantısı',
                        '<div class="input-group"><span class="addon">/yazar/</span>'
                        . ui_input('kisa_ad', $me->slug, ['id' => 'p-slug', 'class' => 'input mono'])
                        . '</div>', '', 'p-slug') ?>
                </div>

                <?= ui_field('Künye',
                    '<textarea class="input" id="p-kunye" name="kunye" rows="4">' . esc_html($me->bio) . '</textarea>',
                    'Yazılarınızın altında yazar kutusunda görünür.', 'p-kunye') ?>

                <hr>

                <div class="field-row">
                    <?= ui_field('Panel teması',
                        ui_select('sema', ['auto' => 'Sistem tercihini kullan', 'light' => 'Açık', 'dark' => 'Karanlık'],
                            (string) $me->preference('scheme', 'auto'), ['id' => 'p-sema']),
                        'Tarayıcıdaki geçiş düğmesi bu ayarı geçici olarak değiştirir.', 'p-sema') ?>

                    <div class="field">
                        <?= ui_switch('bildirim', (bool) $me->preference('notify', true),
                            'E-posta bildirimleri', 'Yeni yorum geldiğinde bilgilendir.') ?>
                    </div>
                </div>
            </div>

            <footer class="panel-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit" data-primary-save>Kaydet</button>
            </footer>
        </form>

        <form class="panel" method="post" action="profile.php">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="bolum" value="password">

            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Şifre</h2>
                    <p class="panel-sub">En az 10 karakter kullanın</p>
                </div>
            </header>

            <div class="panel-body">
                <?= ui_field('Mevcut şifre', ui_input('mevcut', '', [
                    'type' => 'password', 'id' => 'p-mevcut', 'required' => true,
                    'autocomplete' => 'current-password',
                ]), '', 'p-mevcut', true) ?>

                <div class="field-row">
                    <?= ui_field('Yeni şifre', ui_input('yeni', '', [
                        'type' => 'password', 'id' => 'p-yeni', 'required' => true,
                        'minlength' => '10', 'autocomplete' => 'new-password',
                    ]), '', 'p-yeni', true) ?>

                    <?= ui_field('Yeni şifre (tekrar)', ui_input('yeni_tekrar', '', [
                        'type' => 'password', 'id' => 'p-yeni2', 'required' => true,
                        'minlength' => '10', 'autocomplete' => 'new-password',
                    ]), '', 'p-yeni2', true) ?>
                </div>
            </div>

            <footer class="panel-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm" type="submit">Şifreyi değiştir</button>
            </footer>
        </form>
    </div>

    <div>
        <section class="box">
            <div class="box-body center">
                <?= ui_avatar($me->displayName, 'xl') ?>
                <h3 class="h3 mt-2"><?= esc_html($me->displayName) ?></h3>
                <p class="muted small"><?= esc_html($app->roles()->label($me->role)) ?></p>
            </div>
        </section>

        <section class="box">
            <header class="box-head"><?= admin_icon('activity', 15) ?>Hesap özeti</header>
            <div class="box-body">
                <ul class="kv">
                    <li><span class="k">İçerik</span><span class="v"><?= Str::number($entryCount) ?></span></li>
                    <li><span class="k">Kayıt</span><span class="v"><?= esc_html(Dates::format($me->createdAt, 'j M Y')) ?></span></li>
                    <li><span class="k">Son giriş</span><span class="v"><?= esc_html(Dates::ago($me->lastLoginAt)) ?></span></li>
                </ul>
            </div>
        </section>

        <section class="box">
            <header class="box-head"><?= admin_icon('key', 15) ?>Yetkileriniz</header>
            <div class="box-body">
                <ul class="checks">
                    <?php foreach ($app->roles()->capabilitiesOf($me->role) as $capability) : ?>
                        <li>
                            <span class="mark is-ok"><?= admin_icon('check', 11) ?></span>
                            <span class="body"><?= esc_html(HiCMS\Auth\Roles::CAPABILITIES[$capability] ?? $capability) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
</div>

<?php admin_foot(); ?>
