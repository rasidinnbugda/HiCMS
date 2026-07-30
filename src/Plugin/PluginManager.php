<?php

declare(strict_types=1);

namespace HiCMS\Plugin;

use HiCMS\Autoloader;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Migrator;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Extension\Toggled;
use HiCMS\Events\System\PluginsLoaded;
use HiCMS\Extension\Manifest;
use HiCMS\I18n\Translator;
use HiCMS\Kernel;
use HiCMS\Repository\OptionRepository;
use HiCMS\Support\Fs;
use Throwable;

/**
 * Eklenti keşfi, etkinleştirme ve yükleme.
 *
 * Bir eklenti etkinleştirildiğinde sırasıyla: uyumluluk denetlenir, kendi
 * migration'ları çalıştırılır, `activate()` kancası tetiklenir ve etkin listeye
 * yazılır. Yükleme sırasında oluşan hata tüm siteyi çökertmez — eklenti otomatik
 * devre dışı bırakılır ve panelde bildirilir.
 */
final class PluginManager
{
    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;

    /** @var array<string, Plugin> */
    private array $instances = [];

    /** @var array<string, string> Yükleme sırasında hata veren eklentiler */
    private array $failed = [];

    public function __construct(
        private readonly string $pluginsDir,
        private readonly OptionRepository $options,
        private readonly Dispatcher $events,
        private readonly Translator $translator,
        private readonly TypeRegistry $types,
        private readonly Migrator $migrator,
        private readonly Kernel $app,
        private readonly string $coreVersion,
    ) {
    }

