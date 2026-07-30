<?php

declare(strict_types=1);

/**
 * HiAdmin — Kullanıcılar
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Model\User;
use HiCMS\Support\Str;

admin_require('users.manage');

$selfUrl = 'users.php';
$editId  = (int) ($_GET['duzenle'] ?? 0);

if ($app->request()->isPost()) {
    admin_verify($selfUrl);

    $action = (string) ($_POST['islem'] ?? 'save');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id === $app->auth()->id()) {
            admin_redirect($selfUrl, 'error', 'Kendi hesabınızı silemezsiniz.');
        }

        if ($app->users()->isLastAdmin($id)) {
            admin_redirect($selfUrl, 'error', 'Son yöneticiyi silemezsiniz.');
        }

        $target = $app->users()->find($id);

        if ($target === null) {
            admin_redirect($selfUrl, 'error', 'Kullanıcı bulunamadı.');
        }

        $app->users()->delete($id, $app->auth()->id());

        $app->audit()->record(
            action: 'users.delete',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: 'user',
            subjectId: $id,
            summary: 'Kullanıcı silindi: ' . $target->displayName,
            ip: $app->request()->ip(),
        );

        admin_redirect($selfUrl, 'success', Str::format('"%s" silindi, içerikleri size devredildi.', $target->displayName));
    }

    $id   = (int) ($_POST['id'] ?? 0);
    $user = $id > 0 ? $app->users()->find($id) : new User();

    if ($user === null) {
        admin_redirect($selfUrl, 'error', 'Kullanıcı bulunamadı.');
    }

    $user->displayName = trim((string) ($_POST['ad'] ?? ''));
    $user->email       = trim((string) ($_POST['eposta'] ?? ''));
    $user->bio         = trim((string) ($_POST['kunye'] ?? ''));
    $user->slug        = trim((string) ($_POST['kisa_ad'] ?? ''));
    $role              = (string) ($_POST['rol'] ?? 'subscriber');

    // Son yöneticinin rolü düşürülemez.
    if ($id > 0 && $role !== 'admin' && $app->users()->isLastAdmin($id)) {
        admin_redirect($selfUrl, 'error', 'Sistemde en az bir yönetici kalmalı.');
    }

    $user->role   = $app->roles()->exists($role) ? $role : 'subscriber';
    $user->status = (string) ($_POST['durum'] ?? 'active') === 'active' ? 'active' : 'suspended';

    $password = (string) ($_POST['sifre'] ?? '');

    if ($id > 0) {
        $result = $app->users()->update($user, $password);
        $message = 'Kullanıcı güncellendi.';
    } else {
        $user->username = trim((string) ($_POST['kullanici_adi'] ?? ''));
        $result = $app->users()->create($user, $password);
        $message = 'Kullanıcı eklendi.';
    }

    if (!$result['ok']) {
        admin_redirect($selfUrl . ($id > 0 ? '?duzenle=' . $id : ''), 'error', $result['error']);
    }

    $app->audit()->record(
        action: $id > 0 ? 'users.update' : 'users.create',
        userId: $app->auth()->id(),
        actor: (string) $app->auth()->user()?->displayName,
        subjectType: 'user',
        subjectId: $id > 0 ? $id : (int) ($result['id'] ?? 0),
        summary: $user->displayName . ' — ' . $app->roles()->label($user->role),
        ip: $app->request()->ip(),
    );

    admin_redirect($selfUrl, 'success', $message);
}

$users    = $app->users()->all();
$editing  = $editId > 0 ? $app->users()->find($editId) : null;
$byRole   = $app->users()->countByRole();
$roleOpts = $app->roles()->options();

$page = [
    'title'       => 'Kullanıcılar',
    'slug'        => 'users',
    'description' => 'Rol değişiklikleri anında geçerli olur. İzin ayrıntıları için Ayarlar → Roller.',
    'actions'     => $editing === null
        ? '<a class="btn btn-primary" href="#yeni-kullanici">' . admin_icon('plus', 15) . 'Yeni kullanıcı</a>'
        : '<a class="btn" href="users.php">Listeye dön</a>',
];

admin_head($page);

$metrics = [];

foreach (['admin', 'editor', 'author'] as $role) {
    $metrics[] = ['label' => $app->roles()->label($role), 'value' => Str::number($byRole[$role] ?? 0)];
}

$metrics[] = ['label' => 'Toplam', 'value' => Str::number(count($users))];

echo ui_metrics($metrics);
?>

<section class="panel mt-3">
    <header class="panel-head">
        <div><h2 class="panel-title">Hesaplar</h2></div>
    </header>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>Kullanıcı</th>
                    <th>E-posta</th>
                    <th>Rol</th>
                    <th class="num">İçerik</th>
                    <th>Son giriş</th>
                    <th>Durum</th>
                    <th class="fit"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $row) : ?>
                    <tr>
                        <td>
                            <div class="cell-person">
                                <?= ui_avatar($row->displayName, 'md') ?>
                                <div>
                                    <strong><?= esc_html($row->displayName) ?></strong>
                                    <small class="mono">@<?= esc_html($row->username) ?></small>
                                </div>
                                <?php if ($row->id === $app->auth()->id()) : ?>
                                    <span class="pill is-info no-dot">siz</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="small dim"><?= esc_html($row->email) ?></td>
                        <td class="small"><?= esc_html($app->roles()->label($row->role)) ?></td>
                        <td class="num"><?= (int) $row->entryCount ?></td>
                        <td class="small muted nowrap"><?= ui_time($row->lastLoginAt) ?></td>
                        <td><?= ui_status($row->status === 'active' ? 'active' : 'inactive') ?></td>
                        <td class="fit">
                            <div class="row-acts">
                                <a class="icon-btn" href="users.php?duzenle=<?= (int) $row->id ?>"
                                   title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                <a class="icon-btn" href="<?= esc_url($app->links()->forAuthor($row)) ?>"
                                   target="_blank" rel="noopener" title="Yazar sayfası"
                                   aria-label="Yazar sayfası"><?= admin_icon('eye', 15) ?></a>
                                <?php if ($row->id !== $app->auth()->id()) : ?>
                                    <button class="icon-btn" type="submit" form="user-delete"
                                            formaction="users.php?id=<?= (int) $row->id ?>"
                                            name="id" value="<?= (int) $row->id ?>" title="Sil" aria-label="Sil"
                                        <?= ui_confirm(Str::format('"%s" silinecek, içerikleri size devredilecek. Devam edilsin mi?', $row->displayName)) ?>>
                                        <?= admin_icon('trash', 15) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<form id="user-delete" method="post" action="users.php" hidden>
    <?= hi_csrf_field() ?>
    <input type="hidden" name="islem" value="delete">
</form>

<section class="panel mt-3" id="yeni-kullanici">
    <header class="panel-head">
        <div>
            <h2 class="panel-title">
                <?= $editing !== null ? esc_html($editing->displayName) . ' — düzenle' : 'Yeni kullanıcı' ?>
            </h2>
            <p class="panel-sub">
                <?= $editing !== null
                    ? 'Şifre alanını boş bırakırsanız mevcut şifre korunur.'
                    : 'Şifre en az 10 karakter olmalıdır.' ?>
            </p>
        </div>
    </header>

    <form method="post" action="users.php">
        <?= hi_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing?->id ?? 0) ?>">

        <div class="panel-body">
            <div class="field-row">
                <?= ui_field('Görünen ad', ui_input('ad', $editing?->displayName ?? '',
                    ['id' => 'u-ad', 'required' => true]), '', 'u-ad', true) ?>

                <?php if ($editing !== null) : ?>
                    <?= ui_field('Kullanıcı adı', ui_input('kullanici_adi_ro', $editing->username,
                        ['id' => 'u-kadi', 'readonly' => true, 'class' => 'input mono']),
                        'Kullanıcı adı sonradan değiştirilemez.', 'u-kadi') ?>
                <?php else : ?>
                    <?= ui_field('Kullanıcı adı', ui_input('kullanici_adi', '',
                        ['id' => 'u-kadi', 'required' => true, 'class' => 'input mono',
                         'pattern' => '[A-Za-z0-9_.\-]{3,32}']),
                        '3–32 karakter; harf, sayı, nokta, tire, alt çizgi.', 'u-kadi', true) ?>
                <?php endif; ?>
            </div>

            <div class="field-row">
                <?= ui_field('E-posta', ui_input('eposta', $editing?->email ?? '',
                    ['type' => 'email', 'id' => 'u-eposta', 'required' => true]), '', 'u-eposta', true) ?>

                <?= ui_field('Rol', ui_select('rol', $roleOpts, $editing?->role ?? 'author', ['id' => 'u-rol']),
                    'Yetki ayrıntıları: Ayarlar → Roller.', 'u-rol') ?>
            </div>

            <div class="field-row">
                <?= ui_field('Şifre', ui_input('sifre', '', [
                    'type' => 'password', 'id' => 'u-sifre', 'autocomplete' => 'new-password',
                    'minlength' => '10', 'required' => $editing === null,
                ]), $editing !== null ? 'Değiştirmek istemiyorsanız boş bırakın.' : '', 'u-sifre', $editing === null) ?>

                <?= ui_field('Durum', ui_select('durum', ['active' => 'Etkin', 'suspended' => 'Askıya alındı'],
                    $editing?->status ?? 'active', ['id' => 'u-durum']),
                    'Askıya alınan kullanıcı giriş yapamaz.', 'u-durum') ?>
            </div>

            <div class="field-row">
                <?= ui_field('Yazar bağlantısı',
                    '<div class="input-group"><span class="addon">/yazar/</span>'
                    . ui_input('kisa_ad', $editing?->slug ?? '', ['id' => 'u-slug', 'class' => 'input mono'])
                    . '</div>', '', 'u-slug') ?>

                <?= ui_field('Künye',
                    '<textarea class="input" id="u-kunye" name="kunye" rows="3">'
                    . esc_html($editing?->bio ?? '') . '</textarea>',
                    'Yazıların altında yazar kutusunda görünür.', 'u-kunye') ?>
            </div>
        </div>

        <footer class="panel-foot">
            <?php if ($editing !== null) : ?>
                <a class="btn btn-sm" href="users.php">Vazgeç</a>
            <?php endif; ?>
            <span class="spacer"></span>
            <button class="btn btn-sm btn-primary" type="submit" data-primary-save>
                <?= $editing !== null ? 'Güncelle' : 'Kullanıcı ekle' ?>
            </button>
        </footer>
    </form>
</section>

<?php admin_foot(); ?>
