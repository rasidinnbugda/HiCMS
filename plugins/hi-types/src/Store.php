<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Extension\Settings;
use HiCMS\Kernel;
use Throwable;

/**
 * Tanım deposu.
 *
 * HiTypes'ın tüm kalıcı verisi çekirdeğin **ayar API'sinde** durur:
 * `hi_settings('hi-types')`. İki bölüm var ve aralarındaki sınır bilinçli:
 *
 *   • `genel` — kullanıcının panelden değiştirdiği ayarlar. Form gönderimiyle
 *     `save($_POST, 'genel')` çağrılır; işaretsiz anahtar "kapalı" sayılır.
 *   • `depo`  — tür ve taksonomi tanımlarının JSON metni. Buraya YALNIZCA
 *     `save(..., 'depo', true)` (kısmi) ile yazılır. Ayrı bölüm olmasının
 *     sebebi tam olarak bu: `genel` formu gönderildiğinde eksik anahtarların
 *     sıfırlanması kuralı tanımları silerdi.
 *
 * Neden JSON? Tanım ağaç biçimli (tür → alan → alt alan) ve `Field::TYPES`
 * kümesinde ağaç taşıyan bir alan türü yok. Tek `code` alanında JSON tutmak,
 * tanım başına ayar satırı açmaktan hem daha az sorgu hem daha az şema demek.
 * Depolama yine tek option satırı: `plugin.hi-types.settings`.
 */
final class Store
{
    /** Çekirdeğin sahip olduğu tür adları — üzerine yazılamaz. */
    public const RESERVED_TYPES = ['post', 'page'];

    /** Çekirdeğin sahip olduğu taksonomi adları. */
    public const RESERVED_TAXONOMIES = ['category', 'tag'];

    private bool $declared = false;

