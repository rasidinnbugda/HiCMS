<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use Closure;
use HiCMS\Content\Permalinks;
use HiCMS\Http\Url;
use HiCMS\Repository\ContentRepository;
use HiCMS\Repository\OptionRepository;
use HiCMS\Repository\TermRepository;
use HiCMS\Support\Html;
use HiCMS\Support\Str;

/**
 * Bileşen (widget) altyapısı.
 *
 * Tema bileşen alanı bildirir, panelden bu alanlara bileşen eklenir. Bileşen
 * türleri blok sistemiyle aynı mantıkta çalışır: `fields` tanımından panel formu
 * otomatik üretilir, `render` çıktıyı basar. Eklentiler `registerType()` ile
 * kendi bileşenlerini ekler.
 */
final class Widgets
{
    /** @var array<string, array{id: string, name: string, description: string}> */
    private array $areas = [];

    /** @var array<string, array{label: string, icon: string, description: string, fields: list<array<string, mixed>>, render: Closure}> */
    private array $types = [];

    public function __construct(
        private readonly OptionRepository $options,
        private readonly ContentRepository $content,
        private readonly TermRepository $terms,
        private readonly Permalinks $links,
        private readonly Url $url,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Alanlar
     * ------------------------------------------------------------------ */

    /**
     * @param array{id: string, name?: string, description?: string} $args
     */
    public function registerArea(array $args): string
    {
        $id = Str::slug((string) ($args['id'] ?? ''));

        if ($id === '') {
            return '';
        }

        $this->areas[$id] = [
            'id'          => $id,
            'name'        => (string) ($args['name'] ?? $id),
            'description' => (string) ($args['description'] ?? ''),
        ];

        return $id;
    }

    /** @return array<string, array{id: string, name: string, description: string}> */
    public function areas(): array
    {
        return $this->areas;
    }

    public function hasArea(string $id): bool
    {
        return isset($this->areas[$id]);
    }

    /**
     * Bir alandaki bileşenler.
     *
     * @return list<array{type: string, title: string, settings: array<string, mixed>}>
     */
    public function widgetsIn(string $areaId): array
    {
        $stored = $this->options->get('widgets', []);

        if (!is_array($stored) || !isset($stored[$areaId]) || !is_array($stored[$areaId])) {
            return [];
        }

        $widgets = [];

        foreach ($stored[$areaId] as $widget) {
            if (!is_array($widget) || !isset($widget['type'])) {
                continue;
            }

            $widgets[] = [
                'type'     => (string) $widget['type'],
                'title'    => (string) ($widget['title'] ?? ''),
                'settings' => is_array($widget['settings'] ?? null) ? $widget['settings'] : [],
            ];
        }

        return $widgets;
    }

    public function isActive(string $areaId): bool
    {
        return $this->widgetsIn($areaId) !== [];
    }

    /**
     * Bir alanın bileşenlerini kaydeder.
     *
     * @param list<array{type: string, title?: string, settings?: array<string, mixed>}> $widgets
     */
    public function saveArea(string $areaId, array $widgets): void
    {
        $stored = $this->options->get('widgets', []);
        $stored = is_array($stored) ? $stored : [];

        $clean = [];

        foreach ($widgets as $widget) {
            $type = (string) ($widget['type'] ?? '');

            if (!$this->hasType($type)) {
                continue;
            }

            $clean[] = [
                'type'     => $type,
                'title'    => trim((string) ($widget['title'] ?? '')),
                'settings' => $this->sanitizeSettings($type, (array) ($widget['settings'] ?? [])),
            ];
        }

        $stored[$areaId] = $clean;

        $this->options->set('widgets', $stored);
    }

    /* ---------------------------------------------------------------------
     * Türler
     * ------------------------------------------------------------------ */

    /**
     * @param array{label: string, icon?: string, description?: string,
     *              fields?: list<array<string, mixed>>, render: callable} $definition
     */
    public function registerType(string $type, array $definition): void
    {
        $this->types[$type] = [
            'label'       => (string) $definition['label'],
            'icon'        => (string) ($definition['icon'] ?? 'block'),
            'description' => (string) ($definition['description'] ?? ''),
            'fields'      => (array) ($definition['fields'] ?? []),
            'render'      => Closure::fromCallable($definition['render']),
        ];
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array<string, array<string, mixed>> */
    public function types(): array
    {
        return $this->types;
    }

    /** @return array<string, mixed>|null */
    public function type(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /* ---------------------------------------------------------------------
     * Basma
     * ------------------------------------------------------------------ */

    /**
     * Alanı basar.
     */
    public function renderArea(
        string $areaId,
        string $beforeWidget = '<section class="widget widget-%2$s" id="%1$s">',
        string $afterWidget = '</section>',
        string $beforeTitle = '<h2 class="widget-title">',
        string $afterTitle = '</h2>',
    ): string {
        $widgets = $this->widgetsIn($areaId);

        if ($widgets === []) {
            return '';
        }

        $html = '';

        foreach ($widgets as $index => $widget) {
            $body = $this->renderWidget($widget['type'], $widget['settings']);

            if (trim($body) === '') {
                continue;
            }

            $html .= sprintf($beforeWidget, Str::attr($areaId . '-' . ($index + 1)), Str::attr($widget['type']));

            if ($widget['title'] !== '') {
                $html .= $beforeTitle . Str::html($widget['title']) . $afterTitle;
            }

            $html .= $body . $afterWidget;
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function renderWidget(string $type, array $settings): string
    {
        $definition = $this->type($type);

        if ($definition === null) {
            return '';
        }

        /** @var Closure $render */
        $render = $definition['render'];

        return (string) $render($settings, $this);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitizeSettings(string $type, array $settings): array
    {
        $fields = $this->type($type)['fields'] ?? [];
        $clean  = [];

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $value = $settings[$key] ?? ($field['default'] ?? '');

            $clean[$key] = match ((string) ($field['type'] ?? 'text')) {
                'number'   => (int) $value,
                'switch'   => in_array($value, ['1', 'on', true, 1], true),
                'richtext' => Html::clean(is_string($value) ? $value : ''),
                'select'   => isset($field['options'][$value]) ? (string) $value
                                : (string) (array_key_first((array) ($field['options'] ?? [])) ?? ''),
                default    => is_scalar($value) ? trim((string) $value) : '',
            };
        }

        return $clean;
    }

    /* ---------------------------------------------------------------------
     * Yerleşik bileşenler
     * ------------------------------------------------------------------ */

    public function registerDefaults(): void
    {
        $this->registerType('search', [
            'label'  => 'Arama',
            'icon'   => 'search',
            'fields' => [
                ['key' => 'placeholder', 'type' => 'text', 'label' => 'Yer tutucu', 'default' => 'Yazılarda ara…'],
            ],
            'render' => function (array $settings): string {
                $placeholder = (string) ($settings['placeholder'] ?? 'Ara…');

                return '<form class="search-form" role="search" method="get" action="'
                    . Str::url($this->url->to('arama')) . '">'
                    . '<label class="sr-only" for="widget-search">Arama terimi</label>'
                    . '<input type="search" id="widget-search" name="q" placeholder="'
                    . Str::attr($placeholder) . '" required>'
                    . '<button type="submit" aria-label="Ara">'
                    . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
                    . ' stroke-width="2" stroke-linecap="round" aria-hidden="true">'
                    . '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
                    . '</button></form>';
            },
        ]);

        $this->registerType('about', [
            'label'  => 'Hakkında',
            'icon'   => 'user',
            'fields' => [
                ['key' => 'text', 'type' => 'richtext', 'label' => 'Metin', 'rows' => 4],
                ['key' => 'link', 'type' => 'text', 'label' => 'Bağlantı', 'default' => ''],
                ['key' => 'link_label', 'type' => 'text', 'label' => 'Bağlantı metni', 'default' => 'Devamını oku'],
            ],
            'render' => function (array $settings): string {
                $text = trim((string) ($settings['text'] ?? ''));

                if ($text === '') {
                    return '';
                }

                $html = '<div class="widget-about">' . Html::clean($text);
                $link = trim((string) ($settings['link'] ?? ''));

                if ($link !== '') {
                    $html .= '<a class="widget-link" href="' . Str::url($this->url->to($link)) . '">'
                        . Str::html((string) ($settings['link_label'] ?? 'Devamını oku')) . ' →</a>';
                }

                return $html . '</div>';
            },
        ]);

        $this->registerType('recent', [
            'label'  => 'Son İçerikler',
            'icon'   => 'list',
            'fields' => [
                ['key' => 'count', 'type' => 'number', 'label' => 'Kaç tane', 'default' => 4],
                ['key' => 'numbered', 'type' => 'switch', 'label' => 'Numaralandır', 'default' => true],
            ],
            'render' => function (array $settings): string {
                $entries = $this->content->get([
                    'type'        => 'post',
                    'visibleOnly' => true,
                    'perPage'     => max(1, min(12, (int) ($settings['count'] ?? 4))),
                ]);

                if ($entries === []) {
                    return '';
                }

                $numbered = !empty($settings['numbered']);
                $html     = '<ol class="widget-entry-list' . ($numbered ? ' is-numbered' : '') . '">';

                foreach ($entries as $index => $entry) {
                    $html .= '<li class="widget-entry">';

                    if ($numbered) {
                        $html .= '<span class="widget-entry-index">' . sprintf('%02d', $index + 1) . '</span>';
                    }

                    $html .= '<span class="widget-entry-body">'
                        . '<a class="widget-entry-title" href="' . Str::url($this->links->forEntry($entry)) . '">'
                        . Str::html($entry->title) . '</a>'
                        . '<span class="widget-entry-meta">'
                        . Str::html(\HiCMS\Support\Dates::format($entry->publishedAt, 'j M Y')) . '</span>'
                        . '</span></li>';
                }

                return $html . '</ol>';
            },
        ]);

        $this->registerType('terms', [
            'label'  => 'Kategoriler',
            'icon'   => 'folder',
            'fields' => [
                ['key' => 'taxonomy', 'type' => 'text', 'label' => 'Taksonomi', 'default' => 'category'],
                ['key' => 'show_count', 'type' => 'switch', 'label' => 'Sayıları göster', 'default' => true],
            ],
            'render' => function (array $settings): string {
                $taxonomy = (string) ($settings['taxonomy'] ?? 'category');
                $terms    = $this->terms->forTaxonomy($taxonomy, true, true);

                if ($terms === []) {
                    return '';
                }

                $showCount = !empty($settings['show_count']);
                $html      = '<ul class="widget-term-list">';

                foreach ($terms as $term) {
                    $html .= '<li><a href="' . Str::url($this->links->forTerm($term)) . '"'
                        . ($term->color !== '' ? ' style="--term-color: ' . Str::attr($term->color) . '"' : '') . '>'
                        . '<span class="term-dot" aria-hidden="true"></span>'
                        . Str::html($term->name)
                        . ($showCount ? '<span class="term-count">' . $term->count . '</span>' : '')
                        . '</a></li>';
                }

                return $html . '</ul>';
            },
        ]);

        $this->registerType('tagcloud', [
            'label'  => 'Etiket Bulutu',
            'icon'   => 'tag',
            'fields' => [
                ['key' => 'taxonomy', 'type' => 'text', 'label' => 'Taksonomi', 'default' => 'tag'],
                ['key' => 'limit', 'type' => 'number', 'label' => 'En fazla', 'default' => 24],
            ],
            'render' => function (array $settings): string {
                $terms = $this->terms->forTaxonomy((string) ($settings['taxonomy'] ?? 'tag'), true, true);

                if ($terms === []) {
                    return '';
                }

                usort($terms, static fn(object $a, object $b): int => $b->count <=> $a->count);
                $terms = array_slice($terms, 0, max(4, (int) ($settings['limit'] ?? 24)));
                $max   = max(array_map(static fn(object $t): int => $t->count, $terms));

                $html = '<div class="widget-tagcloud">';

                foreach ($terms as $term) {
                    $scale = $max > 0 ? $term->count / $max : 0;

                    $html .= '<a href="' . Str::url($this->links->forTerm($term)) . '"'
                        . sprintf(' style="font-size: %.2frem"', 0.82 + $scale * 0.22) . '>'
                        . Str::html($term->name) . '</a>';
                }

                return $html . '</div>';
            },
        ]);

        $this->registerType('newsletter', [
            'label'  => 'Bülten',
            'icon'   => 'mail',
            'fields' => [
                ['key' => 'text', 'type' => 'text', 'label' => 'Açıklama',
                 'default' => 'Yeni yazıları e-postanıza gönderelim.'],
                ['key' => 'action', 'type' => 'text', 'label' => 'Form adresi', 'default' => ''],
            ],
            'render' => function (array $settings): string {
                $action = trim((string) ($settings['action'] ?? ''));

                return '<div class="widget-newsletter">'
                    . '<p>' . Str::html((string) ($settings['text'] ?? '')) . '</p>'
                    . '<form class="newsletter-form" method="post" action="'
                    . Str::url($action !== '' ? $action : $this->url->to('abone')) . '">'
                    . '<label class="sr-only" for="widget-newsletter">E-posta adresiniz</label>'
                    . '<input type="email" id="widget-newsletter" name="email" required placeholder="ornek@eposta.com">'
                    . '<button class="button button-primary" type="submit">Abone Ol</button>'
                    . '</form>'
                    . '<small>Spam yok, dilediğiniz an çıkabilirsiniz.</small>'
                    . '</div>';
            },
        ]);

        $this->registerType('text', [
            'label'  => 'Serbest Metin',
            'icon'   => 'text',
            'fields' => [
                ['key' => 'text', 'type' => 'richtext', 'label' => 'Metin', 'rows' => 5],
            ],
            'render' => static function (array $settings): string {
                $text = trim((string) ($settings['text'] ?? ''));

                return $text === '' ? '' : '<div class="widget-text">' . Html::clean($text) . '</div>';
            },
        ]);
    }
}
