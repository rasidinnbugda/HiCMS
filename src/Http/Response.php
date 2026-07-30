<?php

declare(strict_types=1);

namespace HiCMS\Http;

/**
 * Giden yanıt. Gövde bir dizge ya da bir kapanış (deferred) olabilir; şablon
 * çıktısı doğrudan basıldığı için çekirdek çoğunlukla `deferred()` kullanır.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?\Closure $renderer = null;

    private function __construct(
        private string $body = '',
        private int $status = 200,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';

        return $response;
    }

    public static function text(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/plain; charset=UTF-8';

        return $response;
    }

    public static function xml(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'application/xml; charset=UTF-8';

        return $response;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'application/json; charset=UTF-8';

        return $response;
    }

    /**
     * Çıktıyı doğrudan basan şablonlar için: gövde gönderim anında üretilir.
     */
    public static function deferred(callable $renderer, int $status = 200): self
    {
        $response = new self('', $status);
        $response->renderer = \Closure::fromCallable($renderer);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';

        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;

        return $response;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** Tarayıcı önbelleğini kapatır (panel sayfaları için). */
    public function noCache(): self
    {
        $this->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
        $this->headers['Pragma']        = 'no-cache';

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Yanıtı istemciye gönderir.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            // Temel güvenlik başlıkları
            header('X-Content-Type-Options: nosniff', true);
            header('Referrer-Policy: strict-origin-when-cross-origin', true);
        }

        if ($this->renderer !== null) {
            ($this->renderer)();

            return;
        }

        echo $this->body;
    }
}