    public function __construct(
        private readonly Kernel $app,
        private readonly string $slug = 'hi-types',
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** Kayıt kaynağı: eklenti kapatılınca çekirdek bu etiketle türleri söker. */
    public function source(): string
    {
        return 'plugin:' . $this->slug;
    }

    /**
     * Ayar tanımı. Bölümler ilk erişimde bildirilir; `Kernel::settings()`
     * eklenti başına tek örnek tuttuğu için tanım bir kez yapılır.
     */
    public function settings(): Settings
    {
        $settings = $this->app->settings($this->slug);

        if (!$this->declared) {
            $this->defineSections($settings);
            $this->declared = true;
        }

        return $settings;
    }

    private function defineSections(Settings $settings): void
    {
        $settings->section(
            'genel',
            'Genel',
            [
                [
                    'key'     => 'menu_order',
                    'type'    => 'number',
                    'label'   => 'Menü sırası',
                    'default' => 60,
                    'help'    => 'Panel menüsünde türlerin yeri. Yazılar 10, Sayfalar 20 sırasında;'
                        . ' 60 vermek onların altına yerleştirir.',
                ],
                [
                    'key'     => 'field_group',
                    'type'    => 'text',
                    'label'   => 'Alan kutusu başlığı',
                    'default' => 'Alanlar',
                    'help'    => 'Grubu belirtilmemiş özel alanların içerik düzenleyicide toplandığı kutunun başlığı.',
                ],
                [
                    'key'     => 'trash_on_delete',
                    'type'    => 'switch',
                    'label'   => 'Tür silinince içerikleri çöp kutusuna taşı',
                    'default' => false,
                    'help'    => 'Kapalıyken tanım kaldırılır, kayıtlar veritabanında olduğu gibi kalır.'
                        . ' Bu ayar silme onayındaki kutunun başlangıç durumunu belirler.',
                ],
            ],
            'Bütün HiTypes türleri için geçerli.'
        );

        $settings->section(
            'depo',
            'Tanım deposu',
            [
                ['key' => 'types', 'type' => 'code', 'label' => 'Tür tanımları (JSON)', 'default' => '[]'],
                ['key' => 'taxonomies', 'type' => 'code', 'label' => 'Taksonomi tanımları (JSON)', 'default' => '[]'],
                ['key' => 'migrated', 'type' => 'switch', 'label' => '0.2.0 göçü tamamlandı', 'default' => false],
            ],
            'Panelden düzenlenmez; tür ekranı buraya yazar.'
        );
    }

    /* ---------------------------------------------------------------------
     * Okuma
     * ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function types(): array
    {
        return $this->read('types');
    }

    /** @return list<array<string, mixed>> */
    public function taxonomies(): array
    {
        return $this->read('taxonomies');
    }

    /** @return array<string, mixed>|null */
    public function type(string $name): ?array
    {
        return $this->find($this->types(), $name);
    }

    /** @return array<string, mixed>|null */
    public function taxonomy(string $name): ?array
    {
        return $this->find($this->taxonomies(), $name);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return array<string, mixed>|null
     */
    private function find(array $list, string $name): ?array
    {
        foreach ($list as $item) {
            if ((string) ($item['name'] ?? '') === $name) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Bir türde kaç kayıt var? (Silme onayı ve liste için.)
     */
    public function contentCount(string $name): int
    {
        return $this->app->content()->countOfType($name);
    }

    /* ---------------------------------------------------------------------
     * Yazma
     * ------------------------------------------------------------------ */

    /**
     * Türü ekler ya da günceller.
     *
     * @param array<string, mixed> $definition
     * @param string $replacing Makine adı değiştiyse eski ad — kayıt yerinde kalsın.
     */
    public function saveType(array $definition, string $replacing = ''): void
    {
        $this->write('types', $this->upsert($this->types(), $definition, $replacing));
    }

    /** @param array<string, mixed> $definition */
    public function saveTaxonomy(array $definition, string $replacing = ''): void
    {
        $this->write('taxonomies', $this->upsert($this->taxonomies(), $definition, $replacing));
    }

    public function deleteType(string $name): bool
    {
        $types = $this->types();
        $kept  = $this->without($types, $name);

        if (count($kept) === count($types)) {
            return false;
        }

        $this->write('types', $kept);

        return true;
    }

    /**
     * Taksonomiyi kaldırır ve onu kullanan tür tanımlarından da düşürür.
     *
     * Tanımda kalsa panelde ölü bir onay kutusu, ön yüzde de çözülmeyen bir
     * taksonomi adı bırakırdı.
     */
    public function deleteTaxonomy(string $name): bool
    {
        $taxonomies = $this->taxonomies();
        $kept       = $this->without($taxonomies, $name);

        if (count($kept) === count($taxonomies)) {
            return false;
        }

        $this->write('taxonomies', $kept);

        $types   = $this->types();
        $touched = false;

        foreach ($types as $index => $type) {
            $current = array_values(array_map('strval', (array) ($type['taxonomies'] ?? [])));
            $reduced = array_values(array_filter($current, static fn(string $t): bool => $t !== $name));

            if ($reduced !== $current) {
                $types[$index]['taxonomies'] = $reduced;
                $touched = true;
            }
        }

        if ($touched) {
            $this->write('types', $types);
        }

        return true;
    }

    /**
     * Tanımların tamamını değiştirir (JSON içe alma).
     *
     * @param list<array<string, mixed>> $types
     * @param list<array<string, mixed>> $taxonomies
     */
    public function replaceAll(array $types, array $taxonomies): void
    {
        $this->settings()->save([
            'types'      => self::encode($types),
            'taxonomies' => self::encode($taxonomies),
        ], 'depo', true);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $definition
     * @return list<array<string, mixed>>
     */
    private function upsert(array $list, array $definition, string $replacing): array
    {
        $target = $replacing !== '' ? $replacing : (string) ($definition['name'] ?? '');

        foreach ($list as $index => $item) {
            if ((string) ($item['name'] ?? '') === $target) {
                $list[$index] = $definition;

                return array_values($list);
            }
        }

        $list[] = $definition;

        return array_values($list);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private function without(array $list, string $name): array
    {
        return array_values(array_filter(
            $list,
            static fn(array $item): bool => (string) ($item['name'] ?? '') !== $name
        ));
    }

    /* ---------------------------------------------------------------------
     * Çekirdeğe kayıt
     * ------------------------------------------------------------------ */

    /**
     * Tanımlı her şeyi çekirdeğe bildirir. `boot()` içinde çağrılır: rota
     * tablosu, panel menüsü ve şablon hiyerarşisi bu kayıttan üretiliyor.
     */
    public function registerAll(): void
    {
        $data = $this->load();

        $group = trim((string) $this->settings()->get('field_group', 'Alanlar'));
        $order = (int) $this->settings()->get('menu_order', 60);

        foreach ($data['taxonomies'] as $definition) {
            $name = (string) ($definition['name'] ?? '');

            if ($name === '' || in_array($name, self::RESERVED_TAXONOMIES, true)) {
                continue;
            }

            hi_register_taxonomy($definition, $this->source());
        }

        foreach ($data['types'] as $definition) {
            $name = (string) ($definition['name'] ?? '');

            /*
             * Ayrılmış adlar burada da süzülür. Depoya elle ya da eski bir
             * sürümden "post" girmiş olsa, kayıt çekirdeğin Yazı türünü
             * SESSİZCE değiştirirdi: bütün blog panelde kaybolurdu.
             */
            if ($name === '' || in_array($name, self::RESERVED_TYPES, true)) {
                continue;
            }

            $definition['menu_order'] = $order;

            foreach ((array) ($definition['fields'] ?? []) as $index => $field) {
                if (is_array($field) && trim((string) ($field['group'] ?? '')) === '') {
                    $definition['fields'][$index]['group'] = $group !== '' ? $group : 'Alanlar';
                }
            }

            hi_register_content_type($definition, $this->source());
        }
    }

    /* ---------------------------------------------------------------------
     * Göç ve temizlik
     * ------------------------------------------------------------------ */

    /**
     * Tanımları okur; gerekiyorsa 0.2.0 deposundan taşır.
     *
     * @return array{types: list<array<string, mixed>>, taxonomies: list<array<string, mixed>>}
     */
    public function load(): array
    {
        $types      = $this->types();
        $taxonomies = $this->taxonomies();

        if ($this->settings()->get('migrated') === true) {
            return ['types' => $types, 'taxonomies' => $taxonomies];
        }

        /*
         * 0.2.0 GÖÇÜ — tek elle option okuması burada.
         *
         * Eski sürüm tanımları `plugin.hi-types.types` satırında tutuyordu.
         * Göç bir kez yapılır ve `migrated` işareti ayar satırına yazılır;
         * o satır zaten autoload olduğu için sonraki isteklerde bu yol
         * fazladan tek bir sorgu bile açmaz.
         */
        $legacy   = $this->app->options()->get('plugin.' . $this->slug . '.types');
        $imported = [];

        if (is_array($legacy)) {
            foreach ($legacy as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $normalized = TypeBlueprint::normalize($definition);

                if ($normalized !== []) {
                    $imported[] = $normalized;
                }
            }
        }

        if ($imported !== [] && $types === []) {
            $types = $imported;
        }

        try {
            $this->settings()->save([
                'types'      => self::encode($types),
                'taxonomies' => self::encode($taxonomies),
                'migrated'   => true,
            ], 'depo', true);

            $this->app->options()->delete('plugin.' . $this->slug . '.types');
        } catch (Throwable) {
            /*
             * Yazma başarısızsa (salt okunur veritabanı, kilit) göç bir sonraki
             * istekte yeniden denenir. Bu istekte tanımlar okunmuş hâlleriyle
             * kullanılır: kullanıcı türlerini kaybetmez.
             */
        }

        return ['types' => $types, 'taxonomies' => $taxonomies];
    }

    /** Eklenti kaldırılırken: ayarlar ve eski depo satırı silinir. */
    public function forget(): void
    {
        $this->settings()->forget();
        $this->app->options()->delete('plugin.' . $this->slug . '.types');
    }

    /* ---------------------------------------------------------------------
     * JSON
     * ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    private function read(string $key): array
    {
        $raw = $this->settings()->get($key, '[]');

        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode(is_string($raw) ? $raw : '[]', true);
        }

        if (!is_array($decoded)) {
            return [];
        }

        $list = [];

        foreach ($decoded as $item) {
            if (is_array($item) && (string) ($item['name'] ?? '') !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** @param list<array<string, mixed>> $list */
    private function write(string $key, array $list): void
    {
        // Kısmi yazma: `depo` bölümünün diğer anahtarlarına dokunulmaz.
        $this->settings()->save([$key => self::encode($list)], 'depo', true);
    }

    /** @param list<array<string, mixed>> $list */
    private static function encode(array $list): string
    {
        return (string) json_encode(
            array_values($list),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
