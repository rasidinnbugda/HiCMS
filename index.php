<?php

declare(strict_types=1);

/**
 * HiCMS — Ön yüz giriş noktası
 *
 * Statik dosyalar dışındaki tüm ziyaretçi istekleri buraya gelir.
 *
 * @package HiCMS
 */

use HiCMS\Kernel;
use HiCMS\Routing\FrontController;

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    exit('HiCMS için PHP 8.2 veya üstü gerekir. Kurulu sürüm: ' . PHP_VERSION);
}

// Kurulum yapılmadıysa sihirbaza yönlendir.
if (!is_readable(__DIR__ . '/config.php')) {
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    header('Location: ' . $base . '/install.php', true, 302);
    exit;
}

require __DIR__ . '/src/Kernel.php';

$app = Kernel::boot(__DIR__);

$app->auth()->startSession();

$response = (new FrontController($app))->handle($app->request());

$response->send();

// Yanıt gönderildikten sonra: süresi gelmiş bir planlı görevi çalıştır.
$app->scheduler()->tick();
