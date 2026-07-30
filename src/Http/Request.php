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

    public function ip(): string
    {
        // Ters vekil arkasındaysa gerçek IP başlıkta olabilir; yalnızca ilk değeri al.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $this->server[$key] ?? '';

            if ($value === '') {
                continue;
            }

            $candidate = trim(explode(',', $value)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '0.0.0.0';
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
