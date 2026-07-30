<?php

declare(strict_types=1);

namespace HiCMS\Content;

use HiCMS\Support\Str;

/**
 * Bir içerik türünün tanımı.
 *
 * Çekirdek yalnızca `post` ve `page` türlerini kaydeder. Bunun dışındaki her
 * şey — portfolyo, hizmet, ekip üyesi, etkinlik — tema veya eklenti tarafından
 * bildirilir. Bildirim JSON dosyası ya da PHP dizisi olabilir:
 *
 *     themes/temam/content-types/portfolyo.json
 *
 *     {
 *       "name": "portfolyo",
 *       "labels": { "singular": "Proje", "plural": "Portfolyo" },
 *       "icon": "grid",
 *       "route": "proje",
 *       "archive": "portfolyo",
 *       "taxonomies": ["category"],
 *       "supports": ["blocks", "excerpt", "image"],
 *       "fields": [
 *         { "key": "musteri", "type": "text", "label": "Müşteri" },
 *         { "key": "yil", "type": "number", "label": "Yıl" },
 *         { "key": "adres", "type": "url", "label": "Proje adresi" }
 *       ]
 *     }
 *
 * Tür kaydedildiği anda panelde menüsü, listesi ve düzenleme formu; ön yüzde
 * `/proje/{slug}` ve `/portfolyo` rotaları oluşur.
 */
final class ContentType
{
    /**
     * @param list<string> $taxonomies
     * @param list<Field> $fields
     * @param list<string> $statuses
     */
    public function __construct(
        public readonly string $name,
        public readonly string $singular,
        public readonly string $plural,
        public readonly string $icon = 'file',
        public readonly string $route = '',
        public readonly ?string $archive = null,
        public readonly bool $isPublic = true,
        public readonly bool $hasBlocks = true,
        public readonly bool $hasExcerpt = true,
        public readonly bool $hasImage = true,
        public readonly bool $hasComments = false,
        public readonly bool $hierarchical = false,
        public readonly bool $sortable = false,
        public readonly array $taxonomies = [],
        public readonly array $fields = [],
        public readonly string $menuGroup = 'content',
        public readonly int $menuOrder = 50,
        public readonly string $orderBy = 'published_at',
        public readonly string $orderDir = 'desc',
        public readonly string $description = '',
        public readonly string $source = 'core',
        public readonly array $statuses = ['draft', 'pending', 'published', 'private'],
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition, string $source = 'core'): self
    {
        $name = Str::slug((string) ($definition['name'] ?? ''), '_');

        if ($name === '') {
            $name = 'icerik';
        }

        $labels   = (array) ($definition['labels'] ?? []);
        $singular = (string) ($labels['singular'] ?? ucfirst($name));
        $plural   = (string) ($labels['plural'] ?? $singular);

        $supports = array_map('strval', (array) ($definition['supports'] ?? ['blocks', 'excerpt', 'image']));

        $fields = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            if (is_array($field)) {
                $fields[] = Field::fromArray($field);
            }
        }

        return new self(
            name: $name,
            singular: $singular,
            plural: $plural,
            icon: (string) ($definition['icon'] ?? 'file'),
            route: Str::slug((string) ($definition['route'] ?? '')),
            archive: isset($definition['archive']) && $definition['archive'] !== null && $definition['archive'] !== ''
                ? Str::slug((string) $definition['archive'])
                : null,
            isPublic: (bool) ($definition['public'] ?? true),
            hasBlocks: in_array('blocks', $supports, true),
            hasExcerpt: in_array('excerpt', $supports, true),
            hasImage: in_array('image', $supports, true),
            hasComments: in_array('comments', $supports, true),
            hierarchical: (bool) ($definition['hierarchical'] ?? false),
            sortable: in_array('order', $supports, true),
            taxonomies: array_values(array_map(
                static fn(mixed $t): string => Str::slug((string) $t, '_'),
                (array) ($definition['taxonomies'] ?? [])
            )),
            fields: $fields,
            menuGroup: (string) ($definition['menu_group'] ?? 'content'),
            menuOrder: (int) ($definition['menu_order'] ?? 50),
            orderBy: (string) ($definition['order_by'] ?? 'published_at'),
            orderDir: (string) ($definition['order_dir'] ?? 'desc'),
            description: (string) ($definition['description'] ?? ''),
            source: $source,
            statuses: array_values(array_map('strval', (array) ($definition['statuses'] ?? ['draft', 'pending', 'published', 'private']))),
        );
    }

    /** Tek kayıt yolu: /yazi/{slug} ya da /{slug} */
    public function permalinkPrefix(): string
    {
        return $this->route;
    }

    public function hasArchive(): bool
    {
        return $this->isPublic && $this->archive !== null && $this->archive !== '';
    }

    public function field(string $key): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    public function hasFields(): bool
    {
        return $this->fields !== [];
    }

    /**
     * Alanları panelde kutulara ayırmak için gruplar.
     *
     * @return array<string, list<Field>>
     */
    public function fieldGroups(): array
    {
        $groups = [];

        foreach ($this->fields as $field) {
            $groups[$field->group !== '' ? $field->group : 'Alanlar'][] = $field;
        }

        return $groups;
    }

    public function supportsTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->taxonomies, true);
    }

    /** Panelde kullanılan temel yol: admin/content.php?tur=post */
    public function adminUrl(string $extra = ''): string
    {
        return 'content.php?tur=' . rawurlencode($this->name) . ($extra !== '' ? '&' . $extra : '');
    }

    public function editUrl(int $id = 0): string
    {
        return 'content-edit.php?tur=' . rawurlencode($this->name) . ($id > 0 ? '&id=' . $id : '');
    }
}
