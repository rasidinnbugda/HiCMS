<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Content\Field;
use HiCMS\Support\Str;

/**
 * Tür tanımının şeması.
 *
 * Panel formundan gelen ham POST'u, çekirdeğin `ContentType::fromArray()`
 * beklediği biçime çevirir ve doğrular. Alan temizliği için ikinci bir kural
 * seti yazılmaz: her alanın varsayılan değeri `Field::sanitize()` üzerinden
 * geçirilir, yani panelde tanımlanan alan ile koddan tanımlanan alan aynı
 * kurallara tabi olur.
 */
final class TypeBlueprint
{
    /** Destek anahtarı → [etiket, açıklama] */
    public const SUPPORTS = [
        'blocks'   => ['İçerik blokları', 'Gövde blok editörüyle yazılır'],
        'excerpt'  => ['Özet', 'Liste ve arama sonuçlarında görünür'],
        'image'    => ['Öne çıkan görsel', ''],
        'comments' => ['Yorumlar', 'Kayıtlara yorum yazılabilir'],
        'order'    => ['Elle sıralama', 'Tarih yerine sıra numarasıyla dizilir'],
    ];

    /** Çekirdeğin tanıdığı yayın durumları. */
    public const STATUSES = [
        'draft'     => 'Taslak',
        'pending'   => 'İncelemede',
        'published' => 'Yayında',
        'private'   => 'Özel',
    ];

    /**
     * Sıralama sütunları.
     *
     * Liste `ContentRepository::query()` içindeki izin listesinin alt kümesi:
     * dışındaki bir değer çekirdekte sessizce `published_at`'e düşer.
     */
    public const ORDER_COLUMNS = [
        'published_at' => 'Yayın tarihi',
        'created_at'   => 'Oluşturma tarihi',
        'updated_at'   => 'Güncelleme tarihi',
        'title'        => 'Başlık',
        'position'     => 'Elle sıra',
        'views'        => 'Görüntülenme',
    ];

    /** Alan türü → Türkçe etiket. Anahtarlar `Field::TYPES` ile aynı kümedir. */
    public const FIELD_TYPES = [
        'text'       => 'Metin',
        'textarea'   => 'Çok satırlı metin',
        'richtext'   => 'Zengin metin',
        'lines'      => 'Satır listesi',
        'code'       => 'Kod',
        'number'     => 'Sayı',
        'date'       => 'Tarih',
        'datetime'   => 'Tarih ve saat',
        'select'     => 'Açılır liste',
        'switch'     => 'Anahtar',
        'color'      => 'Renk',
        'url'        => 'Adres',
        'email'      => 'E-posta',
        'media'      => 'Medya',
        'media-list' => 'Medya listesi',
        'entry'      => 'İçerik bağlantısı',
        'repeater'   => 'Yinelenen grup',
    ];

    /** Seçenek listesi isteyen türler. */
    public const NEEDS_OPTIONS = ['select'];

    /** Alt alan isteyen türler. */
    public const NEEDS_SUBFIELDS = ['repeater'];

    /** Satır sayısı anlamlı olan türler. */
    public const NEEDS_ROWS = ['textarea', 'richtext', 'code', 'lines'];

    /** İpucu metni (placeholder) anlamlı olan türler. */
    public const NEEDS_PLACEHOLDER = ['text', 'textarea', 'richtext', 'lines', 'code', 'number', 'url', 'email'];

    public const MAX_FIELDS = 20;
    public const MAX_SUBFIELDS = 8;

    /**
     * Panel formundan tanım üretir ve doğrular.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error: string, definition: array<string, mixed>}
     */
    public static function fromInput(array $input): array
    {
        $definition = self::draft($input);
        $name       = (string) $definition['name'];

        if ($name === '') {
            return self::fail('Makine adı gerekli: harf, sayı ve alt çizgi.');
        }

        if (strlen($name) > 32) {
            return self::fail('Makine adı en fazla 32 karakter olabilir.');
        }

        if (in_array($name, Store::RESERVED_TYPES, true)) {
            return self::fail('"post" ve "page" adları çekirdeğe ayrılmıştır; başka bir ad seçin.');
        }

        return ['ok' => true, 'error' => '', 'definition' => $definition];
    }

