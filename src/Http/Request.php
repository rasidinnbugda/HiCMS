<?php

declare(strict_types=1);

namespace HiCMS\Http;

/**
 * Gelen istek. Süper küreselleri tek bir yerde sarmalar; kodun geri kalanı
 * `$_GET`/`$_POST`'a doğrudan dokunmaz.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
    ) {
    }

    /**
     * Süper küresellerden kurar.
     *
     * `$basePath` alt dizin kurulumlarında ("/hicms") istek yolundan düşülür.
     */
    public static function capture(string $basePath = ''): self
    {
        $uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($uri), '/');

        $basePath = '/' . trim($basePath, '/');

        if ($basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = '/' . trim(substr($path, strlen($basePath)), '/');
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '//' ? '/' : $path,
            $_GET,
            $_POST,
            $_FILES,
            array_map('strval', array_filter($_SERVER, 'is_scalar')),
            array_map('strval', $_COOKIE),
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }

        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? 'localhost';
    }

    /** İstek yolunun eğik çizgiyle ayrılmış parçaları. */
    public function segments(): array
    {
        $trimmed = trim($this->path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    public function segment(int $index, string $default = ''): string
    {
        return $this->segments()[$index] ?? $default;
    }

    /** Sorgu dizesi değeri. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Gövde (POST) değeri. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function text(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key);

        return in_array($value, ['1', 'on', 'true', 'yes', true, 1], true);
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->input($key, []);

        if (!is_array($value)) {
            return $value === null || $value === '' ? [] : [(string) $value];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /**
     * Güvenilen vekil adresleri. Boşsa hiçbir vekil başlığına güvenilmez.
     *
     * @var list<string>
     */
    private array $trustedProxies = [];

    /**
     * Hangi vekillerin başlıklarına güvenileceğini bildirir.
     *
     * `config.php` içindeki `trusted_proxies` dizisinden gelir. Değer tam IP
     * ya da CIDR olabilir: `['10.0.0.1', '172.16.0.0/12']`.
     *
     * @param list<string> $proxies
     */
    public function trustProxies(array $proxies): void
    {
        $this->trustedProxies = array_values(array_filter(array_map('strval', $proxies)));
    }

    /**
     * İstemci IP'si.
     *
     * VEKİL BAŞLIKLARINA KOŞULSUZ GÜVENİLMEZ.
     *
     * 0.2.0 `HTTP_CF_CONNECTING_IP` ve `HTTP_X_FORWARDED_FOR` başlıklarını
     * sırayla deniyor ve ilk geçerli IP'yi döndürüyordu. Bu başlıkları HERHANGİ
     * bir istemci gönderebilir; sonuç iki gerçek açıktı:
     *
     *   1. Giriş oran sınırlaması tamamen atlanabiliyordu — her istekte farklı
     *      bir `X-Forwarded-For` göndermek yeterli, çünkü sınırlama IP'ye
     *      bakıyor ve her seferinde yeni bir "IP" görüyor.
     *   2. Denetim günlüğü sahte adreslerle kirletilebiliyordu; olay incelemesi
     *      yanlış yere bakar.
     *
     * Artık başlık YALNIZCA isteğin gerçekten güvenilen bir vekilden gelmesi
     * hâlinde okunur. `REMOTE_ADDR` taklit edilemez: TCP el sıkışmasından gelir.
     *
     * Güvenilen vekil bildirilmemişse (varsayılan) `REMOTE_ADDR` kullanılır.
     * Ters vekil arkasında bu, tüm ziyaretçilerin aynı adresten görünmesi
     * demektir — bu yüzden giriş sınırlaması IP'ye DEĞİL kullanıcı adına
     * dayanmak zorunda (bkz. Auth::throttleSeconds()).
     */
    public function ip(): string
    {
        $remote = trim((string) ($this->server['REMOTE_ADDR'] ?? ''));

        if ($remote !== '' && $this->isTrustedProxy($remote)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
                $value = (string) ($this->server[$key] ?? '');

                if ($value === '') {
                    continue;
                }

                // XFF zinciri "istemci, vekil1, vekil2" sırasında: ilki istemci.
                $candidate = trim(explode(',', $value)[0]);

                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $trusted) {
            if ($trusted === $ip) {
                return true;
            }

            if (str_contains($trusted, '/') && self::inCidr($ip, $trusted)) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 CIDR eşleşmesi. IPv6 için yalnızca tam eşleşme desteklenir. */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    public function userAgent(): string
    {
        return substr($this->server['HTTP_USER_AGENT'] ?? '', 0, 255);
    }

    public function referer(): string
    {
        return $this->server['HTTP_REFERER'] ?? '';
    }

    /** AJAX / fetch isteği mi? */
    public function wantsJson(): bool
    {
        return str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json')
            || ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
