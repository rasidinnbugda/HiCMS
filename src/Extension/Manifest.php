<?php

declare(strict_types=1);

namespace HiCMS\Extension;

/**
 * Tema ve eklenti künyesi (`hicms.json`).
 *
 * Tek bir künye biçimi hem temalar hem eklentiler için kullanılır; güncelleme
 * mekanizması da bu yüzden ortaktır. WordPress'in dosya başındaki yorum bloğunu
 * ayrıştırma alışkanlığı yerine açık bir JSON dosyası tercih edilmiştir:
 * makine okunur, doğrulanabilir ve sürüm kontrolünde okunaklı.
 *
 * Örnek — `plugins/hi-seo/hicms.json`:
 *
 *     {
 *       "name": "HiSEO",
 *       "slug": "hi-seo",
 *       "version": "1.0.0",
 *       "type": "plugin",
 *       "description": "Sitemap, yapısal veri, paylaşım görseli ve 301 yönetimi.",
 *       "author": "HiCMS",
 *       "repository": "rasidinnbugda/hi-seo",
 *       "requires": { "hicms": "0.2.0", "php": "8.2" },
 *       "namespace": "HiSEO\\",
 *       "autoload": "src",
 *       "main": "plugin.php"
 *     }
 */
final class Manifest
{
    /**
     * @param list<string> $tags
     * @param array<string, string> $requires
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $type,
        public readonly string $description = '',
        public readonly string $author = '',
        public readonly string $authorUrl = '',
        public readonly string $repository = '',
        public readonly string $license = '',
        public readonly array $requires = [],
        public readonly array $tags = [],
        public readonly string $screenshot = 'screenshot.svg',
        public readonly string $namespace = '',
        public readonly string $autoload = '',
        public readonly string $main = '',
        public readonly string $entryClass = '',
        public readonly string $directory = '',
        public readonly bool $valid = true,
        public readonly string $error = '',
    ) {
    }

    /**
     * Dizindeki `hicms.json` dosyasını okur.
     */
    public static function fromDirectory(string $directory, string $type): self
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $slug      = basename($directory);
        $file      = $directory . '/hicms.json';

        if (!is_readable($file)) {
            return self::invalid($slug, $type, $directory, 'hicms.json bulunamadı.');
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            return self::invalid($slug, $type, $directory, 'hicms.json okunamadı (geçersiz JSON).');
        }

        $version = (string) ($decoded['version'] ?? '');

        if ($version === '' || preg_match('/^\d+\.\d+(\.\d+)?/', $version) !== 1) {
            return self::invalid($slug, $type, $directory, 'Sürüm numarası eksik veya geçersiz.');
        }

        $main = (string) ($decoded['main'] ?? ($type === 'plugin' ? 'plugin.php' : 'functions.php'));