    /**
     * Doğrulamadan tanım üretir.
     *
     * Reddedilen bir gönderimi forma geri basmak için gerekiyor: kullanıcı
     * yirmi alan tanımlayıp makine adını yanlış yazdığında bütün emeği
     * kaybetmemeli.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function draft(array $input): array
    {
        $name = Str::slug((string) ($input['ad'] ?? ''), '_');

        $singular = trim((string) ($input['tekil'] ?? ''));
        $plural   = trim((string) ($input['cogul'] ?? ''));

        if ($singular === '') {
            $singular = ucfirst(str_replace('_', ' ', $name));
        }

        if ($plural === '') {
            $plural = $singular;
        }

        $supports = [];

        foreach (array_keys(self::SUPPORTS) as $support) {
            if (isset($input['destek'][$support])) {
                $supports[] = $support;
            }
        }

        $statuses = [];

        foreach (array_keys(self::STATUSES) as $status) {
            if (isset($input['durum'][$status])) {
                $statuses[] = $status;
            }
        }

        /*
         * Taslak her zaman listede. Düzenleyicideki durum kutusu türün
         * durumlarıyla kesişimden üretiliyor; hiçbiri seçilmezse kutu boş
         * kalır ve içerik kaydedilemez hâle gelir.
         */
        if (!in_array('draft', $statuses, true)) {
            array_unshift($statuses, 'draft');
        }

        $taxonomies = [];

        foreach ((array) ($input['taksonomi'] ?? []) as $taxonomy) {
            $slug = Str::slug((string) (is_scalar($taxonomy) ? $taxonomy : ''), '_');

            if ($slug !== '' && !in_array($slug, $taxonomies, true)) {
                $taxonomies[] = $slug;
            }
        }

        $route = Str::slug((string) ($input['rota'] ?? ''));

        /*
         * Boş rota, kayıtları kök seviyeye (`/{slug}`) taşır ve çekirdeğin
         * Sayfa türüyle çakışır: aynı kalıcı bağlantıyı iki tür paylaşamaz.
         * Bu yüzden boş bırakılırsa makine adı kullanılır.
         */
        if ($route === '') {
            $route = Str::slug(str_replace('_', '-', $name));
        }

        $orderBy = (string) ($input['siralama'] ?? 'published_at');

        return [
            'name'         => $name,
            'labels'       => ['singular' => $singular, 'plural' => $plural],
            'icon'         => Str::slug((string) ($input['ikon'] ?? 'grid')),
            'route'        => $route,
            'archive'      => Str::slug((string) ($input['arsiv'] ?? '')),
            'public'       => isset($input['acik']),
            'hierarchical' => isset($input['hiyerarsik']),
            'supports'     => $supports,
            'taxonomies'   => $taxonomies,
            'statuses'     => $statuses,
            'order_by'     => isset(self::ORDER_COLUMNS[$orderBy]) ? $orderBy : 'published_at',
            'order_dir'    => strtolower((string) ($input['yon'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'description'  => trim(strip_tags((string) ($input['aciklama'] ?? ''))),
            'fields'       => self::fieldsFromInput((array) ($input['alan'] ?? []), true),
        ];
    }

    /**
     * Alan satırlarını POST biçiminden depo biçimine çevirir.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function fieldsFromInput(array $rows, bool $allowRepeater): array
    {
        $fields = [];
        $seen   = [];
        $limit  = $allowRepeater ? self::MAX_FIELDS : self::MAX_SUBFIELDS;

        foreach ($rows as $row) {
            if (count($fields) >= $limit) {
                break;
            }

            if (!is_array($row)) {
                continue;
            }

            $key = Str::slug((string) ($row['key'] ?? ''), '_');

            // Anahtarsız satır = kullanıcının doldurmadığı boş yuva.
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $type = (string) ($row['type'] ?? 'text');

            if (!isset(self::FIELD_TYPES[$type])) {
                $type = 'text';
            }

            // Yinelenen grup iç içe geçmez: alt alanların alt alanı olamaz.
            if ($type === 'repeater' && !$allowRepeater) {
                $type = 'text';
            }

            $label = trim((string) ($row['label'] ?? ''));

            $field = [
                'key'      => $key,
                'type'     => $type,
                'label'    => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key)),
                'help'     => trim((string) ($row['help'] ?? '')),
                'required' => isset($row['required']),
                'group'    => trim((string) ($row['group'] ?? '')),
            ];

            if (in_array($type, self::NEEDS_PLACEHOLDER, true)) {
                $field['placeholder'] = trim((string) ($row['placeholder'] ?? ''));
            }

            if (in_array($type, self::NEEDS_ROWS, true)) {
                $field['rows'] = max(2, min(20, (int) ($row['rows'] ?? 3)));
            }

            if ($type === 'select') {
                $options = self::options((string) ($row['options'] ?? ''));

                /*
                 * Seçeneksiz açılır liste panelde boş bir kutu olurdu: hiçbir
                 * değer seçilemez, kaydedilen değer her zaman boş kalır.
                 * Metin alanına düşürmek girilen veriyi korur.
                 */
                if ($options === []) {
                    $field['type'] = 'text';
                } else {
                    $field['options'] = $options;
                }
            }

            if ($field['type'] === 'repeater') {
                $subFields = self::fieldsFromInput((array) ($row['alt'] ?? []), false);

                if ($subFields === []) {
                    $field['type'] = 'text';
                } else {
                    $field['fields'] = $subFields;
                }
            }

            // Varsayılan değer çekirdeğin kendi temizleyicisinden geçer.
            $field['default'] = Field::fromArray($field)->sanitize($row['default'] ?? null);

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * "deger:Etiket" satırlarını seçenek haritasına çevirir.
     *
     * @return array<string, string>
     */
    public static function options(string $raw): array
    {
        $options = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$value, $label] = array_pad(explode(':', $line, 2), 2, '');

            $value = Str::slug((string) $value, '_');

            if ($value === '') {
                continue;
            }

            $label = trim((string) $label);

            $options[$value] = $label !== '' ? $label : $value;
        }

