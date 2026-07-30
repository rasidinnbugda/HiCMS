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

    /** Kilitli dosya yana alınırken kullanılan uzantı. */
    public const ASIDE_SUFFIX = '.hicms-old';

    /**
     * Bir dosyanın üzerine yazar; dosya kilitliyse önce yana alır.
     *
     * Windows'ta o an çalışmakta olan PHP betiğinin üzerine `copy()` ile
     * yazılamaz. Ancak dosyayı yeniden adlandırmak (dizin girdisini
     * değiştirmek) çoğu durumda mümkündür. Bu yüzden kopyalama başarısız
     * olursa hedef `.hicms-old` olarak yana alınır ve kopyalama yeniden
     * denenir. Yana alınan dosya silinemezse bırakılır; süpürme işi
     * `sweepAside()` ve `core.clean_tmp` görevine kalır.
     */
    public static function replaceFile(string $source, string $target): bool
    {
        if (@copy($source, $target)) {
            return true;
        }

        if (!self::ensureDir(dirname($target))) {
            return false;
        }

        $aside = $target . self::ASIDE_SUFFIX;

        @unlink($aside);

        if (!@rename($target, $aside)) {
            return false;
        }

        if (@copy($source, $target)) {
            @unlink($aside);   // hâlâ kilitliyse kalır, sorun değil

            return true;
        }

        // Kopyalama yine olmadıysa özgün dosyayı yerine geri koy.
        @rename($aside, $target);

        return false;
    }

    /**
     * Hedefi kaynakla eşitler: kaynaktaki her şey üzerine yazılır, kaynakta
     * olmayan girdiler hedeften silinir.
     *
     * Güncellemede `deleteDir()` + `copyDir()` ikilisinin yerini alır. O ikili,
     * silme adımı çalışan betiğe takıldığında dizini yarı boş bırakıp
     * kopyalamayı hiç çalıştırmıyordu; panelden güncelleme yapıldığında
     * `admin/` dizini bu yüzden yok oluyordu. Eşitleme dizini hiçbir anda
     * boşaltmaz.
     *
     * Kaynakta olmayan girdilerin silinmesi "elden geldiğince" yapılır ve
     * dönüş değerini etkilemez — artık bir dosya kalması siteyi bozmaz, ama
     * güncellemeyi başarısız saymak bozar.
     */
    public static function syncDir(string $source, string $target): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!self::ensureDir($target)) {
            return false;
        }

        $expected = [];

        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
            /** @var \SplFileInfo $item */
            $name       = $item->getFilename();
            $expected[] = $name;
            $child      = $target . '/' . $name;

            if ($item->isDir()) {
                // Aynı adda dosya varsa yol açılsın diye kaldırılır.
                if (is_file($child)) {
                    @unlink($child);
                }

                if (!self::syncDir($item->getPathname(), $child)) {
                    return false;
                }

                continue;
            }

            if (is_dir($child) && !self::deleteDir($child)) {
                return false;
            }

            if (!self::replaceFile($item->getPathname(), $child)) {
                return false;
            }
        }

        foreach (new FilesystemIterator($target, FilesystemIterator::SKIP_DOTS) as $item) {
            /** @var \SplFileInfo $item */
            $name = $item->getFilename();

            if (in_array($name, $expected, true) || str_ends_with($name, self::ASIDE_SUFFIX)) {
                continue;
            }

            $item->isDir()
                ? self::deleteDir($item->getPathname())
                : @unlink($item->getPathname());
        }

        return true;
    }

    /**
     * Yana alınmış `.hicms-old` dosyalarını süpürür ve silinen sayısını döndürür.
     */
    public static function sweepAside(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $removed = 0;

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && str_ends_with($item->getFilename(), self::ASIDE_SUFFIX) && @unlink($item->getPathname())) {
                $removed++;
            }
        }

        return $removed;
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
