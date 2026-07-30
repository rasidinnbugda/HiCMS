<?php

declare(strict_types=1);

/**
 * HiCMS — sözdizimi ve yükleme denetleyicisi
 *
 * Kullanım (proje kökünden):
 *   php build/lint.php            → tüm PHP dosyalarını denetler
 *   php build/lint.php src        → yalnızca verilen dizini denetler
 *
 * İki aşama uygular:
 *   1. Her dosyada `php -l` eşdeğeri sözdizimi denetimi
 *   2. src/ altındaki her sınıfın autoloader ile gerçekten yüklenebilmesi
 */

$root = dirname(__DIR__);
$only = $argv[1] ?? '';

$skipDirs = ['.git', 'content', 'node_modules', 'vendor', 'build/tmp', 'HiAdmin'];

/** @return list<string> */
function collect(string $directory, array $skipDirs, string $root): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path     = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

        foreach ($skipDirs as $skip) {
            if (str_starts_with($relative, $skip . '/') || str_contains($relative, '/' . $skip . '/')) {
                continue 2;
            }
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

$target = $only !== '' ? $root . '/' . trim($only, '/') : $root;
$files  = collect($target, $skipDirs, $root);

echo "HiCMS lint — " . count($files) . " dosya\n";
echo str_repeat('-', 62) . "\n";

$errors = [];
$binary = PHP_BINARY;

foreach ($files as $file) {
    $output = [];
    $status = 0;

    exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', str_replace('\\', '/', $file)), '/');
        $errors[] = $relative . "\n    " . trim(implode("\n    ", $output));
    }
}

if ($errors !== []) {
    echo "SOZDIZIMI HATALARI (" . count($errors) . "):\n\n";
    foreach ($errors as $error) {
        echo '  ' . $error . "\n\n";
    }
    exit(1);
}

echo "Sozdizimi: tum dosyalar temiz.\n";

/* ---------------------------------------------------------------------------
 * 2. aşama: sınıfların yüklenebilirliği
 * ------------------------------------------------------------------------ */

require $root . '/src/Autoloader.php';
HiCMS\Autoloader::register($root . '/src');

$classFiles = collect($root . '/src', array_merge($skipDirs, ['migrations']), $root);
$failed     = [];
$checked    = 0;

foreach ($classFiles as $file) {
    $relative = ltrim(str_replace(str_replace('\\', '/', $root) . '/src', '', str_replace('\\', '/', $file)), '/');

    // functions.php gibi sınıf içermeyen dosyaları atla.
    if (!preg_match('/^[A-Z]/', basename($relative))) {
        continue;
    }

    $class = 'HiCMS\\' . str_replace('/', '\\', substr($relative, 0, -4));
    $checked++;

    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
        $failed[] = $class;
    }
}

echo "Yuklenebilirlik: {$checked} sinif denetlendi.\n";

if ($failed !== []) {
    echo "\nYUKLENEMEYEN SINIFLAR (" . count($failed) . "):\n";
    foreach ($failed as $class) {
        echo '  - ' . $class . "\n";
    }
    exit(1);
}

echo "Tum siniflar autoloader ile yuklendi.\n";
exit(0);
