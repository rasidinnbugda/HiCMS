<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Support\Str;

/**
 * Taksonomi tanımının şeması.
 *
 * Çekirdeğin `Taxonomy::fromArray()` biçimini üretir. Kategori ve etiket
 * çekirdeğe ait; buradan yalnızca yenileri (beceri, sektör, mekân…) tanımlanır.
 */
final class TaxonomyBlueprint
{
    /**
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

        if (in_array($name, Store::RESERVED_TAXONOMIES, true)) {
            return self::fail('"category" ve "tag" adları çekirdeğe ayrılmıştır; başka bir ad seçin.');
        }

        return ['ok' => true, 'error' => '', 'definition' => $definition];
    }

    /**
     * Doğrulamadan tanım üretir — reddedilen formu geri basmak için.
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

        $route = Str::slug((string) ($input['rota'] ?? ''));

        return [
            'name'         => $name,
            'labels'       => ['singular' => $singular, 'plural' => $plural],
            'route'        => $route !== '' ? $route : Str::slug(str_replace('_', '-', $name)),
            'hierarchical' => isset($input['hiyerarsik']),
            'color'        => isset($input['renk']),
            'single'       => isset($input['tek']),
            'public'       => isset($input['acik']),
            'description'  => trim(strip_tags((string) ($input['aciklama'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed> Ad geçersizse boş dizi
     */
    public static function normalize(array $definition): array
    {
        $name = Str::slug((string) ($definition['name'] ?? ''), '_');

        if ($name === '' || in_array($name, Store::RESERVED_TAXONOMIES, true)) {
            return [];
        }

        $labels   = (array) ($definition['labels'] ?? []);
        $singular = trim((string) ($labels['singular'] ?? '')) !== ''
            ? (string) $labels['singular']
            : ucfirst(str_replace('_', ' ', $name));

        $route = Str::slug((string) ($definition['route'] ?? ''));

        return [
            'name'         => $name,
            'labels'       => [
                'singular' => $singular,
                'plural'   => trim((string) ($labels['plural'] ?? '')) !== ''
                    ? (string) $labels['plural']
                    : $singular,
            ],
            'route'        => $route !== '' ? $route : Str::slug(str_replace('_', '-', $name)),
            'hierarchical' => (bool) ($definition['hierarchical'] ?? false),
            'color'        => (bool) ($definition['color'] ?? false),
            'single'       => (bool) ($definition['single'] ?? false),
            'public'       => (bool) ($definition['public'] ?? true),
            'description'  => trim(strip_tags((string) ($definition['description'] ?? ''))),
        ];
    }

    /**
     * Boş tanım — "yeni taksonomi" formunun başlangıç değerleri.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'name'         => '',
            'labels'       => ['singular' => '', 'plural' => ''],
            'route'        => '',
            'hierarchical' => false,
            'color'        => false,
            'single'       => false,
            'public'       => true,
            'description'  => '',
        ];
    }

    /** @return array{ok: bool, error: string, definition: array<string, mixed>} */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'definition' => []];
    }
}
