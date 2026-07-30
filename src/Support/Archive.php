<?php

declare(strict_types=1);

namespace HiCMS\Support;

use ZipArchive;

/**
 * ZIP paketleme ve açma.
 *
 * Kurulum, güncelleme, yedekleme ve tema/eklenti kurulumu aynı ZIP mantığını
 * paylaşır. `extract()` yol dışına çıkma (zip slip) girişimlerini reddeder.
 */
final class Archive
{
    public static function supported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * ZIP'i hedef dizine açar.
     *
     * @return array{ok: bool, error?: string, root?: string}
     */
    public static function extract(string $zipFile, string $target): array
    {
        if (!self::supported()) {
            return ['ok' => false, 'error' => 'PHP zip eklentisi etkin değil.'];
        }

        $zip    = new ZipArchive();
        $opened = $zip->open($zipFile);

        if ($opened !== true) {
            return ['ok' => false, 'error' => "ZIP açılamadı (kod {$opened})."];
        }

        if (!Fs::ensureDir($target)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Hedef dizin oluşturulamadı.'];
        }

        // Zip slip denetimi: hiçbir girdi hedef dizinin dışına yazamaz.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')
                || preg_match('#^[a-zA-Z]:#', $name) === 1) {
                $zip->close();
                return ['ok' => false, 'error' => "Güvensiz dosya yolu: {$name}"];
            }
        }

        $root = self::commonRoot($zip);

        if (!$zip->extractTo($target)) {
            $zip->close();
            return ['ok' => false, 'error' => 'Arşiv açılırken hata oluştu.'];
        }

        $zip->close();

        return ['ok' => true, 'root' => $root];
    }

    /**
     * Arşivin tüm girdilerini kapsayan tek üst klasörü döndürür.
     * GitHub'ın ürettiği ZIP'lerde bu "HiCMS-1.0.0/" gibi olur.
     */
    private static function commonRoot(ZipArchive $zip): string
    {
        $root = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $first = explode('/', trim($name, '/'))[0] ?? '';

            if ($first === '') {
                continue;
            }

            if ($root === null) {
                $root = $first;
                continue;
            }

            if ($root !== $first) {
                return '';
            }
        }

        return $root ?? '';
    }

    /**
     * Dizinden ZIP üretir.
     *
     * @param list<string> $skipDirs   Atlanacak klasör adları
     * @param list<string> $skipFiles  Atlanacak dosya adları
     * @return array{ok: bool, error?: string, files?: int}
     */
    public static function create(
        string $sourceDir,
        string $zipFile,
        array $skipDirs = [],
        array $skipFiles = [],
        string $innerPrefix = ''
    ): array {
        if (!self::supported()) {
            return ['ok' => false, 'error' => 'PHP zip eklentisi etkin değil.'];
        }

        if (!Fs::ensureDir(dirname($zipFile))) {
            return ['ok' => false, 'error' => 'ZIP hedef dizini oluşturulamadı.'];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'ZIP oluşturulamadı.'];
        }

        $prefix = $innerPrefix !== '' ? rtrim($innerPrefix, '/') . '/' : '';
        $count  = 0;

        foreach (Fs::listFiles($sourceDir, $skipDirs) as $relative) {
            if (in_array(basename($relative), $skipFiles, true)) {
                continue;
            }

            if ($zip->addFile($sourceDir . '/' . $relative, $prefix . $relative)) {
                $count++;
            }
        }

        $zip->close();

        return ['ok' => true, 'files' => $count];
    }

    /**
     * Tek bir dosyayı arşive ekler (yedeklemede veritabanı dökümü için).
     */
    public static function addString(string $zipFile, string $innerPath, string $contents): bool
    {
        if (!self::supported()) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            return false;
        }

        $ok = $zip->addFromString($innerPath, $contents);
        $zip->close();

        return $ok;
    }
}
