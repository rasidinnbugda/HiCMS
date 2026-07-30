<?php

declare(strict_types=1);

namespace HiCMS;

/**
 * PSR-4 uyumlu, bağımlılıksız sınıf yükleyici.
 *
 * `HiCMS\Database\Connection` → `src/Database/Connection.php`
 *
 * Eklentiler kendi namespace'lerini `addNamespace()` ile kaydeder:
 *   Autoloader::instance()->addNamespace('HiSEO\\', __DIR__ . '/src');
 */
final class Autoloader
{
    private static ?self $instance = null;

    /** @var array<string, list<string>> namespace öneki → dizin listesi */
    private array $prefixes = [];

    /** @var array<string, string> tam sınıf adı → dosya yolu */
    private array $classMap = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Çekirdeği kaydeder ve yükleyiciyi devreye alır.
     */
    public static function register(string $srcDir): self
    {
        $loader = self::instance();
        $loader->addNamespace('HiCMS\\', $srcDir);

        spl_autoload_register([$loader, 'load'], true, true);

        return $loader;
    }

    /**
     * Bir namespace önekini bir dizine bağlar.
     */
    public function addNamespace(string $prefix, string $directory): void
    {
        $prefix    = trim($prefix, '\\') . '\\';
        $directory = rtrim(str_replace('\\', '/', $directory), '/');

        $this->prefixes[$prefix] ??= [];

        if (!in_array($directory, $this->prefixes[$prefix], true)) {
            $this->prefixes[$prefix][] = $directory;
        }
    }

    /**
     * Tek bir sınıfı doğrudan bir dosyaya bağlar (namespace kuralına uymayanlar için).
     */
    public function addClass(string $class, string $file): void
    {
        $this->classMap[trim($class, '\\')] = $file;
    }

    /**
     * Sınıfı yükler.
     */
    public function load(string $class): bool
    {
        $class = trim($class, '\\');

        if (isset($this->classMap[$class])) {
            return $this->requireFile($this->classMap[$class]);
        }

        foreach ($this->prefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $relative = str_replace('\\', '/', $relative) . '.php';

            foreach ($directories as $directory) {
                if ($this->requireFile($directory . '/' . $relative)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function requireFile(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }

        require $file;

        return true;
    }
}