    /**
     * @return array<string, Manifest>
     */
    public function available(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $plugins = [];

        foreach (glob($this->pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $manifest = Manifest::fromDirectory($directory, 'plugin');

            if (!$manifest->valid && !is_readable($directory . '/hicms.json')) {
                continue;
            }

            $plugins[basename($directory)] = $manifest;
        }

        ksort($plugins);

        return $this->discovered = $plugins;
    }

    /** @return list<string> */
    public function activeSlugs(): array
    {
        $stored = $this->options->get('active_plugins', []);

        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $stored)));
    }

    public function isActive(string $slug): bool
    {
        return in_array($slug, $this->activeSlugs(), true);
    }

    public function get(string $slug): ?Manifest
    {
        return $this->available()[$slug] ?? null;
    }

    /** @return array<string, Plugin> */
    public function instances(): array
    {
        return $this->instances;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failed;
    }

    /**
     * Etkin eklentileri yükler.
     */
    public function loadActive(): void
    {
        $available = $this->available();
        $active    = $this->activeSlugs();
        $loaded    = [];

        foreach ($active as $slug) {
            $manifest = $available[$slug] ?? null;

            if ($manifest === null || !$manifest->valid) {
                $this->failed[$slug] = $manifest?->error ?? 'Eklenti klasörü bulunamadı.';
                continue;
            }

            try {
                $this->bootPlugin($manifest);
                $loaded[] = $slug;
            } catch (Throwable $exception) {
                // Tek bir eklenti tüm siteyi düşürmesin.
                $this->failed[$slug] = $exception->getMessage();
                $this->deactivate($slug, false);
            }
        }

        $this->events->dispatch(new PluginsLoaded($loaded));
    }

    private function bootPlugin(Manifest $manifest): void
    {
        if ($manifest->namespace !== '' && $manifest->autoloadDirectory() !== '') {
            Autoloader::instance()->addNamespace($manifest->namespace, $manifest->autoloadDirectory());
        }

        if (is_dir($manifest->languagesDirectory())) {
            $this->translator->loadDirectory($manifest->languagesDirectory());
        }

        if (is_dir($manifest->contentTypesDirectory())) {
            $this->types->loadDirectory($manifest->contentTypesDirectory(), 'plugin:' . $manifest->slug);
        }

        if (is_dir($manifest->migrationsDirectory())) {
            $this->migrator->addSource('plugin:' . $manifest->slug, $manifest->migrationsDirectory());
        }

        if ($manifest->entryClass !== '') {
            /** @var class-string<Plugin> $class */
            $class = $manifest->entryClass;

            if (!class_exists($class)) {
                // Sınıf autoload ile bulunamadıysa ana dosya onu tanımlıyor olabilir.
                if ($manifest->hasMainFile()) {
                    require_once $manifest->mainFile();
                }
            }

            if (!class_exists($class) || !is_subclass_of($class, Plugin::class)) {
                throw new \RuntimeException("Eklenti sınıfı bulunamadı: {$class}");
            }

            $plugin = new $class($this->app, $manifest);
            $plugin->boot();

            $this->instances[$manifest->slug] = $plugin;

            return;
        }

        if (!$manifest->hasMainFile()) {
            throw new \RuntimeException("Ana dosya bulunamadı: {$manifest->main}");
        }

        require_once $manifest->mainFile();
    }

    /**
     * Eklentiyi etkinleştirir.
     *
     * @return array{ok: bool, error: string, migrations: list<string>}
     */
    public function activate(string $slug): array
    {
        $manifest = $this->get($slug);

        if ($manifest === null) {
            return ['ok' => false, 'error' => 'Eklenti bulunamadı.', 'migrations' => []];
        }

        if (!$manifest->valid) {
            return ['ok' => false, 'error' => $manifest->error, 'migrations' => []];
        }

        $compatibility = $manifest->checkCompatibility($this->coreVersion);

        if (!$compatibility['ok']) {
            return ['ok' => false, 'error' => $compatibility['error'], 'migrations' => []];
        }

        if ($this->isActive($slug)) {
            return ['ok' => true, 'error' => '', 'migrations' => []];
        }

        $migrations = [];

        try {
            $this->bootPlugin($manifest);

            // Eklentinin kendi tablolarını kur.
            if (is_dir($manifest->migrationsDirectory())) {
                $result = $this->migrator->migrate();

                if (!$result['ok']) {
                    return ['ok' => false, 'error' => $result['error'], 'migrations' => $result['applied']];
                }

                $migrations = $result['applied'];
            }

            ($this->instances[$slug] ?? null)?->activate();
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'migrations' => $migrations];
        }

        $active   = $this->activeSlugs();
        $active[] = $slug;

        $this->options->set('active_plugins', array_values(array_unique($active)));

        $this->events->dispatch(new Toggled('plugin', $slug, true));

        return ['ok' => true, 'error' => '', 'migrations' => $migrations];
    }

    /**
     * Eklentiyi devre dışı bırakır.
     *
     * @return array{ok: bool, error: string}
     */
    public function deactivate(string $slug, bool $runHook = true): array
    {
        if (!$this->isActive($slug)) {
            return ['ok' => true, 'error' => ''];
        }

        if ($runHook && isset($this->instances[$slug])) {
            try {
                $this->instances[$slug]->deactivate();
            } catch (Throwable $exception) {
                // Devre dışı bırakma her koşulda tamamlanmalı.
                $this->failed[$slug] = $exception->getMessage();
            }
        }

        $active = array_values(array_diff($this->activeSlugs(), [$slug]));
        $this->options->set('active_plugins', $active);

        $this->types->forgetSource('plugin:' . $slug);
        unset($this->instances[$slug]);

        $this->events->dispatch(new Toggled('plugin', $slug, false));

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Eklentiyi kaldırır: devre dışı bırakır, tablolarını geri alır, klasörünü siler.
     *
     * @return array{ok: bool, error: string}
     */
    public function delete(string $slug): array
    {
        $manifest = $this->get($slug);

        if ($manifest === null) {
            return ['ok' => false, 'error' => 'Eklenti bulunamadı.'];
        }

        if ($this->isActive($slug)) {
            $instance = $this->instances[$slug] ?? null;

            $this->deactivate($slug);

            if ($instance !== null) {
                try {
                    $instance->uninstall();
                } catch (Throwable) {
                    // Kaldırma kancası hata verse de dosya silme sürsün.
                }
            }
        }

        if (is_dir($manifest->migrationsDirectory())) {
            $this->migrator->addSource('plugin:' . $slug, $manifest->migrationsDirectory());
            $this->migrator->rollbackSource('plugin:' . $slug);
        }

        if (!Fs::deleteDir($manifest->directory)) {
            return ['ok' => false, 'error' => 'Eklenti klasörü silinemedi. Dosya izinlerini kontrol edin.'];
        }

        $this->discovered = null;

        return ['ok' => true, 'error' => ''];
    }

    public function pluginsDir(): string
    {
        return $this->pluginsDir;
    }

    public function flush(): void
    {
        $this->discovered = null;
    }

    /**
     * Kendini güncelleyebilen (deposu bildirilmiş) eklentiler.
     *
     * @return array<string, Manifest>
     */
    public function updatable(): array
    {
        return array_filter($this->available(), static fn(Manifest $m): bool => $m->canSelfUpdate());
    }
}