        return $options;
    }

    /**
     * Seçenek haritasını forma basılacak metne çevirir.
     *
     * @param array<string, string> $options
     */
    public static function optionLines(array $options): string
    {
        $lines = [];

        foreach ($options as $value => $label) {
            $lines[] = $value . ':' . $label;
        }

        return implode("\n", $lines);
    }

    /**
     * Depodan ya da 0.2.0 satırından gelen tanımı güvene alır.
     *
     * Formu basmak için de kullanılır: dönen dizide her anahtar mutlaka var,
     * böylece görünüm katmanı `??` zinciri kurmak zorunda kalmıyor.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed> Ad geçersizse boş dizi
     */
    public static function normalize(array $definition): array
    {
        $name = Str::slug((string) ($definition['name'] ?? ''), '_');

        if ($name === '' || in_array($name, Store::RESERVED_TYPES, true)) {
            return [];
        }

        $labels   = (array) ($definition['labels'] ?? []);
        $singular = trim((string) ($labels['singular'] ?? '')) !== ''
            ? (string) $labels['singular']
            : ucfirst(str_replace('_', ' ', $name));

        $supports = array_values(array_intersect(
            array_map('strval', (array) ($definition['supports'] ?? ['blocks', 'excerpt', 'image'])),
            array_keys(self::SUPPORTS)
        ));

        $statuses = array_values(array_intersect(
            array_map('strval', (array) ($definition['statuses'] ?? array_keys(self::STATUSES))),
            array_keys(self::STATUSES)
        ));

        if (!in_array('draft', $statuses, true)) {
            array_unshift($statuses, 'draft');
        }

        $taxonomies = [];

        foreach ((array) ($definition['taxonomies'] ?? []) as $taxonomy) {
            $slug = Str::slug((string) (is_scalar($taxonomy) ? $taxonomy : ''), '_');

            if ($slug !== '' && !in_array($slug, $taxonomies, true)) {
                $taxonomies[] = $slug;
            }
        }

        $fields = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            if (!is_array($field) || (string) ($field['key'] ?? '') === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');

            if (!isset(self::FIELD_TYPES[$type])) {
                $field['type'] = 'text';
            }

            $fields[] = $field;
        }

        $orderBy = (string) ($definition['order_by'] ?? 'published_at');
        $route   = Str::slug((string) ($definition['route'] ?? ''));

        return [
            'name'         => $name,
            'labels'       => [
                'singular' => $singular,
                'plural'   => trim((string) ($labels['plural'] ?? '')) !== ''
                    ? (string) $labels['plural']
                    : $singular,
            ],
            'icon'         => Str::slug((string) ($definition['icon'] ?? 'grid')),
            'route'        => $route !== '' ? $route : Str::slug(str_replace('_', '-', $name)),
            'archive'      => Str::slug((string) ($definition['archive'] ?? '')),
            'public'       => (bool) ($definition['public'] ?? true),
            'hierarchical' => (bool) ($definition['hierarchical'] ?? false),
            'supports'     => $supports,
            'taxonomies'   => $taxonomies,
            'statuses'     => $statuses,
            'order_by'     => isset(self::ORDER_COLUMNS[$orderBy]) ? $orderBy : 'published_at',
            'order_dir'    => strtolower((string) ($definition['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            'description'  => trim(strip_tags((string) ($definition['description'] ?? ''))),
            'fields'       => $fields,
        ];
    }

    /**
     * Boş bir tanım — "yeni tür" formunun başlangıç değerleri.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'name'         => '',
            'labels'       => ['singular' => '', 'plural' => ''],
            'icon'         => 'grid',
            'route'        => '',
            'archive'      => '',
            'public'       => true,
            'hierarchical' => false,
            'supports'     => ['blocks', 'excerpt', 'image'],
            'taxonomies'   => [],
            'statuses'     => ['draft', 'pending', 'published', 'private'],
            'order_by'     => 'published_at',
            'order_dir'    => 'desc',
            'description'  => '',
            'fields'       => [],
        ];
    }

    /** @return array{ok: bool, error: string, definition: array<string, mixed>} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'definition' => []];
    }
}
