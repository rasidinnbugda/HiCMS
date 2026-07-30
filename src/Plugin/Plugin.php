<?php

declare(strict_types=1);

namespace HiCMS\Plugin;

use HiCMS\Extension\Manifest;
use HiCMS\Kernel;

/**
 * Eklenti taban sınıfı.
 *
 * İki yazım biçimi desteklenir:
 *
 *  1. **Sınıf tabanlı** (önerilen) — künyede `"class": "HiSEO\\Plugin"` belirtilir.
 *     Yükleyici sınıfı kurar ve `boot()` çağırır. Etkinleştirme/devre dışı
 *     bırakma kancaları da burada tanımlanır.
 *
 *  2. **Dosya tabanlı** — `plugin.php` doğrudan çalıştırılır ve küresel yardımcı
 *     fonksiyonlarla (`hi_listen()`, `hi_on()`) kancalara bağlanır. Küçük
 *     eklentiler için pratiktir.
 */
abstract class Plugin
{
    public function __construct(
        protected readonly Kernel $app,
        protected readonly Manifest $manifest,
    ) {
    }

    /**
     * Her istekte, eklenti yüklendiğinde çalışır.
     * Kanca bağlama, rota ve panel sayfası kaydı buraya yazılır.
     */
    abstract public function boot(): void;

    /**
     * Yalnızca eklenti etkinleştirildiğinde bir kez çalışır.
     * Varsayılan ayarları yazmak için uygundur (tablolar migration ile kurulur).
     */
    public function activate(): void
    {
    }

    /**
     * Devre dışı bırakıldığında çalışır. Veriyi SİLMEZ — veri silme yalnızca
     * eklenti kaldırılırken (`uninstall()`) yapılır.
     */
    public function deactivate(): void
    {
    }

    /**
     * Eklenti tamamen kaldırılırken çalışır: kendi ayarlarını ve tablolarını
     * temizleme sorumluluğu eklentidedir.
     */
    public function uninstall(): void
    {
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    /** Eklenti dizinindeki bir dosyanın tam yolu. */
    protected function path(string $relative = ''): string
    {
        return $this->manifest->directory . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    /** Eklenti dizinindeki bir dosyanın tarayıcı adresi. */
    protected function assetUrl(string $relative): string
    {
        return $this->app->urls()->plugin($this->manifest->slug, $relative);
    }

    /**
     * Eklentiye ait ayar okur/yazar. Anahtarlar otomatik olarak eklenti kısa adı
     * ile öneklenir, böylece iki eklenti aynı ayar adını kullanamaz.
     */
    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->app->options()->get($this->optionKey($key), $default);
    }

    protected function setOption(string $key, mixed $value): void
    {
        $this->app->options()->set($this->optionKey($key), $value);
    }

    protected function optionKey(string $key): string
    {
        return 'plugin.' . $this->manifest->slug . '.' . $key;
    }
}