        return new self(
            slug: (string) ($decoded['slug'] ?? $slug),
            name: (string) ($decoded['name'] ?? $slug),
            version: $version,
            type: (string) ($decoded['type'] ?? $type),
            description: (string) ($decoded['description'] ?? ''),
            author: (string) ($decoded['author'] ?? ''),
            authorUrl: (string) ($decoded['author_url'] ?? ''),
            repository: self::normalizeRepository((string) ($decoded['repository'] ?? '')),
            license: (string) ($decoded['license'] ?? ''),
            requires: array_map('strval', (array) ($decoded['requires'] ?? [])),
            tags: array_values(array_map('strval', (array) ($decoded['tags'] ?? []))),
            screenshot: (string) ($decoded['screenshot'] ?? 'screenshot.svg'),
            namespace: (string) ($decoded['namespace'] ?? ''),
            autoload: (string) ($decoded['autoload'] ?? ''),
            main: $main,
            entryClass: (string) ($decoded['class'] ?? ''),
            directory: $directory,
        );
    }

    private static function invalid(string $slug, string $type, string $directory, string $error): self
    {
        return new self(
            slug: $slug,
            name: $slug,
            version: '0.0.0',
            type: $type,
            directory: $directory,
            valid: false,
            error: $error,
        );
    }

    /**
     * "https://github.com/sahip/depo" → "sahip/depo"
     */
    private static function normalizeRepository(string $repository): string
    {
        $repository = trim($repository);

        if ($repository === '') {
            return '';
        }

        if (preg_match('#github\.com/([^/]+/[^/.]+)#i', $repository, $match) === 1) {
            return $match[1];
        }

        return preg_match('#^[\w.-]+/[\w.-]+$#', $repository) === 1 ? $repository : '';
    }

    public function mainFile(): string
    {
        return $this->directory . '/' . ltrim($this->main, '/');
    }

    public function hasMainFile(): bool
    {
        return is_readable($this->mainFile());
    }

    public function screenshotPath(): string
    {
        return $this->directory . '/' . ltrim($this->screenshot, '/');
    }

    public function hasScreenshot(): bool
    {
        return is_readable($this->screenshotPath());
    }

    public function autoloadDirectory(): string
    {
        return $this->autoload !== '' ? $this->directory . '/' . trim($this->autoload, '/') : '';
    }

    public function languagesDirectory(): string
    {
        return $this->directory . '/languages';
    }

    public function migrationsDirectory(): string
    {
        return $this->directory . '/migrations';
    }

    public function contentTypesDirectory(): string
    {
        return $this->directory . '/content-types';
    }

    public function requiresCore(): string
    {
        return $this->requires['hicms'] ?? '';
    }

    public function requiresPhp(): string
    {
        return $this->requires['php'] ?? '';
    }

    /**
     * Kurulu HiCMS ve PHP sürümüyle uyumlu mu?
     *
     * @return array{ok: bool, error: string}
     */
    public function checkCompatibility(string $coreVersion): array
    {
        $needsCore = $this->requiresCore();

        if ($needsCore !== '' && version_compare($coreVersion, $needsCore, '<')) {
            return [
                'ok'    => false,
                'error' => sprintf('HiCMS %s veya üstü gerekiyor (kurulu: %s).', $needsCore, $coreVersion),
            ];
        }

        $needsPhp = $this->requiresPhp();

        if ($needsPhp !== '' && version_compare(PHP_VERSION, $needsPhp, '<')) {
            return [
                'ok'    => false,
                'error' => sprintf('PHP %s veya üstü gerekiyor (kurulu: %s).', $needsPhp, PHP_VERSION),
            ];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Bu paketin ihtiyaç duyduğu DİĞER eklentiler.
     *
     * `requires` içindeki `hicms` ve `php` dışındaki her anahtar bir eklenti
     * kısa adı sayılır:
     *
     *     "requires": { "hicms": "0.3.0", "php": "8.2", "hi-types": "1.0.0" }
     *
     * @return array<string, string> kısa ad → en az sürüm
     */
    public function requiredPlugins(): array
    {
        $needs = $this->requires;

        unset($needs['hicms'], $needs['php']);

        return $needs;
    }

    /**
     * Bağımlı olunan eklentiler etkin ve yeterli sürümde mi?
     *
     * @param array<string, string> $activeVersions kısa ad → kurulu sürüm
     * @return array{ok: bool, error: string, missing: list<string>}
     */
    public function checkDependencies(array $activeVersions): array
    {
        $missing = [];

        foreach ($this->requiredPlugins() as $slug => $minimum) {
            if (!isset($activeVersions[$slug])) {
                $missing[] = $slug . ($minimum !== '' ? ' (' . $minimum . '+)' : '');
                continue;
            }

            if ($minimum !== '' && version_compare($activeVersions[$slug], $minimum, '<')) {
                $missing[] = sprintf('%s %s+ (etkin: %s)', $slug, $minimum, $activeVersions[$slug]);
            }
        }

        if ($missing !== []) {
            return [
                'ok'      => false,
                'error'   => 'Şu eklentiler etkin ve güncel olmalı: ' . implode(', ', $missing) . '.',
                'missing' => $missing,
            ];
        }

        return ['ok' => true, 'error' => '', 'missing' => []];
    }

    public function canSelfUpdate(): bool
    {
        return $this->repository !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug'        => $this->slug,
            'name'        => $this->name,
            'version'     => $this->version,
            'type'        => $this->type,
            'description' => $this->description,
            'author'      => $this->author,
            'repository'  => $this->repository,
            'tags'        => $this->tags,
        ];
    }
}
