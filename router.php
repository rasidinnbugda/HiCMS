<?php

declare(strict_types=1);

/**
 * HiCMS — PHP yerleşik sunucusu yönlendiricisi
 *
 * Apache'nin .htaccess kurallarını geliştirme ortamında taklit eder.
 *
 *     php -S localhost:8000 router.php
 *
 * Ön yüz : http://localhost:8000
 * Kurulum: http://localhost:8000/install.php
 * Panel  : http://localhost:8000/admin/
 *
 * @package HiCMS
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

// Yapılandırma ve içerik verilerine doğrudan erişimi engelle.
if (preg_match('#^/(config\.php|content/(backups|tmp|cache)/)#', $path) === 1) {
    http_response_code(403);
    exit('403 — Erişim engellendi.');
}

// Var olan dosyayı sunucu kendisi servis etsin.
if ($path !== '/' && is_file($file)) {
    return false;
}

// Dizin istekleri: sonda eğik çizgi yoksa ekle, sonra index.php'yi çalıştır.
if (is_dir($file)) {
    if (!str_ends_with($path, '/')) {
        header('Location: ' . $path . '/', true, 301);
        return true;
    }

    $index = rtrim($file, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';

    if (is_file($index)) {
        require $index;
        return true;
    }
}

require __DIR__ . '/index.php';

return true;
