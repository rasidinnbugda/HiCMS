<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use HiCMS\Autoloader;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Migrator;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Extension\Toggled;
use HiCMS\Events\System\ThemeLoaded;
use HiCMS\Extension\Manifest;
use HiCMS\Http\Url;
use HiCMS\I18n\Translator;
use HiCMS\Repository\OptionRepository;

/**
 * Tema keşfi ve yükleme.
 *
 * Bir tema klasörü şu şekilde tanınır: `hicms.json` + `index.php`. Tema
 * yüklenirken sırasıyla namespace'i kaydedilir, dil dosyaları okunur, içerik
 * türü tanımları alınır ve `functions.php` çalıştırılır.
 */
final class ThemeManager
{
    private ?Manifest $active = null;

    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;

    public function __construct(
        private readonly string $themesDir,
        private readonly OptionRepository $options,
        private readonly Dispatcher $events,
        private readonly Translator $translator,
        private readonly TypeRegistry $types,
        private readonly Url $url,
        private readonly Migrator $migrator,
        private readonly string $coreVersion,
    ) {
    }

    /**
     * Kurulu tüm temalar.
     *
     * @return array<string, Manifest>
     */
    public function available(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $themes = [];

        foreach (glob($this->themesDir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (!is_readable($directory . '/index.php')) {
                continue;
            }

            $manifest = Manifest::fromDirectory($directory, 'theme');
            $themes[basename($directory)] = $manifest;
        }

        ksort($themes);

        return $this->discovered = $themes;
    }

    public function activeSlug(): string
    {
        $slug   = (string) $this->options->get('active_theme', 'hiblog');
        $themes = $this->available();

        if (isset($themes[$slug]) && $themes[$slug]->valid) {
            return $slug;
        }

        // Etkin tema silinmiş veya bozuk: geçerli olan ilk temaya düş.
        foreach ($themes as $name => $manifest) {
            if ($manifest->valid) {
                return $name;
            }
        }

        return $slug;
    }

    public function active(): ?Manifest
    {
        return $this->active ??= $this->available()[$this->activeSlug()] ?? null;
    }

    public function directory(string $path = ''): string
    {
        $base = $this->themesDir . '/' . $this->activeSlug();

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    public function url(string $path = ''): string
    {
        return $this->url->theme($this->activeSlug(), $path);
    }

    /**
     * Temayı yükler: namespace, diller, içerik türleri, functions.php.
     */
    public function loadActive(): void
    {
        $manifest = $this->active();

        if ($manifest === null || !$manifest->valid) {
            return;
        }

        if ($manifest->namespace !== '' && $manifest->autoloadDirectory() !== '') {
            Autoloader::instance()->addNamespace($manifest->namespace, $manifest->autoloadDirectory());
        }

        if (is_dir($manifest->languagesDirectory())) {
            $this->translator->loadDirectory($manifest->languagesDirectory());
        }

        if (is_dir($manifest->contentTypesDirectory())) {
            $this->types->loadDirectory($manifest->contentTypesDirectory(), 'theme:' . $manifest->slug);
        }

        if (is_dir($manifest->migrationsDirectory())) {
            $this->migrator->addSource('theme:' . $manifest->slug, $manifest->migrationsDirectory());
        }

        $functions = $manifest->directory . '/functions.php';

        if (is_readable($functions)) {
            require_once $functions;
        }

        $this->events->dispatch(new ThemeLoaded($manifest->slug, $manifest));
    }

    /**
     * Temayı etkinleştirir.
     *
     * @return array{ok: bool, error: string}
     */
    public function activate(string $slug): array
    {
        $themes = $this->available();

        if (!isset($themes[$slug])) {
            return ['ok' => false, 'error' => 'Tema bulunamadı.'];
        }

        $manifest = $themes[$slug];

        if (!$manifest->valid) {
            return ['ok' => false, 'error' => $manifest->error];
        }

        $compatibility = $manifest->checkCompatibility($this->coreVersion);

        if (!$compatibility['ok']) {
            return ['ok' => false, 'error' => $compatibility['error']];
        }

        $previous = $this->activeSlug();

        if ($previous === $slug) {
            return ['ok' => true, 'error' => ''];
        }

        $this->options->set('active_theme', $slug);
        $this->active = $manifest;

        $this->events->dispatch(new Toggled('theme', $slug, true, $previous));

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Temayı klasörüyle birlikte siler. Etkin tema silinemez.
     *
     * @return array{ok: bool, error: string}
     */
    public function canDelete(string $slug): array
    {
        if ($slug === $this->activeSlug()) {
            return ['ok' => false, 'error' => 'Etkin tema silinemez. Önce başka bir temayı etkinleştirin.'];
        }

        if (!isset($this->available()[$slug])) {
            return ['ok' => false, 'error' => 'Tema bulunamadı.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    public function themesDir(): string
    {
        return $this->themesDir;
    }

    public function flush(): void
    {
        $this->discovered = null;
        $this->active     = null;
    }

    /**
     * Temanın bildirdiği destekler (`hi_theme_support()` ile eklenir).
     *
     * @var array<string, mixed>
     */
    private array $supports = [];

    public function addSupport(string $feature, mixed $value = true): void
    {
        $this->supports[$feature] = $value;
    }

    public function supports(string $feature): bool
    {
        return !empty($this->supports[$feature]);
    }

    public function support(string $feature): mixed
    {
        return $this->supports[$feature] ?? null;
    }

    /** @return array<string, mixed> */
    public function allSupports(): array
    {
        return $this->supports;
    }
}
