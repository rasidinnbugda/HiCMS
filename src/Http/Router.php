<?php

declare(strict_types=1);

namespace HiCMS\Http;

/**
 * Merkezi rota tablosu.
 *
 * WordPress'in "query var" tahmin oyunu yerine açık bir tablo kullanılır: her
 * rota bir desen, bir işleyici ve bir addan oluşur. Eklentiler ve içerik türleri
 * kendi rotalarını buraya ekler; sıralama önceliğe göre yapılır, böylece
 * yakala-hepsini sayfa rotası her zaman en sonda kalır.
 *
 * Desen sözdizimi:
 *   /kategori/{slug}          → tek segment
 *   /kategori/{slug}/{page:\d+} → kısıtlı segment
 */
final class Router
{
    /** @var list<array{methods: list<string>, regex: string, params: list<string>, handler: mixed, name: string, priority: int, pattern: string}> */
    private array $routes = [];

    private bool $sorted = false;

    /**
     * @param callable|array{0: string, 1: string}|string $handler
     */
    public function get(string $pattern, mixed $handler, string $name = '', int $priority = 10): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $name, $priority);
    }

    public function post(string $pattern, mixed $handler, string $name = '', int $priority = 10): void
    {
        $this->add(['POST'], $pattern, $handler, $name, $priority);
    }

    public function any(string $pattern, mixed $handler, string $name = '', int $priority = 10): void
    {
        $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler, $name, $priority);
    }

    /**
     * @param list<string> $methods
     */
    public function add(array $methods, string $pattern, mixed $handler, string $name = '', int $priority = 10): void
    {
        [$regex, $params] = self::compile($pattern);

        $this->routes[] = [
            'methods'  => $methods,
            'regex'    => $regex,
            'params'   => $params,
            'handler'  => $handler,
            'name'     => $name,
            'priority' => $priority,
            'pattern'  => $pattern,
        ];

        $this->sorted = false;
    }

    /**
     * İsteğe uyan ilk rotayı döndürür.
     *
     * @return array{handler: mixed, params: array<string, string>, name: string}|null
     */
    public function match(Request $request): ?array
    {
        $this->sort();

        $path = $request->path;

        foreach ($this->routes as $route) {
            if (!in_array($request->method, $route['methods'], true)) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];

            foreach ($route['params'] as $name) {
                if (isset($matches[$name]) && $matches[$name] !== '') {
                    $params[$name] = $matches[$name];
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'name' => $route['name']];
        }

        return null;
    }

    /**
     * Adlandırılmış rotadan yol üretir.
     *
     * @param array<string, string|int> $params
     */
    public function path(string $name, array $params = []): ?string
    {
        foreach ($this->routes as $route) {
            if ($route['name'] !== $name) {
                continue;
            }

            $path = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
                static fn(array $m): string => rawurlencode((string) ($params[$m[1]] ?? '')),
                $route['pattern']
            );

            return rtrim((string) $path, '/') ?: '/';
        }

        return null;
    }

    /**
     * Kayıtlı rotaları döndürür (panelde tanılama için).
     *
     * @return list<array{pattern: string, name: string, methods: list<string>}>
     */
    public function all(): array
    {
        $this->sort();

        return array_map(
            static fn(array $route): array => [
                'pattern' => $route['pattern'],
                'name'    => $route['name'],
                'methods' => $route['methods'],
            ],
            $this->routes
        );
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        // Kararlı sıralama: önce öncelik, eşitse kayıt sırası korunur.
        $indexed = [];

        foreach ($this->routes as $index => $route) {
            $indexed[] = [$route['priority'], $index, $route];
        }

        usort($indexed, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $this->routes = array_map(static fn(array $row): array => $row[2], $indexed);
        $this->sorted = true;
    }

    /**
     * Deseni düzenli ifadeye çevirir.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        $params = [];
        $regex  = '';
        $offset = 0;

        // Sabit parçalar preg_quote ile kaçırılır, yer tutucular ham bırakılır.
        preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            $pattern,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $start = (int) $match[0][1];

            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');

            $name       = (string) $match[1][0];
            $constraint = isset($match[2]) ? (string) $match[2][0] : '[^/]+';

            $params[] = $name;
            $regex   .= '(?P<' . $name . '>' . $constraint . ')';

            $offset = $start + strlen((string) $match[0][0]);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        $normalized = rtrim($regex, '/');

        return ['#^' . ($normalized === '' ? '/' : $normalized) . '/?$#u', $params];
    }
}
