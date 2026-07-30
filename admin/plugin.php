<?php

declare(strict_types=1);

/**
 * HiAdmin — Eklenti sayfası yönlendiricisi
 *
 * Eklentiler panele dosya kopyalamaz; kendi ekranlarını bir kancaya bağlar:
 *
 *     hi_listen(MenuBuilding::class, fn($e) => $e->add('content', [
 *         'slug'  => 'plugin:hi-forms',
 *         'label' => 'Formlar',
 *         'icon'  => 'inbox',
 *         'url'   => 'plugin.php?eklenti=hi-forms',
 *     ]));
 *
 *     hi_on('admin.page.hi-forms', function (): void {
 *         admin_head(['title' => 'Formlar', 'slug' => 'plugin:hi-forms']);
 *         // … ekran …
 *         admin_foot();
 *     });
 *
 * Böylece çekirdek güncellendiğinde eklenti ekranları etkilenmez.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

$slug = (string) ($_GET['eklenti'] ?? '');

if ($slug === '' || !$app->plugins()->isActive($slug)) {
    admin_redirect('plugins.php', 'error', 'Eklenti bulunamadı veya etkin değil.');
}

$hook = 'admin.page.' . $slug;

if (!$app->events()->hasListeners($hook)) {
    $manifest = $app->plugins()->get($slug);

    admin_head([
        'title' => $manifest?->name ?? $slug,
        'slug'  => 'plugin:' . $slug,
    ]);

    echo ui_notice('info', 'Bu eklenti bir panel ekranı sağlamıyor.');

    admin_foot();
    exit;
}

// Ekranın tamamını eklenti basar; başlık ve alt bilgi onun sorumluluğunda.
$app->events()->emit($hook, $app);
