<?php

declare(strict_types=1);

namespace HiCMS\Http;

/**
 * URL üretici.
 *
 * Site adresi kurulum sırasında yapılandırmaya yazılır; yazılmadıysa istekten
 * türetilir (alt dizin kurulumları dahil). Tüm bağlantılar buradan geçtiği için
 * siteyi başka bir adrese taşımak tek bir ayarı değiştirmekten ibarettir.
 */
final class Url
{
    private string $base;

    public function __construct(string $base = '', ?Request $request = null)
    {
        $this->base = $base !== '' ? rtrim($base, '/') : self::guess($request);
    }

    /** Yapılandırmada adres yoksa istekten tahmin eder. */
    private static function guess(?Request $request): string
    {
        $scheme = $request !== null && $request->isSecure() ? 'https' : 'http';
        $host   = $request?->host() ?? 'localhost';

        $script = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

        // /admin ve /install.php gibi giriş noktalarını kökten düş.
        foreach (['/admin', '/install'] as $suffix) {
            if (str_ends_with($script, $suffix)) {
                $script = substr($script, 0, -strlen($suffix));
            }
        }

        return $scheme . '://' . $host . rtrim($script, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    /** Kurulumun kök yolu ("/hicms" veya ""). */
    public function basePath(): string
    {
        return (string) parse_url($this->base, PHP_URL_PATH) ?: '';
    }

    public function to(string $path = ''): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? $this->base . '/' : $this->base . '/' . $path;
    }

    public function admin(string $path = ''): string
    {
        return $this->to('admin/' . ltrim($path, '/'));
    }

    public function uploads(string $path = ''): string
    {
        return $this->to('content/uploads/' . ltrim($path, '/'));
    }

    public function theme(string $slug, string $path = ''): string
    {
        return $this->to('themes/' . $slug . '/' . ltrim($path, '/'));
    }

    public function plugin(string $slug, string $path = ''): string
    {
        return $this->to('plugins/' . $slug . '/' . ltrim($path, '/'));
    }

    /**
     * Sorgu dizesi ekleyerek bağlantı üretir.
     *
     * @param array<string, string|int|null> $params Null değerler atlanır.
     */
    public function with(string $path, array $params): string
    {
        $filtered = array_filter(
            $params,
            static fn(mixed $value): bool => $value !== null && $value !== ''
        );

        $query = http_build_query($filtered);

        return $this->to($path) . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Geçerli istek adresine parametre ekleyip/çıkarıp yeni bağlantı üretir
     * (liste sayfalarındaki filtreler için).
     *
     * @param array<string, string|int|null> $changes
     */
    public function currentWith(Request $request, array $changes): string
    {
        $params = array_merge($request->query, $changes);

        $filtered = array_filter(
            $params,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 'all'
        );

        $query = http_build_query($filtered);
        $script = basename((string) ($request->server['SCRIPT_NAME'] ?? 'index.php'));

        return $script . ($query !== '' ? '?' . $query : '');
    }
}
