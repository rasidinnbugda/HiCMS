<?php

declare(strict_types=1);

namespace HiCMS\Install;

use HiCMS\Kernel;
use HiCMS\Support\Fs;

/**
 * Kurulum ve çalışma zamanı gereksinim denetimi.
 *
 * Aynı liste hem kurulum sihirbazında hem panelin "Sistem Durumu" ekranında
 * kullanılır — iki yerde iki farklı doğru olmasın.
 */
final class Requirements
{
    public const MIN_PHP = '8.2.0';

    /**
     * @return list<array{key: string, label: string, ok: bool, required: bool,
     *                    value: string, hint: string}>
     */
    public static function check(string $rootDir): array
    {
        $checks = [];

        $checks[] = self::row(
            'php',
            'PHP sürümü',
            version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            true,
            PHP_VERSION,
            'HiCMS için PHP ' . self::MIN_PHP . ' veya üstü gerekir.'
        );

        foreach ([
            'pdo_mysql' => 'Veritabanı bağlantısı için zorunlu.',
            'mbstring'  => 'Türkçe karakter işlemleri için zorunlu.',
            'json'      => 'Blok verisi ve ayarlar için zorunlu.',
            'fileinfo'  => 'Güvenli dosya yükleme için zorunlu.',
        ] as $extension => $hint) {
            $checks[] = self::row(
                'ext.' . $extension,
                $extension . ' eklentisi',
                extension_loaded($extension),
                true,
                extension_loaded($extension) ? 'etkin' : 'yok',
                $hint
            );
        }

        foreach ([
            'zip'  => 'Yedekleme, güncelleme ve tema/eklenti kurulumu için gerekir.',
            'gd'   => 'Görsel boyutlandırma (HiMedia) için gerekir.',
            'curl' => 'Güncelleme kontrolü için gerekir; yoksa allow_url_fopen kullanılır.',
            'exif' => 'Görsel yönü düzeltmesi için önerilir.',
            'intl' => 'Çoklu dil sıralaması için önerilir.',
        ] as $extension => $hint) {
            $checks[] = self::row(
                'ext.' . $extension,
                $extension . ' eklentisi',
                extension_loaded($extension),
                false,
                extension_loaded($extension) ? 'etkin' : 'yok',
                $hint
            );
        }

        // Yazma izinleri
        $writable = [
            ''                 => 'config.php buraya yazılacak',
            'content'          => 'Ayarlar, önbellek ve geçici dosyalar',
            'content/uploads'  => 'Medya kitaplığı',
            'content/backups'  => 'Yedekler',
            'themes'           => 'Tema kurulumu ve güncellemesi',
            'plugins'          => 'Eklenti kurulumu ve güncellemesi',
        ];

        foreach ($writable as $relative => $hint) {
            $path = $relative === '' ? $rootDir : $rootDir . '/' . $relative;

            // Henüz oluşmamış klasörleri kurulum oluşturmayı denesin.
            if (!is_dir($path) && $relative !== '') {
                Fs::ensureDir($path);
            }

            $checks[] = self::row(
                'write.' . ($relative !== '' ? $relative : 'root'),
                ($relative !== '' ? $relative . '/' : 'kök dizin') . ' yazılabilir',
                is_dir($path) && is_writable($path),
                $relative === '' || str_starts_with($relative, 'content'),
                is_dir($path) ? (is_writable($path) ? 'yazılabilir' : 'salt okunur') : 'yok',
                $hint
            );
        }

        $memory = self::memoryBytes();

        $checks[] = self::row(
            'memory',
            'Bellek sınırı',
            $memory === 0 || $memory >= 96 * 1024 * 1024,
            false,
            ini_get('memory_limit') ?: 'bilinmiyor',
            'En az 96M önerilir; güncelleme ve yedekleme daha rahat çalışır.'
        );

        $upload = self::iniBytes('upload_max_filesize');

        $checks[] = self::row(
            'upload',
            'Yükleme boyutu sınırı',
            $upload >= 8 * 1024 * 1024,
            false,
            ini_get('upload_max_filesize') ?: 'bilinmiyor',
            'Görsel yüklemek için en az 8M önerilir.'
        );

        return $checks;
    }

    /**
     * Zorunlu maddelerin tamamı sağlanıyor mu?
     *
     * @param list<array{ok: bool, required: bool}> $checks
     */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Panelin sistem durumu ekranı için özet.
     *
     * @return array{ok: int, warn: int, fail: int}
     */
    public static function summary(array $checks): array
    {
        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($checks as $check) {
            if ($check['ok']) {
                $summary['ok']++;
            } elseif ($check['required']) {
                $summary['fail']++;
            } else {
                $summary['warn']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{key: string, label: string, ok: bool, required: bool, value: string, hint: string}
     */
    private static function row(
        string $key,
        string $label,
        bool $ok,
        bool $required,
        string $value,
        string $hint,
    ): array {
        return compact('key', 'label', 'ok', 'required', 'value', 'hint');
    }

    private static function memoryBytes(): int
    {
        $limit = (string) ini_get('memory_limit');

        return $limit === '-1' ? 0 : self::toBytes($limit);
    }

    private static function iniBytes(string $key): int
    {
        return self::toBytes((string) ini_get($key));
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * Çalışan kurulum hakkında bilgi (panelde gösterilir).
     *
     * @return array<string, string>
     */
    public static function environment(Kernel $app): array
    {
        $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'bilinmiyor');

        $info = [
            'HiCMS'        => Kernel::VERSION,
            'PHP'          => PHP_VERSION,
            'Sunucu'       => $server,
            'İşletim'      => PHP_OS_FAMILY,
            'Bellek'       => (string) (ini_get('memory_limit') ?: '-'),
            'Yükleme'      => (string) (ini_get('upload_max_filesize') ?: '-'),
            'Saat dilimi'  => date_default_timezone_get(),
        ];

        try {
            $info['Veritabanı'] = 'MySQL ' . $app->db()->serverVersion();
        } catch (\Throwable) {
            $info['Veritabanı'] = 'bağlanılamadı';
        }

        return $info;
    }
}
