<?php

declare(strict_types=1);

/**
 * HiAdmin — İçerik silme
 *
 * Yalnızca POST ile çalışır ve anahtar doğrulaması `admin_verify()` üzerinden
 * yapılır.
 *
 * 0.2.0'da anahtar sorgu dizesinde (`?_t=`) taşınıyor ve silme bir GET
 * bağlantısıydı: tarayıcı ön-getirmesi, bağlantı önizleyicisi ya da geçmişten
 * yeniden açılan bir sekme içeriği silebiliyordu. Ayrıca CSRF doğrulaması
 * panelin geri kalanından ayrı bir yol izliyordu — `admin_verify()` yalnızca
 * `$_POST['_token']` okuyor, burası `$_GET['_t']` okuyordu; anahtar akışını
 * değiştiren her düzeltme iki yeri birlikte gözetmek zorunda kalıyordu.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('content.delete');

if (!$app->request()->isPost()) {
    admin_redirect('content.php', 'error', 'Silme işlemi yalnızca panelden yapılabilir.');
}

admin_verify('content.php');

$id = (int) ($_POST['id'] ?? 0);

$entry = $id > 0 ? $app->content()->find($id, false) : null;

if ($entry === null) {
    admin_redirect('content.php', 'error', 'İçerik bulunamadı.');
}

if (!$app->auth()->canEdit($entry->authorId)) {
    admin_deny('Bu içeriği silme yetkiniz yok.');
}

$type  = $app->types()->get($entry->type);
$title = $entry->title;

$app->content()->delete($id);

$app->audit()->record(
    action: 'content.delete',
    userId: $app->auth()->id(),
    actor: (string) $app->auth()->user()?->displayName,
    subjectType: $entry->type,
    subjectId: $id,
    summary: Str::format('%s silindi: %s', $type?->singular ?? $entry->type, $title),
    ip: $app->request()->ip(),
);

admin_redirect(
    $type?->adminUrl() ?? 'content.php',
    'success',
    Str::format('"%s" silindi.', Str::limit($title, 60))
);
