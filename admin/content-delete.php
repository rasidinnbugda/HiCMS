<?php

declare(strict_types=1);

/**
 * HiAdmin — İçerik silme
 *
 * Bağlantı üzerinden çağrılır ama anahtar doğrulaması yapılır, böylece
 * yalnızca panelden üretilmiş bağlantı çalışır.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('content.delete');

$id = (int) ($_GET['id'] ?? 0);

if (!$app->csrf()->verify((string) ($_GET['_t'] ?? ''))) {
    admin_redirect('content.php', 'error', 'Güvenlik doğrulaması başarısız.');
}

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
