<?php

declare(strict_types=1);

namespace HiCMS\Content;

/**
 * İçerik türü kaydı.
 *
 * Kutudan çıktığında yalnızca `post` ve `page` vardır. Tema ve eklentiler
 * `register()` ya da `loadDirectory()` ile kendi türlerini ekler; rota tablosu,
 * panel menüsü ve düzenleme formu bu kayıttan üretilir.
 */
final class TypeRegistry
{
    /** @var array<string, ContentType> */
    private array $types = [];

    /** @var array<string, Taxonomy> */
    private array $taxonomies = [];

    public function register(ContentType $type): void
    {
        $this->types[$type->name] = $type;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function registerArray(array $definition, string $source = 'core'): ContentType
    {
        $type = ContentType::fromArray($definition, $source);
        $this->register($type);

        return $type;
    }

    /**
     * Bir dizindeki tüm `*.json` tür tanımlarını yükler.
     *
     * @return list<string> Yüklenen tür adları
     */
    public function loadDirectory(string $directory, string $source): array
    {
        $loaded = [];

        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                continue;
            }

            $decoded['name'] ??= basename($file, '.json');
            $loaded[] = $this->registerArray($decoded, $source)->name;
        }

        return $loaded;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function get(string $name): ?ContentType
    {
        return $this->types[$name] ?? null;
    }

    /** @return array<string, ContentType> */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Ön yüzde görünen türler.
     *
     * @return array<string, ContentType>
     */
    public function publicTypes(): array
    {
        return array_filter($this->types, static fn(ContentType $t): bool => $t->isPublic);
    }

    /**
     * Panel menüsünde sırayla listelenecek türler.
     *
     * @return list<ContentType>
     */
    public function menuTypes(): array
    {
        $types = array_values($this->types);

        usort($types, static fn(ContentType $a, ContentType $b): int
            => [$a->menuOrder, $a->plural] <=> [$b->menuOrder, $b->plural]);

        return $types;
    }

    /**
     * Belirli bir rota önekine sahip türü bulur (yönlendirici kullanır).
     */
    public function byRoute(string $route): ?ContentType
    {
        foreach ($this->types as $type) {
            if ($type->route === $route && $type->isPublic) {
                return $type;
            }
        }

        return null;
    }

    public function byArchive(string $archive): ?ContentType
    {
        foreach ($this->types as $type) {
            if ($type->hasArchive() && $type->archive === $archive) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Bir kaynağın (tema/eklenti) kaydettiği türleri kaldırır — eklenti devre
     * dışı bırakıldığında panel menüsünde hayalet kalmasın diye.
     */
    public function forgetSource(string $source): void
    {
        foreach ($this->types as $name => $type) {
            if ($type->source === $source) {
                unset($this->types[$name]);
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Taksonomiler
     * ------------------------------------------------------------------ */

    public function registerTaxonomy(Taxonomy $taxonomy): void
    {
        $this->taxonomies[$taxonomy->name] = $taxonomy;
    }

    public function taxonomy(string $name): ?Taxonomy
    {
        return $this->taxonomies[$name] ?? null;
    }

    /** @return array<string, Taxonomy> */
    public function taxonomies(): array
    {
        return $this->taxonomies;
    }

    /**
     * Bir içerik türünün taksonomilerini nesne olarak döndürür.
     *
     * @return list<Taxonomy>
     */
    public function taxonomiesFor(ContentType $type): array
    {
        $result = [];

        foreach ($type->taxonomies as $name) {
            if (isset($this->taxonomies[$name])) {
                $result[] = $this->taxonomies[$name];
            }
        }

        return $result;
    }

    /**
     * Çekirdeğin iki temel türü ve iki taksonomisi.
     */
    public function registerDefaults(): void
    {
        $this->registerTaxonomy(new Taxonomy(
            name: 'category',
            singular: 'Kategori',
            plural: 'Kategoriler',
            route: 'kategori',
            hierarchical: true,
            hasColor: true,
            single: true,
        ));

        $this->registerTaxonomy(new Taxonomy(
            name: 'tag',
            singular: 'Etiket',
            plural: 'Etiketler',
            route: 'etiket',
            hierarchical: false,
            hasColor: false,
            single: false,
        ));

        $this->register(new ContentType(
            name: 'post',
            singular: 'Yazı',
            plural: 'Yazılar',
            icon: 'file-text',
            route: 'yazi',
            archive: null,
            hasComments: true,
            taxonomies: ['category', 'tag'],
            menuGroup: 'content',
            menuOrder: 10,
            description: 'Tarihe göre sıralanan blog yazıları.',
        ));

        $this->register(new ContentType(
            name: 'page',
            singular: 'Sayfa',
            plural: 'Sayfalar',
            icon: 'file',
            route: '',
            archive: null,
            hasComments: false,
            hierarchical: true,
            sortable: true,
            taxonomies: [],
            menuGroup: 'content',
            menuOrder: 20,
            orderBy: 'title',
            orderDir: 'asc',
            description: 'Tarihten bağımsız içerikler: hakkında, iletişim, gizlilik.',
        ));
    }
}
