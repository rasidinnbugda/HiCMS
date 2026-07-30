<?php

declare(strict_types=1);

namespace HiCMS\Content;

use Closure;
use HiCMS\Support\Html;

/**
 * Blok türü kaydı.
 *
 * Her blok türü hem **editörü** hem **çıktısını** tek yerde tanımlar:
 *
 *     $blocks->register('uyari', [
 *         'label'  => 'Uyarı Kutusu',
 *         'icon'   => 'alert',
 *         'group'  => 'metin',
 *         'fields' => [
 *             ['key' => 'tone', 'type' => 'select', 'label' => 'Ton',
 *              'options' => ['info' => 'Bilgi', 'warn' => 'Uyarı']],
 *             ['key' => 'text', 'type' => 'richtext', 'label' => 'Metin'],
 *         ],
 *         'render' => fn(array $data, BlockRenderer $r): string =>
 *             '<div class="uyari">' . $r->rich($data['text'] ?? '') . '</div>',
 *     ]);
 *
 * Panel editörü `fields` tanımından formu kendisi üretir — yeni blok eklemek
 * için panelde tek satır arayüz kodu yazmak gerekmez.
 */
final class BlockRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $types = [];

    /**
     * @param array{
     *     label: string,
     *     icon?: string,
     *     group?: string,
     *     description?: string,
     *     fields?: list<array<string, mixed>>,
     *     render: callable
     * } $definition
     */
    public function register(string $type, array $definition): void
    {
        $definition['label']  ??= $type;
        $definition['icon']   ??= 'block';
        $definition['group']  ??= 'diger';
        $definition['fields'] ??= [];
        $definition['render']   = Closure::fromCallable($definition['render']);

        $this->types[$type] = $definition;
    }

    public function unregister(string $type): void
    {
        unset($this->types[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, mixed>|null */
    public function definition(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * Editörde grup başlıkları altında listelemek için.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->types as $type => $definition) {
            $groups[(string) $definition['group']][$type] = $definition;
        }

        return $groups;
    }

    /**
     * Bir bloğun boş veri iskeletini üretir (editör yeni blok eklerken).
     *
     * @return array<string, mixed>
     */
    public function blank(string $type): array
    {
        $data = [];

        foreach ($this->definition($type)['fields'] ?? [] as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $data[$key] = match ($field['type'] ?? 'text') {
                'repeater', 'media-list' => [],
                'number'                 => 0,
                'switch'                 => false,
                'select'                 => array_key_first((array) ($field['options'] ?? [])) ?? '',
                default                  => (string) ($field['default'] ?? ''),
            };
        }

        return $data;
    }

    /**
     * Blok ağacını KAYIT ANINDA temizler.
     *
     * Neden burada: 0.2.0'da temizleme yalnızca render anında yapılıyordu
     * (Field::sanitize, BlockRenderer üzerinden). Ön yüz güvenliydi ama
     * veritabanına ham HTML yazılıyordu ve blok metnini o iki yoldan geçmeden
     * kullanan HER tüketici ham veriyi görüyordu: JSON çıktısı, dışa aktarma,
     * arama dizini, denetim günlüğü özeti, eklentiler ve panelin kendisi.
     *
     * Kayıt sınırında temizlemek bunların hepsini tek noktadan güvenli kılar.
     * Render anındaki temizleme KALDIRILMADI — eski kayıtlar ve elle
     * veritabanına yazılmış içerik için ikinci savunma katmanı olarak durur.
     *
     * Bilinmeyen blok türü ATILMAZ: bir eklenti devre dışıyken içeriği
     * kaydetmek o eklentinin bloklarını silmemeli. Tanınmayan türün verisi
     * dokunulmadan geçer, ama string alanları yine de temizlenir.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function sanitizeTree(array $blocks, int $depth = 0): array
    {
        // Derinlik sınırı: iç içe blok (repeater) ile özyineleme bombası kurulmasın.
        if ($depth > 6) {
            return [];
        }

        $clean = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');

            if ($type === '') {
                continue;
            }

            $data       = (array) ($block['data'] ?? []);
            $definition = $this->definition($type);

            if ($definition === null) {
                /*
                 * Tanınmayan tür: eklenti pasif olabilir. Veri korunur ama
                 * içindeki metinler yine temizlenir — bilinmeyen bir türün
                 * ham HTML taşımasına izin vermek saklı XSS demek.
                 */
                $clean[] = ['type' => $type, 'data' => $this->sanitizeUnknown($data, $depth)];
                continue;
            }

            $result = [];

            foreach ($definition['fields'] ?? [] as $spec) {
                $key = (string) ($spec['key'] ?? '');

                if ($key === '' || !array_key_exists($key, $data)) {
                    continue;
                }

                $result[$key] = Field::fromArray($spec)->sanitize($data[$key]);
            }

            $clean[] = ['type' => $type, 'data' => $result];
        }

        return $clean;
    }

    /**
     * Tanınmayan blok verisini tür bilgisi olmadan temizler: her dize
     * güvenli HTML kümesine indirgenir, yapı korunur.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeUnknown(array $data, int $depth): array
    {
        if ($depth > 6) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $clean[$key] = match (true) {
                is_string($value) => Html::clean($value),
                is_array($value)  => $this->sanitizeUnknown($value, $depth + 1),
                default           => $value,
            };
        }

        return $clean;
    }

    /**
     * Çekirdeğin yerleşik blokları.
     *
     * Bilinçli olarak azdır: her biri gerçekten gereken ve tema tarafından
     * anlamlı biçimde biçimlendirilebilen bir yapı üretir. Fazlası eklentinin
     * işidir.
     */
    public function registerDefaults(): void
    {
        $this->register('paragraph', [
            'label'       => 'Paragraf',
            'icon'        => 'text',
            'group'       => 'metin',
            'description' => 'Düz metin. Kalın, italik ve bağlantı kullanabilirsiniz.',
            'fields'      => [
                ['key' => 'text', 'type' => 'richtext', 'label' => 'Metin', 'rows' => 5],
                ['key' => 'lead', 'type' => 'switch', 'label' => 'Giriş paragrafı (daha büyük punto)'],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $text = $r->rich((string) ($data['text'] ?? ''));

                if (trim(strip_tags($text)) === '') {
                    return '';
                }

                $class = !empty($data['lead']) ? ' class="is-lead"' : '';

                // Metin zaten <p> içeriyorsa sarmalamayı tekrarlamayalım.
                if (str_starts_with(ltrim($text), '<p')) {
                    return $text;
                }

                return '<p' . $class . '>' . $text . '</p>';
            },
        ]);

        $this->register('heading', [
            'label'  => 'Başlık',
            'icon'   => 'heading',
            'group'  => 'metin',
            'fields' => [
                ['key' => 'level', 'type' => 'select', 'label' => 'Seviye', 'default' => 'h2',
                 'options' => ['h2' => 'Başlık 2', 'h3' => 'Başlık 3', 'h4' => 'Başlık 4']],
                ['key' => 'text', 'type' => 'text', 'label' => 'Başlık metni'],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $text = trim((string) ($data['text'] ?? ''));

                if ($text === '') {
                    return '';
                }

                $level = in_array($data['level'] ?? 'h2', ['h2', 'h3', 'h4'], true) ? $data['level'] : 'h2';
                $id    = $r->anchor($text);

                return sprintf('<%s id="%s">%s</%s>', $level, $r->attr($id), $r->text($text), $level);
            },
        ]);

        $this->register('list', [
            'label'  => 'Liste',
            'icon'   => 'list',
            'group'  => 'metin',
            'fields' => [
                ['key' => 'style', 'type' => 'select', 'label' => 'Tür', 'default' => 'bullet',
                 'options' => ['bullet' => 'Madde işaretli', 'number' => 'Numaralı']],
                ['key' => 'items', 'type' => 'lines', 'label' => 'Maddeler', 'help' => 'Her satır bir madde'],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $items = $r->lines($data['items'] ?? []);

                if ($items === []) {
                    return '';
                }

                $tag  = ($data['style'] ?? 'bullet') === 'number' ? 'ol' : 'ul';
                $html = '';

                foreach ($items as $item) {
                    $html .= '<li>' . $r->rich($item) . '</li>';
                }

                return "<{$tag}>{$html}</{$tag}>";
            },
        ]);

        $this->register('quote', [
            'label'  => 'Alıntı',
            'icon'   => 'quote',
            'group'  => 'metin',
            'fields' => [
                ['key' => 'text', 'type' => 'richtext', 'label' => 'Alıntı', 'rows' => 3],
                ['key' => 'cite', 'type' => 'text', 'label' => 'Kaynak', 'optional' => true],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $text = trim((string) ($data['text'] ?? ''));

                if ($text === '') {
                    return '';
                }

                $cite = trim((string) ($data['cite'] ?? ''));

                return '<blockquote><p>' . $r->rich($text) . '</p>'
                    . ($cite !== '' ? '<cite>' . $r->text($cite) . '</cite>' : '')
                    . '</blockquote>';
            },
        ]);

        $this->register('image', [
            'label'  => 'Görsel',
            'icon'   => 'image',
            'group'  => 'medya',
            'fields' => [
                ['key' => 'mediaId', 'type' => 'media', 'label' => 'Görsel'],
                ['key' => 'caption', 'type' => 'text', 'label' => 'Alt yazı', 'optional' => true],
                ['key' => 'width', 'type' => 'select', 'label' => 'Genişlik', 'default' => 'normal',
                 'options' => ['normal' => 'Metin genişliği', 'wide' => 'Geniş', 'full' => 'Tam genişlik']],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $item = $r->media((int) ($data['mediaId'] ?? 0));

                if ($item === null) {
                    return '';
                }

                $width   = in_array($data['width'] ?? 'normal', ['normal', 'wide', 'full'], true)
                    ? (string) $data['width'] : 'normal';
                $caption = trim((string) ($data['caption'] ?? ''));

                return '<figure class="block-image is-' . $r->attr($width) . '">'
                    . $r->image($item, $width === 'normal' ? '(max-width: 720px) 100vw, 720px' : '100vw')
                    . ($caption !== '' ? '<figcaption>' . $r->text($caption) . '</figcaption>' : '')
                    . '</figure>';
            },
        ]);

        $this->register('gallery', [
            'label'  => 'Galeri',
            'icon'   => 'grid',
            'group'  => 'medya',
            'fields' => [
                ['key' => 'mediaIds', 'type' => 'media-list', 'label' => 'Görseller'],
                ['key' => 'columns', 'type' => 'select', 'label' => 'Sütun', 'default' => '3',
                 'options' => ['2' => '2 sütun', '3' => '3 sütun', '4' => '4 sütun']],
                ['key' => 'caption', 'type' => 'text', 'label' => 'Alt yazı', 'optional' => true],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $ids   = array_map('intval', (array) ($data['mediaIds'] ?? []));
                $items = array_filter(array_map([$r, 'media'], $ids));

                if ($items === []) {
                    return '';
                }

                $columns = (int) ($data['columns'] ?? 3);
                $columns = $columns >= 2 && $columns <= 4 ? $columns : 3;
                $caption = trim((string) ($data['caption'] ?? ''));

                $html = '<figure class="block-gallery" style="--gallery-columns: ' . $columns . '">';

                foreach ($items as $item) {
                    $html .= '<span class="gallery-cell">' . $r->image($item, '(max-width: 720px) 50vw, 320px') . '</span>';
                }

                $html .= $caption !== '' ? '<figcaption>' . $r->text($caption) . '</figcaption>' : '';

                return $html . '</figure>';
            },
        ]);

        $this->register('code', [
            'label'  => 'Kod',
            'icon'   => 'code',
            'group'  => 'medya',
            'fields' => [
                ['key' => 'language', 'type' => 'text', 'label' => 'Dil', 'optional' => true,
                 'help' => 'örn. php, css, bash'],
                ['key' => 'code', 'type' => 'code', 'label' => 'Kod', 'rows' => 10],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $code = (string) ($data['code'] ?? '');

                if (trim($code) === '') {
                    return '';
                }

                $language = preg_replace('/[^a-z0-9+#-]/i', '', (string) ($data['language'] ?? '')) ?: '';
                $class    = $language !== '' ? ' class="language-' . $r->attr($language) . '"' : '';

                return '<pre class="block-code"><code' . $class . '>' . $r->text($code) . '</code></pre>';
            },
        ]);

        $this->register('embed', [
            'label'  => 'Video / Gömme',
            'icon'   => 'play',
            'group'  => 'medya',
            'fields' => [
                ['key' => 'url', 'type' => 'url', 'label' => 'Adres',
                 'help' => 'YouTube veya Vimeo bağlantısı'],
                ['key' => 'caption', 'type' => 'text', 'label' => 'Alt yazı', 'optional' => true],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $embed = $r->embedUrl((string) ($data['url'] ?? ''));

                if ($embed === null) {
                    return '';
                }

                $caption = trim((string) ($data['caption'] ?? ''));

                return '<figure class="block-embed"><div class="embed-frame">'
                    . '<iframe src="' . $r->url($embed) . '" loading="lazy" title="Gömülü video"'
                    . ' allow="accelerometer; clipboard-write; encrypted-media; picture-in-picture"'
                    . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>'
                    . ($caption !== '' ? '<figcaption>' . $r->text($caption) . '</figcaption>' : '')
                    . '</figure>';
            },
        ]);

        $this->register('callout', [
            'label'  => 'Bilgi Kutusu',
            'icon'   => 'info',
            'group'  => 'düzen',
            'fields' => [
                ['key' => 'tone', 'type' => 'select', 'label' => 'Ton', 'default' => 'info',
                 'options' => ['info' => 'Bilgi', 'success' => 'Olumlu', 'warning' => 'Uyarı', 'danger' => 'Dikkat']],
                ['key' => 'title', 'type' => 'text', 'label' => 'Başlık', 'optional' => true],
                ['key' => 'text', 'type' => 'richtext', 'label' => 'Metin', 'rows' => 3],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $text = trim((string) ($data['text'] ?? ''));

                if ($text === '') {
                    return '';
                }

                $tone  = in_array($data['tone'] ?? 'info', ['info', 'success', 'warning', 'danger'], true)
                    ? (string) $data['tone'] : 'info';
                $title = trim((string) ($data['title'] ?? ''));

                return '<aside class="block-callout is-' . $r->attr($tone) . '">'
                    . ($title !== '' ? '<strong>' . $r->text($title) . '</strong>' : '')
                    . '<div>' . $r->rich($text) . '</div></aside>';
            },
        ]);

        $this->register('cta', [
            'label'  => 'Eylem Çağrısı',
            'icon'   => 'target',
            'group'  => 'düzen',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Başlık'],
                ['key' => 'text', 'type' => 'text', 'label' => 'Açıklama', 'optional' => true],
                ['key' => 'label', 'type' => 'text', 'label' => 'Düğme metni'],
                ['key' => 'url', 'type' => 'url', 'label' => 'Düğme adresi'],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $title = trim((string) ($data['title'] ?? ''));
                $url   = trim((string) ($data['url'] ?? ''));
                $label = trim((string) ($data['label'] ?? ''));

                if ($title === '' && $label === '') {
                    return '';
                }

                $text = trim((string) ($data['text'] ?? ''));

                return '<aside class="block-cta">'
                    . ($title !== '' ? '<h3>' . $r->text($title) . '</h3>' : '')
                    . ($text !== '' ? '<p>' . $r->text($text) . '</p>' : '')
                    . ($label !== '' && $url !== ''
                        ? '<a class="button button-primary" href="' . $r->url($url) . '">' . $r->text($label) . '</a>'
                        : '')
                    . '</aside>';
            },
        ]);

        $this->register('divider', [
            'label'  => 'Ayıraç',
            'icon'   => 'minus',
            'group'  => 'düzen',
            'fields' => [
                ['key' => 'style', 'type' => 'select', 'label' => 'Biçim', 'default' => 'line',
                 'options' => ['line' => 'Çizgi', 'dots' => 'Üç nokta', 'space' => 'Boşluk']],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $style = in_array($data['style'] ?? 'line', ['line', 'dots', 'space'], true)
                    ? (string) $data['style'] : 'line';

                return $style === 'dots'
                    ? '<div class="block-divider is-dots" aria-hidden="true">•&#8195;•&#8195;•</div>'
                    : '<hr class="block-divider is-' . $r->attr($style) . '">';
            },
        ]);

        $this->register('html', [
            'label'       => 'Özel HTML',
            'icon'        => 'brackets',
            'group'       => 'diger',
            'description' => 'Gömme kodu veya özel işaretleme. Güvenli etiketlerle sınırlanır.',
            'fields'      => [
                ['key' => 'html', 'type' => 'code', 'label' => 'HTML', 'rows' => 8],
            ],
            'render' => static function (array $data, BlockRenderer $r): string {
                $html = (string) ($data['html'] ?? '');

                return trim($html) === '' ? '' : '<div class="block-html">' . $r->safe($html) . '</div>';
            },
        ]);
    }
}
