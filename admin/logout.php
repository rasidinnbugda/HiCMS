<?php

declare(strict_types=1);

/**
 * HiAdmin — Çıkış
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

$app->auth()->logout();

admin_redirect('login.php', 'success', 'Çıkış yapıldı.');
