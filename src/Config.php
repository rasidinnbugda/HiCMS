<?php

declare(strict_types=1);

namespace HiCMS;

/**
 * Yapılandırma okuyucu.
 *
 * `config.php` bir dizi döndürür; değerlere noktalı yolla erişilir:
 *   $config->get('db.host')
 *
 * Ayarlar (options) veritabanında tutulur — burası yalnızca kurulum sırasında
 * belirlenen, veritabanına yazılamayacak değerleri taşır.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /**
     * Dosyadan yükler. Dosya yoksa boş yapılandırma döner.
     */
    public static function fromFile(string $file): self
    {
        if (!is_readable($file)) {
            return new self();
        }

        $values = require $file;

        return new self(is_array($values) ? $values : []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = $this->values;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target   = &$this->values;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__hicms_missing__') !== '__hicms_missing__';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Yapılandırmayı PHP dosyası olarak yazar (kurulum sihirbazı kullanır).
     */
    public function writeTo(string $file): bool
    {
        $export = var_export($this->values, true);

        $contents = "<?php\n\n"
            . "/**\n"
            . " * HiCMS yapılandırması — kurulum sihirbazı tarafından üretildi.\n"
            . " *\n"
            . " * Bu dosya güncellemelerde korunur ve sürüm kontrolüne dahil edilmez.\n"
            . " */\n\n"
            . "return {$export};\n";

        return (bool) @file_put_contents($file, $contents, LOCK_EX);
    }
}
