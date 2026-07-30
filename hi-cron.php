<?php

declare(strict_types=1);

/**
 * HiCMS — Planlı görev işleyici
 *
 * İstek tabanlı zamanlayıcı her ziyarette en fazla bir görev çalıştırır. Trafiği
 * düşük siteler ya da tam kapasite işletim için gerçek cron kullanılabilir:
 *
 *     * / 5 * * * *  php /yol/hi-cron.php
 *
 * Komut satırından çalıştırıldığında anahtar gerekmez. HTTP üzerinden
 * çağrılacaksa `cron_key` ayarındaki anahtar sorgu dizesinde verilmelidir:
 *
 *     https://site.test/hi-cron.php?key=…
 *
 * @package HiCMS
 */

use HiCMS\Kernel;

$isCli = PHP_SAPI === 'cli';

if (!is_readable(__DIR__ . '/config.php')) {
    if (!$isCli) {
        http_response_code(503);
    }

    exit("HiCMS kurulu değil.\n");
}

require __DIR__ . '/src/Kernel.php';

$app = Kernel::boot(__DIR__);

if (!$isCli) {
    $expected = (string) $app->options()->get('cron_key', '');
    $provided = (string) ($_GET['key'] ?? '');

    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit("Geçersiz anahtar.\n");
    }

    header('Content-Type: text/plain; charset=UTF-8');
}

$limit  = max(1, (int) ($argv[1] ?? $_GET['limit'] ?? 10));
$result = $app->scheduler()->run($limit);

printf("Çalıştırılan görev: %d\n", $result['ran']);

foreach ($result['names'] as $name) {
    printf("  ✓ %s\n", $name);
}

foreach ($result['errors'] as $error) {
    printf("  ! %s\n", $error);
}

if ($result['ran'] === 0 && $result['errors'] === []) {
    echo "Süresi gelmiş görev yok.\n";
}
