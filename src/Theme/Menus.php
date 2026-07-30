<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use HiCMS\Http\Url;
use HiCMS\Repository\OptionRepository;
use HiCMS\Support\Str;

/**
 * Menü yönetimi.
 *
 * Tema `registerLocation()` ile konum bildirir ("primary", "footer"); panelden
 * oluşturulan menüler bu konumlara atanır. Menü verisi ayarlarda tek bir JSON
 * yapısında tutulur — menü için ayrı tablo gerekmez, tek sorguyla okunur.
 */
final class Menus
{
    /** @var array<string, string> konum → etiket */
    private array $locations = [];

    /** @var array<string, array{name: string, items: list<array<string, mixed>>}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly OptionRepository $options,
        private readonly Url $url,
    ) {
    }

    public function registerLocation(string $location, string $label): void
    {
        $this->locations[$location] = $label;
    }

    /**
     * @param array<string, string> $locations
     */
    public function registerLocations(array $locations): void
    {
        foreach ($locations as $location => $label) {
            $this->registerLocation((string) $location, (string) $label);
        }
    }

    /** @return array<string, string> */
    public function locations(): array
    {
        return $this->locations;
    }

    /**
     * Tanımlı tüm menüler.
     *
     * @return array<string, array{name: string, items: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = $this->options->get('menus', []);
        $menus  = [];

        if (is_array($stored)) {
            foreach ($stored as $id => $menu) {
                if (!is_array($menu)) {
                    continue;
                }

                $menus[(string) $id] = [
                    'name'  => (string) ($menu['name'] ?? $id),
                    'items' => $this->normalizeItems((array) ($menu['items'] ?? [])),
                ];
            }
        }

        return $this->cache = $menus;
    }

    /**
     * @return array{name: string, items: list<array<string, mixed>>}|null
     */
    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** @return array<string, string> konum → menü kimliği */
    public function assignments(): array
    {
        $stored = $this->options->get('menu_locations', []);

        return is_array($stored) ? array_map('strval', $stored) : [];
    }

    /**
     * Bir konuma atanmış menünün öğeleri.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $location): array
    {
        $assignments = $this->assignments();
        $menuId      = $assignments[$location] ?? '';

        if ($menuId === '') {
            return [];
        }

        return $this->get($menuId)['items'] ?? [];
    }

    public function hasLocation(string $location): bool
    {
        return $this->items($location) !== [];
    }

    /**
     * Menü oluşturur veya günceller.
     *
     * @param list<array<string, mixed>> $items
     */
    public function save(string $id, string $name, array $items): string
    {
        $id = Str::slug($id !== '' ? $id : $name);

        if ($id === '') {
            $id = 'menu-' . substr(Str::random(3), 0, 5);
        }

        $menus      = $this->all();
        $menus[$id] = ['name' => trim($name) !== '' ? trim($name) : $id, 'items' => $this->normalizeItems($items)];

        $this->options->set('menus', $menus);
        $this->cache = $menus;

        return $id;
    }

    public function delete(string $id): void
    {
        $menus = $this->all();
        unset($menus[$id]);

        $this->options->set('menus', $menus);
        $this->cache = $menus;

        $assignments = array_filter($this->assignments(), static fn(string $menuId): bool => $menuId !== $id);
        $this->options->set('menu_locations', $assignments);
    }

    /**
     * @param array<string, string> $assignments konum → menü kimliği
     */
    public function assign(array $assignments): void
    {
        $clean = [];

        foreach ($assignments as $location => $menuId) {
            if (isset($this->locations[$location]) && $menuId !== '') {
                $clean[(string) $location] = (string) $menuId;
            }
        }

        $this->options->set('menu_locations', $clean);
    }

    /**
     * Menüyü HTML olarak basar.
     *
     * @param array{class?: string, item_class?: string, depth?: int, current?: string} $args
     */
    public function render(string $location, array $args = []): string
    {
        $items = $this->items($location);

        if ($items === []) {
            return '';
        }

        $args = array_merge([
            'class'      => 'nav-menu',
            'item_class' => 'nav-item',
            'depth'      => 0,
            'current'    => '',
        ], $args);

        return '<ul class="' . Str::attr($args['class']) . '">'
            . $this->renderItems($items, $args, 1)
            . '</ul>';
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $args
     */
    private function renderItems(array $items, array $args, int $level): string
    {
        $html    = '';
        $current = '/' . trim((string) $args['current'], '/');

        foreach ($items as $item) {
            $raw      = (string) ($item['url'] ?? '');
            $isMarker = $raw === '' || $raw === '#';
            $href     = $this->resolveUrl($raw);
            $children = (array) ($item['children'] ?? []);
            $hasKids  = $children !== [] && ((int) $args['depth'] === 0 || $level < (int) $args['depth']);

            $classes = [(string) $args['item_class']];

            if ($hasKids) {
                $classes[] = 'has-children';
            }

            $isCurrent = !$isMarker && $this->matchesCurrent($href, $current);

            if ($isCurrent) {
                $classes[] = 'is-current';
            }

            $html .= '<li class="' . Str::attr(implode(' ', $classes)) . '">'
                . '<a href="' . Str::url($href) . '"' . ($isCurrent ? ' aria-current="page"' : '')
                . (!empty($item['new_tab']) ? ' target="_blank" rel="noopener"' : '') . '>'
                . Str::html((string) ($item['label'] ?? '')) . '</a>';

            if ($hasKids) {
                $html .= '<ul class="' . Str::attr($args['class'] . '-sub') . '">'
                    . $this->renderItems($this->normalizeItems($children), $args, $level + 1)
                    . '</ul>';
            }

            $html .= '</li>';
        }

        return $html;
    }

    /**
     * Menü öğesi hedefini tam adrese çevirir.
     */
    private function resolveUrl(string $raw): string
    {
        if ($raw === '' ) {
            return $this->url->to();
        }

        if ($raw === '#') {
            return '#';
        }

        if (preg_match('#^(https?:)?//#i', $raw) === 1 || str_starts_with($raw, 'mailto:')) {
            return $raw;
        }

        return $this->url->to($raw);
    }

    private function matchesCurrent(string $href, string $current): bool
    {
        $path = '/' . trim((string) parse_url($href, PHP_URL_PATH), '/');
        $base = '/' . trim($this->url->basePath(), '/');

        if ($base !== '/' && str_starts_with($path, $base)) {
            $path = '/' . trim(substr($path, strlen($base)), '/');
        }

        return $path === $current;
    }

    /**
     * Gelen öğe dizisini beklenen şekle indirger.
     *
     * @param array<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $clean[] = [
                'label'    => $label,
                'url'      => trim((string) ($item['url'] ?? '')),
                'type'     => (string) ($item['type'] ?? 'custom'),
                'ref'      => (int) ($item['ref'] ?? 0),
                'new_tab'  => !empty($item['new_tab']),
                'children' => $this->normalizeItems((array) ($item['children'] ?? [])),
            ];
        }

        return $clean;
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
