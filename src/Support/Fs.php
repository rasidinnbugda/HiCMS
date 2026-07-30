<?php

declare(strict_types=1);

namespace HiCMS\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Dosya sistemi yardımcıları. Güncelleme ve yedekleme akışları büyük ölçüde
 * buraya dayanır, bu yüzden her yöntem başarısızlığı sessizce yutmaz — false
 * döndürür ve çağıran karar verir.
 */
final class Fs
{
    public static function ensureDir(string $directory, int $mode = 0o755): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, $mode, true) || is_dir($directory);
    }

    public static function isWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return is_writable(dirname($path));
    }

    /**
     * Dizini özyinelemeli siler.
     */
    public static function deleteDir(string $directory): bool
    {
        if (!is_dir($directory)) {
            return true;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());

            if (!$ok) {
                return false;
            }
        }

        return @rmdir($directory);
    }

    /**
     * Dizini özyinelemeli kopyalar.
     *
     * @param list<string> $skip Kök dizine göreli atlanacak yollar
     */
    public static function copyDir(string $source, string $target, array $skip = []): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!self::ensureDir($target)) {
            return false;
        }

        $items = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $name = $item->getFilename();

            if (in_array($name, $skip, true)) {
                continue;
            }

            $destination = $target . '/' . $name;

            if ($item->isDir()) {
                if (!self::copyDir($item->getPathname(), $destination, $skip)) {
                    return false;
                }
                continue;
            }

            if (!@copy($item->getPathname(), $destination)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dizin altındaki tüm dosyaları göreli yollarıyla listeler.
     *
     * @param list<string> $skipDirs
     * @return list<string>
     */
    public static function listFiles(string $directory, array $skipDirs = []): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found  = [];
        $length = strlen(rtrim(str_replace('\\', '/', $directory), '/')) + 1;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr(str_replace('\\', '/', $file->getPathname()), $length);
            $segments = explode('/', $relative);

            foreach ($skipDirs as $skip) {
                if (in_array($skip, $segments, true)) {
                    continue 2;
                }
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }

    public static function dirSize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $total = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    /**
     * Atomik yazma: geçici dosyaya yazıp yerine taşır.
     */
    public static function writeAtomic(string $file, string $contents): bool
    {
        if (!self::ensureDir(dirname($file))) {
            return false;
        }

        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, $contents, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    /**
     * Dizini web erişiminden korur (Apache + boş index).
     */
    public static function protect(string $directory): void
    {
        self::ensureDir($directory);

        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        $index = $directory . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }
}
