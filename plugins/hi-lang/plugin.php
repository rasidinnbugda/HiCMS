<?php

declare(strict_types=1);

namespace HiLang;

use HiCMS\Content\BlockRenderer;
use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Http\Response;
use HiCMS\Http\Router;
use HiCMS\Model\Entry;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;
use HiCMS\Theme\ViewContext;

/**
 * HiLang — içerik çokluluğu
 *
 * Çekirdek arayüz çevirisini zaten yapıyor (kaynak metinler Türkçe, dil
 * dosyaları hedef dile eşliyor). Eksik olan **içeriğin** dil sürümleriydi; bu
 * eklenti onu ekler.
 *
 * Yaklaşım basit ve geri dönüşü kolay: her içerik `content_meta` üzerinde iki
 * değer taşır — `locale` (dili) ve `translation_group` (aynı içeriğin diğer dil
 * sürümlerini birbirine bağlayan anahtar). Yeni tablo açılmaz; eklenti
 * kapatıldığında içerik olduğu gibi kalır, yalnızca dil bağları görünmez olur.
 *
 * İkincil diller `/en/...` gibi önekli adreslerden servis edilir.
 *
 * 0.3.0 NOTLARI
 *
 *  • AYARLAR — dil tanımları artık `hi_settings('hi-lang')` üzerinden, tek
 *    option satırında (`plugin.hi-lang.settings`) tutuluyor. 0.2.0 şeması
 *    `plugin.hi-lang.locales` anahtarındaydı; göç `migrateLegacyLocales()`
 *    içinde yapılıyor ve eski anahtar SİLİNMİYOR (geri dönüş mümkün kalsın).
 *
 *  • KANCA SÖKME — `forget()` kullanılmıyor. PluginManager `boot()`'u
 *    `asOwner('plugin:hi-lang', …)` ile sarıyor, devre dışı bırakmada
 *    `forgetOwner()` yalnızca bu eklentinin kayıtlarını söküyor.
 *
 *  • PANEL BETİĞİ — veri `hi_admin_data()` ile JSON olarak basılıyor, kurulum
 *    `HiAdmin.onMount()` içinde yapılıyor. Satır içi `window.X = …` kalıbı
 *    anında sayfa geçişinde çalışmadığı için kullanılmıyor.
 */
final class Plugin extends BasePlugin
{
    /** Ayar bölümleri. */
    private const SECTION_LOCALES  = 'diller';
    private const SECTION_BEHAVIOR = 'davranis';

    /** Panelde tanımlanabilecek en fazla dil. */
    private const MAX_LOCALES = 8;

    /**
     * 0.2.0 şemasının option anahtarı (eklenti öneki olmadan).
     *
     * SİLİNMEZ: göçten sonra da yerinde kalır, çünkü kullanıcı eski sürüme
     * dönerse dil listesini burada bulması gerekiyor.
     */
    private const LEGACY_KEY = 'locales';

    /** Yeni dil eklerken panelin önerdiği hazır tanımlar. */
    private const KNOWN_LOCALES = [
        'tr_TR' => ['Türkçe', 'tr'],
        'en_US' => ['English', 'en'],
        'en_GB' => ['English (UK)', 'en'],
        'de_DE' => ['Deutsch', 'de'],
        'fr_FR' => ['Français', 'fr'],
        'es_ES' => ['Español', 'es'],
        'it_IT' => ['Italiano', 'it'],
        'nl_NL' => ['Nederlands', 'nl'],
        'ru_RU' => ['Русский', 'ru'],
        'ar_SA' => ['العربية', 'ar'],
        'az_AZ' => ['Azərbaycanca', 'az'],
        'ku_TR' => ['Kurdî', 'ku'],
        'pt_BR' => ['Português (BR)', 'pt'],
        'zh_CN' => ['中文', 'zh'],
    ];

    /** @var array<string, array{label: string, prefix: string, primary: bool}>|null */
    private ?array $localeCache = null;

    /** @var array{localeOf: array<int, string>, groupOf: array<int, string>, groups: array<string, array<string, int>>}|null */
    private ?array $indexCache = null;

    /** Bu istekte göç denetimi yapıldı mı? */
    private bool $migrationChecked = false;

    public function boot(): void
    {
        $this->defineSettings();
        $this->migrateLegacyLocales();

        /* ---------------------------------------------------------------- ön yüz */

        // Dil değiştirici: hem bileşen (kenar çubuğu) hem blok (içerik içi).
        hi_register_widget_type('language-switcher', [
            'label'       => 'Dil Değiştirici',
            'icon'        => 'globe',
            'description' => 'Geçerli içeriğin diğer dil sürümlerine bağlantı verir.',
            'fields'      => $this->switcherFields(),
            'render'      => fn(array $settings): string => $this->switcher(
                (string) ($settings['style'] ?? ''),
                !isset($settings['hide_missing']) || !$settings['hide_missing']
            ),
        ]);

        hi_register_block('language-switcher', [
            'label'       => 'Dil Değiştirici',
            'icon'        => 'globe',
            'group'       => 'düzen',
            'description' => 'İçeriğin dil sürümlerine bağlantı listesi basar.',
            'fields'      => $this->switcherFields(),
            'render'      => fn(array $data, BlockRenderer $renderer): string => $this->switcher(
                (string) ($data['style'] ?? ''),
                !isset($data['hide_missing']) || !$data['hide_missing']
            ),
        ]);

        // Tema entegrasyonu: `echo hi_filter('hi_lang.switcher_html', '');`
        hi_add_filter('hi_lang.switcher_html', fn(mixed $value, mixed $style = ''): string
            => $this->switcher(is_string($style) ? $style : ''));

        // İkincil dil rotaları: /en, /en/sayfa/2, /en/yazi/{slug} gibi.
        hi_on('routing.register', function (Router $router): void {
            foreach ($this->secondaryLocales() as $code => $locale) {
                $prefix = '/' . $locale['prefix'];

                $router->get($prefix, fn(): mixed => $this->localeHome($code, 1), 'lang.home.' . $code, 8);

                $router->get(
                    $prefix . '/sayfa/{page:\d+}',
                    fn(array $params): mixed => $this->localeHome($code, (int) $params['page']),
                    'lang.home.paged.' . $code,
                    8
                );

                $router->get(
                    $prefix . '/{path:.+}',
                    fn(array $params): mixed => $this->localeRoute($code, (string) $params['path']),
                    'lang.route.' . $code,
                    9
                );
            }
        });

        // hreflang etiketleri ve değiştiricinin temel stili.
        hi_on('theme.head', fn(): null => $this->head());

        // Gövde sınıfı: tema CSS'i dile göre ayrım yapabilsin (ör. yazı yönü).
        hi_add_filter('theme.body_class', function (mixed $classes): array {
            $classes = is_array($classes) ? $classes : [];

            if ($this->setting('body_class', true)) {
                $classes[] = 'lang-' . Str::slug($this->currentLocale());
            }

            return $classes;
        });

        /* ------------------------------------------------------------------ panel */

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-lang',
                'label' => 'Diller',
                'icon'  => 'globe',
                'url'   => 'plugin.php?eklenti=hi-lang',
            ]);
        });

        hi_on('admin.page.hi-lang', fn(): null => $this->screen());
    }

    public function activate(): void
    {
        // Eski kurulumda dil listesi varsa önce onu taşı, üzerine yazma.
        $this->migrateLegacyLocales();

        $stored = $this->option('settings');

        if (is_array($stored) && ($stored['locales'] ?? []) !== []) {
            return;
        }

        hi_settings($this->slug())->save([
            'locales' => [
                ['code' => 'tr_TR', 'label' => 'Türkçe', 'prefix' => ''],
                ['code' => 'en_US', 'label' => 'English', 'prefix' => 'en'],
            ],
            'primary' => 'tr_TR',
        ], null, true);

        $this->localeCache = null;
    }

    /**
     * Eklenti kaldırılırken yalnızca YENİ ayar satırı silinir.
     *
     * İçeriklerin `locale` / `translation_group` meta değerleri ve 0.2.0'dan
     * kalan `plugin.hi-lang.locales` satırı bırakılır: ikisi de kullanıcının
     * emeği ve eklenti yeniden kurulduğunda geri dönmesi gerekiyor.
     */
    public function uninstall(): void
    {
        hi_settings($this->slug())->forget();
    }

    /* ---------------------------------------------------------------------
     * Ayarlar
     * ------------------------------------------------------------------ */

    private function defineSettings(): void
    {
        $settings = hi_settings($this->slug());

        $settings->section(self::SECTION_LOCALES, 'Diller', [
            [
                'key'     => 'locales',
                'type'    => 'repeater',
                'label'   => 'Dil tanımları',
                'default' => [],
                'fields'  => [
                    ['key' => 'code', 'type' => 'text', 'label' => 'Dil kodu'],
                    ['key' => 'label', 'type' => 'text', 'label' => 'Görünen ad'],
                    ['key' => 'prefix', 'type' => 'text', 'label' => 'Adres öneki'],
                ],
            ],
            [
                'key'     => 'primary',
                'type'    => 'text',
                'label'   => 'Birincil dil kodu',
                'default' => 'tr_TR',
            ],
        ], 'Birincil dil öneksiz sunulur; ikincil diller /en/… gibi önekli adreslerden gelir.');

        $settings->section(self::SECTION_BEHAVIOR, 'Davranış', [
            ['key' => 'hreflang', 'type' => 'switch', 'default' => true,
             'label' => 'hreflang etiketlerini bas',
             'help'  => 'Arama motorlarına aynı içeriğin dil sürümlerini bildirir.'],
            ['key' => 'x_default', 'type' => 'switch', 'default' => true,
             'label' => 'x-default etiketi ekle',
             'help'  => 'Dili belirlenemeyen ziyaretçi için birincil sürümü işaret eder.'],
            ['key' => 'fallback', 'type' => 'switch', 'default' => true,
             'label' => 'Çevirisi olmayan adresi birincil dilden sun',
             'help'  => 'Kapalıysa /en/… altında karşılığı olmayan içerik 404 döner.'],
            ['key' => 'show_missing', 'type' => 'switch', 'default' => true,
             'label' => 'Değiştiricide çevirisi olmayan dilleri de göster',
             'help'  => 'Bu diller o dilin ana sayfasına bağlanır.'],
            ['key' => 'switcher_style', 'type' => 'select', 'default' => 'inline',
             'label'   => 'Değiştiricinin varsayılan biçimi',
             'options' => ['inline' => 'Yan yana', 'list' => 'Liste', 'dropdown' => 'Açılır']],
            ['key' => 'switcher_css', 'type' => 'switch', 'default' => true,
             'label' => 'Değiştirici için temel stil bas',
             'help'  => 'Tema kendi stilini veriyorsa kapatın.'],
            ['key' => 'body_class', 'type' => 'switch', 'default' => true,
             'label' => 'Gövdeye lang-<kod> sınıfı ekle'],
        ]);
    }

    private function setting(string $key, mixed $fallback = null): mixed
    {
        return hi_settings($this->slug())->get($key, $fallback);
    }

    /**
     * 0.2.0 şemasından yeni ayar API'sine göç.
     *
     * ESKİ: plugin.hi-lang.locales = { "tr_TR": {label, prefix, primary}, … }
     * YENİ: plugin.hi-lang.settings.locales = [ {code, label, prefix}, … ]
     *       plugin.hi-lang.settings.primary = "tr_TR"
     *
     * İDEMPOTENT: yeni depoda `locales` anahtarı varsa hiçbir şey yapılmaz.
     * Anahtarın varlığına bakılıyor, değerine değil; böylece göç ikinci kez
     * çalışıp kullanıcının sonradan yaptığı düzenlemeyi geri almıyor.
     */
    private function migrateLegacyLocales(): void
    {
        if ($this->migrationChecked) {
            return;
        }

        $this->migrationChecked = true;

        $stored = $this->option('settings');

        if (is_array($stored) && array_key_exists('locales', $stored)) {
            return;
        }

        $legacy = $this->option(self::LEGACY_KEY);

        if (!is_array($legacy) || $legacy === []) {
            return;
        }

        $rows    = [];
        $primary = '';

        foreach ($legacy as $code => $locale) {
            $code = self::normalizeCode((string) $code);

            if ($code === '' || !is_array($locale)) {
                continue;
            }

            $label = trim((string) ($locale['label'] ?? ''));

            $rows[] = [
                'code'   => $code,
                'label'  => $label !== '' ? $label : $code,
                'prefix' => Str::slug((string) ($locale['prefix'] ?? '')),
            ];

            if (!empty($locale['primary']) && $primary === '') {
                $primary = $code;
            }
        }

        if ($rows === []) {
            return;
        }

        /*
         * Kısmi yazma: yalnızca gönderilen iki anahtar yazılır, davranış
         * ayarlarına dokunulmaz. save() form gönderimi kipinde (partial=false)
         * çağrılsaydı bölüm dışındaki her alan varsayılana düşerdi.
         */
        hi_settings($this->slug())->save([
            'locales' => $rows,
            'primary' => $primary !== '' ? $primary : $rows[0]['code'],
        ], null, true);

        $this->localeCache = null;
    }

    /* ---------------------------------------------------------------------
     * Dil tanımları
     * ------------------------------------------------------------------ */

    /**
     * Normalleştirilmiş dil listesi.
     *
     * @return array<string, array{label: string, prefix: string, primary: bool}>
     */
    public function locales(): array
    {
        if ($this->localeCache !== null) {
            return $this->localeCache;
        }

        $settings = hi_settings($this->slug());
        $rows     = $settings->get('locales', []);
        $primary  = self::normalizeCode((string) $settings->get('primary', ''));

        $clean = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = self::normalizeCode((string) ($row['code'] ?? ''));

            if ($code === '' || isset($clean[$code])) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            $clean[$code] = [
                'label'   => $label !== '' ? $label : (self::KNOWN_LOCALES[$code][0] ?? $code),
                'prefix'  => Str::slug((string) ($row['prefix'] ?? '')),
                'primary' => false,
            ];

            if (count($clean) >= self::MAX_LOCALES) {
                break;
            }
        }

        if ($clean === []) {
            return $this->localeCache = [
                'tr_TR' => ['label' => 'Türkçe', 'prefix' => '', 'primary' => true],
            ];
        }

        if (!isset($clean[$primary])) {
            $primary = (string) array_key_first($clean);
        }

        $clean[$primary]['primary'] = true;

        // Birincil dil her zaman öneksizdir: aksi hâlde kök adresle çakışır.
        $clean[$primary]['prefix'] = '';

        /*
         * İki dil aynı önekten sunulamaz. Çakışan öneki boşaltmak, sessizce
         * yanlış içeriği servis etmekten iyidir; panel bunu uyarı olarak
         * gösteriyor (bkz. localeIssues()).
         */
        $used = [];

        foreach ($clean as $code => $locale) {
            if ($code === $primary) {
                continue;
            }

            if ($locale['prefix'] === '' || in_array($locale['prefix'], $used, true)) {
                $clean[$code]['prefix'] = '';
                continue;
            }

            $used[] = $locale['prefix'];
        }

        return $this->localeCache = $clean;
    }

    public function primaryLocale(): string
    {
        foreach ($this->locales() as $code => $locale) {
            if ($locale['primary']) {
                return $code;
            }
        }

        return (string) array_key_first($this->locales());
    }

    /**
     * Önekli adresten servis edilebilen diller.
     *
     * @return array<string, array{label: string, prefix: string, primary: bool}>
     */
    public function secondaryLocales(): array
    {
        return array_filter(
            $this->locales(),
            static fn(array $locale): bool => !$locale['primary'] && $locale['prefix'] !== ''
        );
    }

    /**
     * Panelde gösterilecek yapılandırma uyarıları.
     *
     * @return list<string>
     */
    public function localeIssues(): array
    {
        $issues = [];

        foreach ($this->locales() as $code => $locale) {
            if ($locale['primary'] || $locale['prefix'] !== '') {
                continue;
            }

            $issues[] = Str::format(
                '"%s" (%s) dili adres önekine sahip olmadığı için ön yüzde sunulmuyor. '
                . 'Benzersiz bir önek verin.',
                $locale['label'],
                $code
            );
        }

        return $issues;
    }

    /**
     * Geçerli dil.
     *
     * Kural: bakılan içerik dilini AÇIKÇA bildiriyorsa o dil geçerlidir,
     * bildirmiyorsa adres öneki geçerlidir.
     *
     * Neden içerik önce geliyor: çekirdek yönlendirici bir yazıyı dilinden
     * bağımsız olarak öneksiz adresten de sunuyor. Yalnızca önekle karar
     * verilse, İngilizce bir yazı `/yazi/…` altında açıldığında değiştirici
     * "geçerli dil Türkçe" diyordu — ekranda İngilizce metin varken.
     */
    public function currentLocale(): string
    {
        $entry = hi_view()->entry;

        if ($entry !== null) {
            $declared = self::normalizeCode((string) ($entry->meta['locale'] ?? ''));

            if ($declared !== '' && isset($this->locales()[$declared])) {
                return $declared;
            }
        }

        $first = hi()->request()->segment(0);

        if ($first !== '') {
            foreach ($this->locales() as $code => $locale) {
                if ($locale['prefix'] !== '' && $locale['prefix'] === $first) {
                    return $code;
                }
            }
        }

        return $this->primaryLocale();
    }

    /** `tr_TR` → `tr-TR` (HTML ve hreflang biçimi). */
    public static function htmlLang(string $code): string
    {
        return str_replace('_', '-', $code);
    }

    /** `tr_TR` → `TR` (tablo kolonunda kullanılan kısa etiket). */
    public static function shortLabel(string $code): string
    {
        return strtoupper(explode('_', $code)[0]);
    }

    /**
     * Dil kodunu tek biçime indirger: `tr`, `tr-tr`, `TR_tr` → `tr_TR`.
     */
    private static function normalizeCode(string $code): string
    {
        $code = str_replace('-', '_', trim($code));

        if (preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z]{2}))?$/', $code, $match) !== 1) {
            return '';
        }

        return strtolower($match[1]) . (isset($match[2]) ? '_' . strtoupper($match[2]) : '');
    }

    /* ---------------------------------------------------------------------
     * Yönlendirme
     * ------------------------------------------------------------------ */

    /**
     * `/en` ve `/en/sayfa/2` → o dildeki içerik akışı.
     */
    private function localeHome(string $code, int $page): mixed
    {
        hi()->translator()->setLocale($code);

        $view          = hi_view();
        $view->kind    = ViewContext::HOME;
        $view->page    = max(1, $page);
        $view->perPage = max(1, (int) hi_option('posts_per_page', 8));

        $result = $this->queryLocale($code, 'post', $view->page, $view->perPage);

        $view->entries = $result['items'];
        $view->total   = $result['total'];
        $view->pages   = $result['pages'];
        $view->rewind();

        // Var olmayan sayfa numarası boş bir liste değil, 404 olmalı.
        $status = $view->page > 1 && $result['items'] === [] ? 404 : 200;

        return $this->render($status);
    }

    /**
     * `/en/yazi/{slug}` → ikincil dildeki tek içerik.
     */
    private function localeRoute(string $code, string $path): mixed
    {
        hi()->translator()->setLocale($code);

        $segments = explode('/', trim($path, '/'));
        $slug     = (string) end($segments);
        $found    = $slug !== '' ? $this->findLocalized($code, $slug) : null;

        if ($found === null) {
            return $this->notFound();
        }

        [$entry, $type, $entryLocale] = $found;

        if ($entryLocale !== $code) {
            /*
             * Bulunan içerik başka dilde. İstenen dilde bir çevirisi varsa
             * onun kendi adresine kalıcı olarak yönlendirilir — iki adresten
             * aynı içeriği sunmak hem SEO'da hem paylaşımda karışıklık.
             */
            $translations = $this->translationsOf($entry, true);

            if (isset($translations[$code])) {
                return Response::redirect($this->urlFor($translations[$code], $code), 301);
            }

            if (!$this->setting('fallback', true)) {
                return $this->notFound();
            }
        }

        $view              = hi_view();
        $view->kind        = $type->route === '' ? ViewContext::PAGE : ViewContext::SINGLE;
        $view->entry       = $entry;
        $view->entries     = [$entry];
        $view->contentType = $type;
        $view->total       = 1;
        $view->pages       = 1;
        $view->rewind();

        return $this->render();
    }

    /**
     * Kısa addan içerik bulur; istenen dildeki sürümü tercih eder.
     *
     * @return array{0: Entry, 1: \HiCMS\Content\ContentType, 2: string}|null
     */
    private function findLocalized(string $code, string $slug): ?array
    {
        $fallback = null;

        foreach (hi()->types()->publicTypes() as $type) {
            $entry = hi()->content()->findBySlug($type->name, $slug, true);

            if ($entry === null) {
                continue;
            }

            $entryLocale = $this->localeOf($entry);

            if ($entryLocale === $code) {
                return [$entry, $type, $entryLocale];
            }

            $fallback ??= [$entry, $type, $entryLocale];
        }

        return $fallback;
    }

    private function notFound(): mixed
    {
        $view       = hi_view();
        $view->kind = ViewContext::NOTFOUND;

        return $this->render(404);
    }

    private function render(int $status = 200): mixed
    {
        $template = hi()->templates()->resolve();

        if ($template === '') {
            return Response::html('<h1>Tema şablonu bulunamadı</h1>', 500);
        }

        return Response::deferred(static function () use ($template): void {
            hi()->templates()->render($template);
        }, $status);
    }

    /**
     * Belirli dildeki yayımlanmış içerikler, sayfalanmış.
     *
     * `content()->query()` meta süzgeci desteklemediği için doğrudan SQL.
     * Meta değerleri JSON olarak saklandığı için karşılaştırma da JSON.
     *
     * @return array{items: list<Entry>, total: int, pages: int}
     */
    private function queryLocale(string $code, string $type, int $page, int $perPage): array
    {
        $db      = hi()->db();
        $prefix  = $db->prefix();
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $where = sprintf(
            "c.type = :type AND c.status = 'published'
               AND (c.published_at IS NULL OR c.published_at <= :now)
               AND EXISTS (
                   SELECT 1 FROM `%scontent_meta` m
                    WHERE m.entry_id = c.id AND m.meta_key = 'locale' AND m.meta_value = :locale
               )",
            $prefix
        );

        $bindings = [
            'type'   => $type,
            'now'    => Dates::stamp(),
            'locale' => self::encodeMeta($code),
        ];

        $total = (int) $db->scalar(
            sprintf('SELECT COUNT(*) FROM `%scontent` c WHERE %s', $prefix, $where),
            $bindings
        );

        $rows = $db->select(
            sprintf(
                'SELECT c.* FROM `%1$scontent` c WHERE %2$s
                  ORDER BY c.published_at DESC, c.id DESC LIMIT %3$d OFFSET %4$d',
                $prefix,
                $where,
                $perPage,
                ($page - 1) * $perPage
            ),
            $bindings
        );

        $entries = array_map([Entry::class, 'fromRow'], $rows);

        hi()->content()->hydrate($entries);

        return [
            'items' => $entries,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /* ---------------------------------------------------------------------
     * Çeviri ilişkisi
     * ------------------------------------------------------------------ */

    /** Bir içeriğin dili; belirtilmemişse birincil dil sayılır. */
    public function localeOf(Entry $entry): string
    {
        $code = self::normalizeCode((string) ($entry->meta['locale'] ?? ''));

        return $code !== '' ? $code : $this->primaryLocale();
    }

    public function groupOf(Entry $entry): string
    {
        return trim((string) ($entry->meta['translation_group'] ?? ''));
    }

    /**
     * Bir içeriğin dil sürümleri (kendisi dahil).
     *
     * @return array<string, Entry>
     */
    public function translationsOf(Entry $entry, bool $visibleOnly = false): array
    {
        $group = $this->groupOf($entry);

        if ($group === '') {
            return [$this->localeOf($entry) => $entry];
        }

        $db     = hi()->db();
        $prefix = $db->prefix();

        $sql = sprintf(
            'SELECT c.*, m2.meta_value AS hi_lang_locale
               FROM `%1$scontent` c
               INNER JOIN `%1$scontent_meta` m
                       ON m.entry_id = c.id AND m.meta_key = \'translation_group\'
               LEFT JOIN `%1$scontent_meta` m2
                      ON m2.entry_id = c.id AND m2.meta_key = \'locale\'
              WHERE m.meta_value = :group',
            $prefix
        );

        $bindings = ['group' => self::encodeMeta($group)];

        if ($visibleOnly) {
            $sql .= " AND c.status = 'published'"
                . ' AND (c.published_at IS NULL OR c.published_at <= :now)';
            $bindings['now'] = Dates::stamp();
        }

        $translations = [];
        $primary      = $this->primaryLocale();

        foreach ($db->select($sql, $bindings) as $row) {
            $code = self::normalizeCode(self::decodeMeta((string) ($row['hi_lang_locale'] ?? '')));
            $code = $code !== '' ? $code : $primary;

            // Aynı dilde iki kayıt varsa ilki kalır; ikincisi çakışma sayılır.
            $translations[$code] ??= Entry::fromRow($row);
        }

        return $translations;
    }

    /**
     * `locale` ve `translation_group` meta değerlerinin tümünü tek sorguda okur.
     *
     * Panelde her satır için ayrı sorgu atmak N+1 demek olurdu; iki meta
     * anahtarının tamamı tek seferde belleğe alınıyor.
     *
     * @return array{localeOf: array<int, string>, groupOf: array<int, string>, groups: array<string, array<string, int>>}
     */
    private function translationIndex(): array
    {
        if ($this->indexCache !== null) {
            return $this->indexCache;
        }

        $db     = hi()->db();
        $prefix = $db->prefix();

        $rows = $db->select(sprintf(
            "SELECT entry_id, meta_key, meta_value FROM `%scontent_meta`
              WHERE meta_key IN ('locale', 'translation_group')",
            $prefix
        ));

        $localeOf = [];
        $groupOf  = [];

        foreach ($rows as $row) {
            $id    = (int) $row['entry_id'];
            $value = trim(self::decodeMeta((string) $row['meta_value']));

            if ($value === '') {
                continue;
            }

            if ((string) $row['meta_key'] === 'locale') {
                $code = self::normalizeCode($value);

                if ($code !== '') {
                    $localeOf[$id] = $code;
                }

                continue;
            }

            $groupOf[$id] = $value;
        }

        $primary = $this->primaryLocale();
        $groups  = [];

        foreach ($groupOf as $id => $group) {
            $code = $localeOf[$id] ?? $primary;

            $groups[$group][$code] ??= $id;
        }

        return $this->indexCache = [
            'localeOf' => $localeOf,
            'groupOf'  => $groupOf,
            'groups'   => $groups,
        ];
    }

    /**
     * Bir içeriğin çeviri durumu: dil kodu → içerik kimliği.
     *
     * @return array<string, int>
     */
    public function statusFor(int $entryId): array
    {
        $index = $this->translationIndex();
        $group = $index['groupOf'][$entryId] ?? '';

        if ($group === '') {
            $code = $index['localeOf'][$entryId] ?? '';

            return $code === '' ? [] : [$code => $entryId];
        }

        return $index['groups'][$group] ?? [];
    }

    /**
     * Tanımlı her dilde karşılığı olan içeriklerin kimlikleri.
     *
     * @return list<int>
     */
    private function completeIds(): array
    {
        $index = $this->translationIndex();
        $codes = array_keys($this->locales());
        $ids   = [];

        foreach ($index['groupOf'] as $id => $group) {
            if (array_diff($codes, array_keys($index['groups'][$group] ?? [])) === []) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * İki içeriği aynı çeviri grubuna alır.
     *
     * `$code` verilirse çakışma denetimi o dile göre yapılır. Panel bunu
     * kullanıyor: kullanıcı aynı gönderimde dili de değiştirebildiği için
     * denetimin HENÜZ YAZILMAMIŞ yeni dile göre yapılması gerekiyor. Aksi
     * hâlde reddedilen bir bağ isteğinde dil değişikliği yazılmış kalıyordu —
     * kullanıcı hata mesajı görüyor ama içeriğin dili sessizce değişmiş oluyordu.
     *
     * Denetim geçmeden HİÇBİR yazma yapılmaz.
     *
     * @return array{ok: bool, error: string}
     */
    public function linkEntries(int $entryId, int $targetId, ?string $code = null): array
    {
        if ($entryId <= 0 || $targetId <= 0 || $entryId === $targetId) {
            return ['ok' => false, 'error' => 'Geçersiz içerik seçimi.'];
        }

        $entry  = hi()->content()->find($entryId);
        $target = hi()->content()->find($targetId);

        if ($entry === null || $target === null) {
            return ['ok' => false, 'error' => 'İçerik bulunamadı.'];
        }

        $code ??= $this->localeOf($entry);

        // Aynı dilde iki kayıt aynı gruba giremez: bağ anlamını yitirir.
        $existing = $this->translationsOf($target);

        if (isset($existing[$code]) && $existing[$code]->id !== $entry->id) {
            return [
                'ok'    => false,
                'error' => Str::format(
                    'Bu grupta "%s" dilinde zaten bir içerik var: %s. Önce onun bağını çözün.',
                    $this->locales()[$code]['label'] ?? $code,
                    Str::limit($existing[$code]->title, 40)
                ),
            ];
        }

        $group = $this->groupOf($target);

        if ($group === '') {
            $group = 'tg-' . $target->id;
            hi()->content()->saveMeta($target->id, ['translation_group' => $group]);
        }

        hi()->content()->saveMeta($entry->id, ['translation_group' => $group]);

        $this->indexCache = null;

        return ['ok' => true, 'error' => ''];
    }

    /**
     * İçeriği çeviri grubundan çıkarır.
     *
     * Meta satırı silinir, içeriğe dokunulmaz.
     */
    public function unlinkEntry(int $entryId): void
    {
        hi()->content()->deleteMeta($entryId, 'translation_group');

        $this->indexCache = null;
    }

    private static function encodeMeta(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeMeta(string $raw): string
    {
        $decoded = json_decode($raw, true);

        return is_scalar($decoded) ? (string) $decoded : $raw;
    }

    /* ---------------------------------------------------------------------
     * Ön yüz çıktısı
     * ------------------------------------------------------------------ */

    /**
     * Bir içeriğin belirli dildeki adresi.
     */
    public function urlFor(Entry $entry, string $code): string
    {
        $prefix = (string) ($this->locales()[$code]['prefix'] ?? '');
        $path   = ltrim(hi()->links()->pathForEntry($entry), '/');

        return $prefix !== '' ? hi()->urls()->to($prefix . '/' . $path) : hi()->urls()->to($path);
    }

    /** Bir dilin ana sayfası. */
    public function homeUrlFor(string $code): string
    {
        $prefix = (string) ($this->locales()[$code]['prefix'] ?? '');

        return $prefix !== '' ? hi()->urls()->to($prefix) : hi()->urls()->to();
    }

    private function head(): null
    {
        if ($this->setting('hreflang', true)) {
            $this->hreflang();
        }

        if ($this->setting('switcher_css', true)) {
            echo $this->switcherCss();
        }

        return null;
    }

    /**
     * hreflang etiketleri.
     *
     * Tek içerikte dil sürümleri, liste sayfalarında dil ana sayfaları
     * bildirilir — ikincisi 0.2.0'da hiç basılmıyordu, yani `/en` sayfasının
     * `/` ile ilişkisi arama motoruna hiç söylenmiyordu.
     */
    private function hreflang(): void
    {
        $view    = hi_view();
        $entry   = $view->entry;
        $primary = $this->primaryLocale();
        $links   = [];

        if ($entry !== null) {
            foreach ($this->translationsOf($entry, true) as $code => $translation) {
                if (!isset($this->locales()[$code])) {
                    continue;
                }

                $links[$code] = $this->urlFor($translation, $code);
            }
        } elseif ($view->is(ViewContext::HOME)) {
            foreach ($this->locales() as $code => $locale) {
                if ($locale['primary'] || $locale['prefix'] !== '') {
                    $links[$code] = $this->homeUrlFor($code);
                }
            }
        }

        if (count($links) < 2) {
            return; // Tek sürüm varsa hreflang'in anlamı yok.
        }

        foreach ($links as $code => $url) {
            printf(
                '<link rel="alternate" hreflang="%s" href="%s">' . "\n",
                Str::attr(self::htmlLang($code)),
                Str::url($url)
            );
        }

        if ($this->setting('x_default', true) && isset($links[$primary])) {
            printf('<link rel="alternate" hreflang="x-default" href="%s">' . "\n", Str::url($links[$primary]));
        }
    }

    /**
     * Dil değiştirici bileşen alanları (bileşen ve blok aynı tanımı paylaşır).
     *
     * @return list<array<string, mixed>>
     */
    private function switcherFields(): array
    {
        return [
            ['key' => 'style', 'type' => 'select', 'label' => 'Biçim', 'default' => '',
             'options' => [
                 ''         => 'Ayarlardaki biçim',
                 'inline'   => 'Yan yana',
                 'list'     => 'Liste',
                 'dropdown' => 'Açılır',
             ]],
            ['key' => 'hide_missing', 'type' => 'switch', 'default' => false,
             'label' => 'Çevirisi olmayan dilleri gizle'],
        ];
    }

    /**
     * Dil değiştirici.
     *
     * JS kullanmaz: "açılır" biçim bile `<details>` ile çalışır.
     */
    public function switcher(string $style = '', ?bool $showMissing = null): string
    {
        $locales = $this->locales();

        if (count($locales) < 2) {
            return '';
        }

        $style = in_array($style, ['inline', 'list', 'dropdown'], true)
            ? $style
            : (string) $this->setting('switcher_style', 'inline');

        $showMissing ??= (bool) $this->setting('show_missing', true);

        $entry        = hi_view()->entry;
        $translations = $entry !== null ? $this->translationsOf($entry, true) : [];
        $current      = $this->currentLocale();

        $items   = '';
        $shown   = 0;
        $label   = (string) ($locales[$current]['label'] ?? $current);

        foreach ($locales as $code => $locale) {
            // Öneksiz ikincil dil sunulamıyor; bağlantısı da olmamalı.
            if (!$locale['primary'] && $locale['prefix'] === '') {
                continue;
            }

            $target = $translations[$code] ?? null;

            if ($target === null && !$showMissing && $code !== $current) {
                continue;
            }

            $url       = $target !== null ? $this->urlFor($target, $code) : $this->homeUrlFor($code);
            $isCurrent = $code === $current;

            $items .= sprintf(
                '<a class="lang-link%s%s" href="%s"%s hreflang="%s" lang="%s">%s</a>',
                $isCurrent ? ' is-current' : '',
                $target === null && !$isCurrent ? ' is-untranslated' : '',
                Str::url($url),
                $isCurrent ? ' aria-current="true"' : '',
                Str::attr(self::htmlLang($code)),
                Str::attr(self::htmlLang($code)),
                Str::html($locale['label'])
            );

            $shown++;
        }

        if ($shown < 2) {
            return '';
        }

        if ($style === 'dropdown') {
            return '<nav class="lang-switcher is-dropdown" aria-label="Dil seçimi">'
                . '<details class="lang-details"><summary class="lang-current">'
                . Str::html($label) . '</summary><div class="lang-list">' . $items . '</div></details>'
                . '</nav>';
        }

        return '<nav class="lang-switcher is-' . Str::attr($style) . '" aria-label="Dil seçimi">'
            . $items . '</nav>';
    }

    /** Değiştiricinin temel stili — ayrı dosya isteği açmamak için satır içi. */
    private function switcherCss(): string
    {
        return '<style id="hi-lang-css">'
            . '.lang-switcher{display:flex;flex-wrap:wrap;gap:.5em;align-items:center;font-size:.9em}'
            . '.lang-switcher.is-list{flex-direction:column;align-items:flex-start;gap:.25em}'
            . '.lang-link{text-decoration:none;opacity:.75}'
            . '.lang-link:hover,.lang-link:focus{opacity:1;text-decoration:underline}'
            . '.lang-link.is-current{font-weight:600;opacity:1}'
            . '.lang-link.is-untranslated{font-style:italic}'
            . '.lang-switcher.is-dropdown{display:inline-block}'
            . '.lang-details{position:relative;display:inline-block}'
            . '.lang-current{cursor:pointer;list-style:none}'
            . '.lang-current::-webkit-details-marker{display:none}'
            . '.lang-current::after{content:" \\25BE"}'
            . '.lang-details[open] .lang-list{display:flex}'
            . '.lang-details .lang-list{display:none;position:absolute;z-index:20;top:100%;left:0;'
            . 'flex-direction:column;gap:.25em;padding:.5em .75em;min-width:8em;'
            . 'background:#fff;color:#111;border:1px solid rgba(0,0,0,.12);border-radius:6px;'
            . 'box-shadow:0 6px 24px rgba(0,0,0,.12)}'
            . '@media (prefers-color-scheme:dark){.lang-details .lang-list{background:#1a1a1a;color:#eee;'
            . 'border-color:rgba(255,255,255,.14)}}'
            . '</style>' . "\n";
    }

    /* ---------------------------------------------------------------------
     * Panel
     * ------------------------------------------------------------------ */

    private function screen(): null
    {
        admin_require('settings.manage');

        $base    = 'plugin.php?eklenti=hi-lang';
        $request = hi()->request();
        $section = (string) $request->query('bolum', 'icerik');
        $editId  = max(0, (int) $request->query('duzenle', 0));

        if (!in_array($section, ['icerik', 'diller', 'davranis'], true)) {
            $section = 'icerik';
        }

        if ($request->isPost()) {
            $this->handlePost($base);
        }

        if ($editId > 0) {
            return $this->entryScreen($base, $editId);
        }

        return match ($section) {
            'diller'   => $this->localesScreen($base),
            'davranis' => $this->behaviorScreen($base),
            default    => $this->listScreen($base),
        };
    }

    /**
     * Form gönderimleri. Her yol ya yönlendirir ya da hata bırakıp döner.
     */
    private function handlePost(string $base): void
    {
        $post   = hi()->request();
        $action = $post->text('islem');

        /*
         * Dönüş adresi KULLANICI GİRDİSİNDEN alınmaz (açık yönlendirme olur);
         * yalnızca beyaz listeye alınmış bölüm adı ve tamsayı kimlikten kurulur.
         */
        $section = $post->text('bolum');
        $section = in_array($section, ['icerik', 'diller', 'davranis'], true) ? $section : 'icerik';
        $editId  = max(0, $post->int('duzenle'));

        $back = $base . '&bolum=' . $section . ($editId > 0 ? '&duzenle=' . $editId : '');

        admin_verify($back);

        if ($action === 'diller') {
            $this->saveLocales($back);
        }

        if ($action === 'davranis') {
            hi_settings($this->slug())->save($_POST, self::SECTION_BEHAVIOR);

            admin_redirect($back, 'success', 'Davranış ayarları kaydedildi.');
        }

        if ($action === 'icerik') {
            $this->saveEntryLanguage($back, $editId);
        }

        if ($action === 'coz') {
            $target = max(0, $post->int('icerik'));

            if ($target <= 0) {
                admin_redirect($back, 'error', 'İçerik seçilmedi.');
            }

            $this->unlinkEntry($target);

            admin_redirect($back, 'success', 'Çeviri bağı çözüldü.');
        }

        admin_redirect($back, 'error', 'Bilinmeyen işlem.');
    }

    /**
     * Dil tanımlarını kaydeder.
     *
     * Girdi ham `$_POST` olarak `save()`'e verilmiyor: dil kodu ve önek
     * biçimlendirme kuralına uymalı, boş satırlar düşmeli, birincil dil satır
     * sırasından çözülmeli (kod alanı düzenlenebilir olduğu için radyo değeri
     * kod olamaz — kodu değiştiren kullanıcı birincil seçimini kaybederdi).
     */
    private function saveLocales(string $back): never
    {
        $rows        = [];
        $primaryCode = '';
        $primaryRow  = (int) ($_POST['birincil'] ?? -1);
        $skipped     = 0;

        foreach ((array) ($_POST['locales'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $raw  = trim((string) ($row['code'] ?? ''));
            $code = self::normalizeCode($raw);

            if ($code === '') {
                if ($raw !== '') {
                    $skipped++;
                }

                continue;
            }

            if (isset($rows[$code])) {
                $skipped++;
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            $rows[$code] = [
                'code'   => $code,
                'label'  => $label !== '' ? $label : (self::KNOWN_LOCALES[$code][0] ?? $code),
                'prefix' => Str::slug((string) ($row['prefix'] ?? '')),
            ];

            if ((int) $index === $primaryRow) {
                $primaryCode = $code;
            }
        }

        if ($rows === []) {
            admin_redirect($back, 'error', 'En az bir geçerli dil tanımlamalısınız (örnek kod: tr_TR).');
        }

        if (count($rows) > self::MAX_LOCALES) {
            $rows = array_slice($rows, 0, self::MAX_LOCALES, true);
        }

        if ($primaryCode === '' || !isset($rows[$primaryCode])) {
            $primaryCode = (string) array_key_first($rows);
        }

        hi_settings($this->slug())->save([
            'locales' => array_values($rows),
            'primary' => $primaryCode,
        ], self::SECTION_LOCALES);

        $this->localeCache = null;
        $this->indexCache  = null;

        $message = Str::format('%d dil kaydedildi.', count($rows));

        if ($skipped > 0) {
            $message .= Str::format(' %d satır geçersiz ya da yinelenen kod içerdiği için atlandı.', $skipped);
        }

        admin_redirect($back, $skipped > 0 ? 'warning' : 'success', $message);
    }

    private function saveEntryLanguage(string $back, int $entryId): never
    {
        if ($entryId <= 0) {
            admin_redirect($back, 'error', 'İçerik seçilmedi.');
        }

        $entry = hi()->content()->find($entryId);

        if ($entry === null) {
            admin_redirect($back, 'error', 'İçerik bulunamadı.');
        }

        $code = self::normalizeCode(hi()->request()->text('dil'));

        if ($code === '' || !isset($this->locales()[$code])) {
            admin_redirect($back, 'error', 'Geçerli bir dil seçin.');
        }

        $targetId = max(0, hi()->request()->int('hedef'));

        /*
         * Bağ önce DENETLENİR, sonra iki yazma birlikte yapılır. Sıra tersine
         * olsaydı reddedilen bir bağ isteğinde dil değişikliği yazılmış kalırdı.
         */
        if ($targetId > 0) {
            $result = $this->linkEntries($entryId, $targetId, $code);

            if (!$result['ok']) {
                admin_redirect($back, 'error', $result['error']);
            }
        }

        hi()->content()->saveMeta($entryId, ['locale' => $code]);

        $this->indexCache      = null;
        $entry->meta['locale'] = $code;

        admin_redirect(
            $back,
            'success',
            $targetId > 0 ? 'Dil ve çeviri bağı kaydedildi.' : 'İçeriğin dili kaydedildi.'
        );
    }

    /* --------------------------------------------------------------- ekranlar */

    /** Ekran sekmeleri. */
    private function tabs(string $base, string $active, ?int $count = null): string
    {
        return ui_tabs([
            [
                'label'  => 'İçerikler',
                'url'    => $base,
                'active' => $active === 'icerik',
                'count'  => $count,
            ],
            [
                'label'  => 'Diller',
                'url'    => $base . '&bolum=diller',
                'active' => $active === 'diller',
                'count'  => count($this->locales()),
            ],
            [
                'label'  => 'Davranış',
                'url'    => $base . '&bolum=davranis',
                'active' => $active === 'davranis',
            ],
        ]);
    }

    private function issueNotices(): void
    {
        foreach ($this->localeIssues() as $issue) {
            echo ui_notice('warning', $issue);
        }
    }

    /**
     * İçerik listesi: dil ve çeviri durumu kolonlarıyla.
     */
    private function listScreen(string $base): null
    {
        $request = hi()->request();
        $locales = $this->locales();
        $codes   = array_keys($locales);

        $filterLocale = (string) $request->query('dil', '');
        $filterState  = (string) $request->query('ceviri', '');
        $filterType   = (string) $request->query('tur', '');
        $search       = trim((string) $request->query('ara', ''));
        $page         = max(1, (int) $request->query('sayfa', 1));

        $index    = $this->translationIndex();
        $complete = $this->completeIds();

        $include = null;
        $exclude = [];

        if ($filterLocale === 'yok') {
            $exclude = array_merge($exclude, array_keys($index['localeOf']));
        } elseif (isset($locales[$filterLocale])) {
            $include = array_keys(array_filter(
                $index['localeOf'],
                static fn(string $code): bool => $code === $filterLocale
            ));
        }

        if ($filterState === 'tam') {
            $include = $include === null ? $complete : array_values(array_intersect($include, $complete));
        } elseif ($filterState === 'eksik') {
            $exclude = array_merge($exclude, $complete);
        }

        $args = [
            'type'    => $filterType !== '' && hi()->types()->has($filterType) ? $filterType : 'all',
            'status'  => 'all',
            'search'  => $search,
            'perPage' => 30,
            'page'    => $page,
            'orderBy' => 'updated_at',
            'exclude' => array_values(array_unique(array_map('intval', $exclude))),
        ];

        if ($include !== null) {
            $args['include'] = array_values(array_unique(array_map('intval', $include)));
        }

        $result = ($include !== null && $args['include'] === [])
            ? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 30]
            : hi()->content()->query($args);

        $typeOptions = ['' => 'Tüm türler'];

        foreach (hi()->types()->all() as $type) {
            $typeOptions[$type->name] = $type->plural !== '' ? $type->plural : $type->name;
        }

        $localeOptions = ['' => 'Tüm diller', 'yok' => 'Dili belirtilmemiş'];

        foreach ($locales as $code => $locale) {
            $localeOptions[$code] = $locale['label'];
        }

        $urlFor = static function (array $changes) use ($base, $filterLocale, $filterState, $filterType, $search): string {
            $params = array_merge([
                'dil'    => $filterLocale,
                'ceviri' => $filterState,
                'tur'    => $filterType,
                'ara'    => $search,
            ], $changes);

            $query = '';

            foreach ($params as $key => $value) {
                if ((string) $value !== '') {
                    $query .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
                }
            }

            return $base . $query;
        };

        admin_head([
            'title'       => 'Diller',
            'slug'        => 'plugin:hi-lang',
            'description' => 'İçeriklerin dili ve çeviri durumu.',
            'count'       => $result['total'],
            'breadcrumb'  => [
                ['label' => 'Eklentiler', 'url' => 'plugins.php'],
                ['label' => 'HiLang'],
            ],
        ]);

        echo $this->tabs($base, 'icerik', $result['total']);
        $this->issueNotices();
        echo $this->metrics($index, $complete);
        ?>

        <section class="panel mt-2">
            <form class="filters" method="get" action="plugin.php">
                <input type="hidden" name="eklenti" value="hi-lang">

                <?= ui_select('dil', $localeOptions, $filterLocale, [
                    'class' => 'input select w-auto', 'aria-label' => 'Dil süzgeci',
                ]) ?>

                <?= ui_select('ceviri', [
                    ''      => 'Tüm çeviri durumları',
                    'tam'   => 'Tüm diller tamam',
                    'eksik' => 'Eksik çeviri',
                ], $filterState, ['class' => 'input select w-auto', 'aria-label' => 'Çeviri durumu']) ?>

                <?= ui_select('tur', $typeOptions, $filterType, [
                    'class' => 'input select w-auto', 'aria-label' => 'İçerik türü',
                ]) ?>

                <?= ui_input('ara', $search, [
                    'type' => 'search', 'placeholder' => 'Başlıkta ara',
                    'aria-label' => 'Ara', 'style' => 'width:180px',
                ]) ?>

                <button class="btn btn-sm" type="submit"><?= admin_icon('filter', 14) ?>Süz</button>

                <?php if ($filterLocale !== '' || $filterState !== '' || $filterType !== '' || $search !== '') : ?>
                    <a class="btn btn-sm" href="<?= esc_url($base) ?>">Temizle</a>
                <?php endif; ?>
            </form>

            <?php if ($result['items'] === []) : ?>
                <?= ui_empty('globe', 'Kayıt yok', 'Bu süzgeçlerle eşleşen içerik bulunamadı.') ?>
            <?php else : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>İçerik</th>
                                <th class="fit">Dil</th>
                                <th class="fit">Çeviri durumu</th>
                                <th class="fit">Grup</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['items'] as $entry) : ?>
                                <?php
                                $entryLocale = (string) ($entry->meta['locale'] ?? '');
                                $group       = $this->groupOf($entry);
                                $status      = $this->statusFor($entry->id);
                                $editUrl     = $base . '&duzenle=' . $entry->id;
                                ?>
                                <tr data-status="<?= esc_attr($entry->status) ?>">
                                    <td class="edge">
                                        <a class="cell-title" href="content-edit.php?id=<?= (int) $entry->id ?>">
                                            <?= esc_html(Str::limit($entry->title, 52)) ?>
                                        </a>
                                        <span class="cell-sub"><?= esc_html($entry->type) ?></span>
                                    </td>
                                    <td class="fit small">
                                        <?php if ($entryLocale !== '' && isset($locales[$entryLocale])) : ?>
                                            <?= esc_html($locales[$entryLocale]['label']) ?>
                                        <?php elseif ($entryLocale !== '') : ?>
                                            <span class="pill is-warn no-dot"><?= esc_html($entryLocale) ?></span>
                                        <?php else : ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fit"><?= $this->statusPills($codes, $status, $entry->id) ?></td>
                                    <td class="fit mono small muted">
                                        <?= esc_html($group !== '' ? Str::limit($group, 18) : '—') ?>
                                    </td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url($editUrl) ?>"
                                               title="Dil bilgisini düzenle" aria-label="Dil bilgisini düzenle">
                                                <?= admin_icon('globe', 15) ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <footer class="panel-foot">
                    <?= ui_pagination(
                        (int) $result['page'],
                        (int) $result['pages'],
                        static fn(int $p): string => $urlFor(['sayfa' => $p]),
                        (int) $result['total']
                    ) ?>
                </footer>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();

        return null;
    }

    /**
     * Çeviri durumu nişanları: her dil için var/yok.
     *
     * @param list<string> $codes
     * @param array<string, int> $status
     */
    private function statusPills(array $codes, array $status, int $entryId): string
    {
        $html = '<span class="row" style="gap:4px">';

        foreach ($codes as $code) {
            $id    = $status[$code] ?? 0;
            $short = esc_html(self::shortLabel($code));

            if ($id === $entryId) {
                $html .= '<span class="pill is-info no-dot" title="' . Str::attr($code . ' — bu kayıt') . '">'
                    . $short . '</span>';
                continue;
            }

            if ($id > 0) {
                $html .= '<a class="pill is-ok no-dot" href="content-edit.php?id=' . $id . '" title="'
                    . Str::attr($code . ' — çeviriyi düzenle') . '">' . $short . '</a>';
                continue;
            }

            $html .= '<a class="pill is-mute no-dot" href="'
                . esc_url('plugin.php?eklenti=hi-lang&duzenle=' . $entryId) . '" title="'
                . Str::attr($code . ' — çeviri yok, bağ kur') . '">' . $short . '</a>';
        }

        return $html . '</span>';
    }

    /**
     * @param array{localeOf: array<int, string>, groupOf: array<int, string>, groups: array<string, array<string, int>>} $index
     * @param list<int> $complete
     */
    private function metrics(array $index, array $complete): string
    {
        $metrics = [];
        $counts  = array_count_values($index['localeOf']);

        foreach ($this->locales() as $code => $locale) {
            $metrics[] = [
                'label' => $locale['label'],
                'value' => Str::number((int) ($counts[$code] ?? 0)),
                'note'  => $locale['primary'] ? 'birincil' : ($locale['prefix'] !== '' ? '/' . $locale['prefix'] : 'öneksiz'),
                'href'  => 'plugin.php?eklenti=hi-lang&dil=' . rawurlencode($code),
            ];
        }

        $metrics[] = [
            'label' => 'Tam çevrilmiş',
            'value' => Str::number(count($complete)),
            'note'  => 'tüm diller',
            'href'  => 'plugin.php?eklenti=hi-lang&ceviri=tam',
        ];

        return ui_metrics($metrics);
    }

    /**
     * Tek içeriğin dil bilgisi ekranı.
     */
    private function entryScreen(string $base, int $entryId): null
    {
        $entry = hi()->content()->find($entryId);

        if ($entry === null) {
            admin_redirect($base, 'error', 'İçerik bulunamadı.');
        }

        $locales     = $this->locales();
        $entryLocale = self::normalizeCode((string) ($entry->meta['locale'] ?? ''));
        $group       = $this->groupOf($entry);
        $status      = $this->statusFor($entryId);
        $selfUrl     = $base . '&duzenle=' . $entryId;

        $localeOptions = [];

        foreach ($locales as $code => $locale) {
            $localeOptions[$code] = $locale['label'] . ' (' . $code . ')';
        }

        admin_head([
            'title'       => 'Dil bilgisi',
            'slug'        => 'plugin:hi-lang',
            'narrow'      => true,
            'description' => Str::limit($entry->title, 80),
            'breadcrumb'  => [
                ['label' => 'Eklentiler', 'url' => 'plugins.php'],
                ['label' => 'Diller', 'url' => $base],
                ['label' => Str::limit($entry->title, 32)],
            ],
            'actions'     => '<a class="btn btn-sm" href="content-edit.php?id=' . $entryId . '">'
                . admin_icon('edit', 14) . 'İçeriği düzenle</a>',
        ]);

        $this->issueNotices();
        ?>

        <form class="panel" method="post" action="<?= esc_url($selfUrl) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="icerik">
            <input type="hidden" name="bolum" value="icerik">
            <input type="hidden" name="duzenle" value="<?= (int) $entryId ?>">

            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Bu içeriğin dili</h2>
                    <p class="panel-sub">Aynı çeviri grubundaki içerikler birbirinin dil sürümü sayılır</p>
                </div>
            </header>

            <div class="panel-body">
                <?= ui_field(
                    'Dil',
                    ui_select('dil', $localeOptions, $entryLocale !== '' ? $entryLocale : $this->primaryLocale()),
                    'Belirtilmezse içerik birincil dilde sayılır.',
                    'dil',
                    true
                ) ?>

                <?php
                $candidates = $this->linkCandidates($entry, $group);
                ?>

                <?php if ($candidates === []) : ?>
                    <?= ui_field(
                        'Çeviri bağı',
                        '<p class="muted small mb-0">Bağ kurulabilecek başka içerik yok.</p>',
                        'Önce başka bir dilde içerik oluşturun.'
                    ) ?>
                <?php else : ?>
                    <?= ui_field(
                        'Şu içeriğin çevirisi yap',
                        ui_select('hedef', array_merge(['0' => '— değişiklik yok —'], $candidates), '0'),
                        'Seçilen içeriğin çeviri grubuna katılır. Grubu olmayan içerik için grup oluşturulur.',
                        'hedef'
                    ) ?>
                <?php endif; ?>

                <?= ui_field(
                    'Çeviri grubu',
                    '<p class="mono small mb-0">' . esc_html($group !== '' ? $group : '— yok —') . '</p>',
                    'Grup anahtarı içeriğin <code>translation_group</code> meta değerinde tutulur.'
                ) ?>
            </div>

            <footer class="panel-foot">
                <a class="btn btn-sm" href="<?= esc_url($base) ?>">Listeye dön</a>
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit"><?= admin_icon('save', 14) ?>Kaydet</button>
            </footer>
        </form>

        <section class="panel mt-3">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Dil sürümleri</h2>
                    <p class="panel-sub"><?= esc_html(Str::format(
                        '%d dilden %d tanesinde karşılığı var',
                        count($locales),
                        count(array_intersect_key($status, $locales))
                    )) ?></p>
                </div>
            </header>

            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th class="fit">Dil</th>
                            <th>İçerik</th>
                            <th class="fit">Adres</th>
                            <th class="fit"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locales as $code => $locale) : ?>
                            <?php
                            $targetId = $status[$code] ?? 0;
                            $target   = $targetId > 0 ? hi()->content()->find($targetId) : null;
                            ?>
                            <tr<?= $target !== null ? ' data-status="' . esc_attr($target->status) . '"' : '' ?>>
                                <td class="fit<?= $target !== null ? ' edge' : '' ?>">
                                    <span class="pill <?= $targetId > 0 ? 'is-ok' : 'is-mute' ?> no-dot">
                                        <?= esc_html(self::shortLabel($code)) ?>
                                    </span>
                                    <span class="cell-sub"><?= esc_html($locale['label']) ?></span>
                                </td>
                                <td>
                                    <?php if ($target !== null) : ?>
                                        <a class="cell-title" href="content-edit.php?id=<?= (int) $target->id ?>">
                                            <?= esc_html(Str::limit($target->title, 46)) ?>
                                        </a>
                                        <?php if ($target->id === $entryId) : ?>
                                            <span class="cell-sub">bu kayıt</span>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span class="muted small">çeviri yok</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fit mono small muted">
                                    <?php if ($target !== null) : ?>
                                        <?= esc_html(Str::limit($this->urlFor($target, $code), 40)) ?>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="fit">
                                    <?php if ($target !== null && $group !== '') : ?>
                                        <form method="post" action="<?= esc_url($selfUrl) ?>">
                                            <?= hi_csrf_field() ?>
                                            <input type="hidden" name="islem" value="coz">
                                            <input type="hidden" name="bolum" value="icerik">
                                            <input type="hidden" name="duzenle" value="<?= (int) $entryId ?>">
                                            <input type="hidden" name="icerik" value="<?= (int) $target->id ?>">
                                            <button class="btn btn-sm" type="submit"
                                                <?= ui_confirm('Bu içeriğin çeviri bağı çözülecek. Emin misiniz?') ?>>
                                                Bağı çöz
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php
        admin_foot();

        return null;
    }

    /**
     * Bağ kurulabilecek içerikler: kendisi ve aynı gruptakiler hariç.
     *
     * @return array<int, string>
     */
    private function linkCandidates(Entry $entry, string $group): array
    {
        $index   = $this->translationIndex();
        $locales = $this->locales();
        $primary = $this->primaryLocale();

        $result = hi()->content()->query([
            'type'          => $entry->type,
            'status'        => 'all',
            'perPage'       => 100,
            'orderBy'       => 'updated_at',
            'withRelations' => false,
        ]);

        $options = [];

        foreach ($result['items'] as $candidate) {
            if ($candidate->id === $entry->id) {
                continue;
            }

            if ($group !== '' && ($index['groupOf'][$candidate->id] ?? '') === $group) {
                continue;
            }

            $code  = $index['localeOf'][$candidate->id] ?? $primary;
            $label = $locales[$code]['label'] ?? $code;

            $options[$candidate->id] = Str::limit($candidate->title, 44) . ' · ' . $label;
        }

        return $options;
    }

    /**
     * Dil tanımları ekranı.
     */
    private function localesScreen(string $base): null
    {
        $locales = $this->locales();
        $codes   = array_keys($locales);
        $rows    = array_values($locales);
        $count   = max(min(count($codes) + 1, self::MAX_LOCALES), 3);

        $primaryIndex = 0;

        foreach ($rows as $index => $locale) {
            if ($locale['primary']) {
                $primaryIndex = $index;
                break;
            }
        }

        $suggest = [];

        foreach (self::KNOWN_LOCALES as $code => $definition) {
            $suggest[$code] = ['label' => $definition[0], 'prefix' => $definition[1]];
        }

        hi_admin_data('hi-lang', [
            'max'     => self::MAX_LOCALES,
            'rows'    => $count,
            'suggest' => $suggest,
        ]);

        hi_admin_script($this->assetUrl('admin/hi-lang.js') . '?v=' . rawurlencode($this->manifest()->version));

        admin_head([
            'title'       => 'Diller',
            'slug'        => 'plugin:hi-lang',
            'narrow'      => true,
            'description' => 'Tanımlı diller, adres önekleri ve birincil dil.',
            'count'       => count($locales),
            'breadcrumb'  => [
                ['label' => 'Eklentiler', 'url' => 'plugins.php'],
                ['label' => 'HiLang'],
            ],
        ]);

        echo $this->tabs($base, 'diller');
        $this->issueNotices();
        ?>

        <form class="panel" method="post" action="<?= esc_url($base . '&bolum=diller') ?>"
              data-hi-lang-locales data-max="<?= (int) self::MAX_LOCALES ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="diller">
            <input type="hidden" name="bolum" value="diller">

            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Dil tanımları</h2>
                    <p class="panel-sub">
                        Birincil dil öneksiz sunulur; ikincil diller <code>/en/…</code> gibi
                        önekli adreslerden gelir
                    </p>
                </div>
            </header>

            <div class="panel-body">
                <p class="hint mt-0 mb-2">
                    Dil kodu <code>tr</code> ya da <code>tr_TR</code> biçiminde olmalı.
                    Bir dili kaldırmak için kod alanını boşaltın.
                </p>

                <div data-hi-lang-rows>
                    <?php for ($i = 0; $i < $count; $i++) : ?>
                        <?php
                        $code   = $codes[$i] ?? '';
                        $locale = $rows[$i] ?? ['label' => '', 'prefix' => '', 'primary' => false];
                        ?>
                        <div class="node" style="display:block;padding:10px 12px" data-hi-lang-row
                             data-index="<?= (int) $i ?>">
                            <div class="field-row" style="gap:8px">
                                <?= ui_input('locales[' . $i . '][code]', $code, [
                                    'id'          => 'lang-code-' . $i,
                                    'class'       => 'input mono',
                                    'placeholder' => 'tr_TR',
                                    'aria-label'  => 'Dil kodu ' . ($i + 1),
                                    'data-role'   => 'code',
                                    'autocomplete' => 'off',
                                ]) ?>
                                <?= ui_input('locales[' . $i . '][label]', (string) $locale['label'], [
                                    'id'          => 'lang-label-' . $i,
                                    'placeholder' => 'Türkçe',
                                    'aria-label'  => 'Dil adı ' . ($i + 1),
                                    'data-role'   => 'label',
                                ]) ?>
                            </div>
                            <div class="field-row mt-1" style="gap:8px;align-items:center">
                                <?= ui_input('locales[' . $i . '][prefix]', (string) $locale['prefix'], [
                                    'id'          => 'lang-prefix-' . $i,
                                    'class'       => 'input mono',
                                    'placeholder' => 'önek (boş = sunulmaz)',
                                    'aria-label'  => 'Adres öneki ' . ($i + 1),
                                    'data-role'   => 'prefix',
                                ]) ?>
                                <label class="check">
                                    <input type="radio" name="birincil" value="<?= (int) $i ?>"
                                        <?= $i === $primaryIndex ? 'checked' : '' ?>>
                                    <span class="check-body">Birincil</span>
                                </label>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <?php
                /*
                 * JS KAPALIYKEN: yukarıda mevcut dillerden bir fazla satır zaten
                 * basılıyor, yani yeni dil eklemek için betiğe ihtiyaç yok.
                 * Düğme yalnızca bir satırdan fazlasını aynı anda eklemek için.
                 */
                ?>
                <button class="btn btn-sm mt-2" type="button" data-hi-lang-add hidden>
                    <?= admin_icon('plus', 14) ?>Satır ekle
                </button>
            </div>

            <footer class="panel-foot">
                <span class="muted small">
                    En fazla <?= (int) self::MAX_LOCALES ?> dil
                </span>
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit"><?= admin_icon('save', 14) ?>Kaydet</button>
            </footer>
        </form>

        <section class="panel mt-3">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Nasıl çalışır</h2>
                </div>
            </header>
            <div class="panel-body">
                <ul class="kv">
                    <li><span class="k">Arayüz çevirisi</span><span class="v">çekirdek</span></li>
                    <li><span class="k">Dil dosyaları</span><span class="v mono">src/languages/</span></li>
                    <li><span class="k">İçerik dili</span><span class="v mono">locale</span></li>
                    <li><span class="k">Bağ anahtarı</span><span class="v mono">translation_group</span></li>
                    <li><span class="k">Ayar satırı</span><span class="v mono">plugin.hi-lang.settings</span></li>
                </ul>
                <p class="hint">
                    Dil değiştiriciyi göstermek için <strong>Görünüm → Bileşenler</strong> bölümünden
                    "Dil Değiştirici" bileşenini bir alana ekleyin; içeriğe yerleştirmek için aynı adlı
                    bloğu kullanın. Temada <code>echo hi_filter('hi_lang.switcher_html', '');</code> de çalışır.
                </p>
            </div>
        </section>

        <?php
        admin_foot();

        return null;
    }

    /**
     * Davranış ayarları ekranı.
     */
    private function behaviorScreen(string $base): null
    {
        $settings = hi_settings($this->slug());
        $values   = $settings->all();

        admin_head([
            'title'       => 'Diller',
            'slug'        => 'plugin:hi-lang',
            'narrow'      => true,
            'description' => 'hreflang, yedekleme ve dil değiştirici davranışı.',
            'breadcrumb'  => [
                ['label' => 'Eklentiler', 'url' => 'plugins.php'],
                ['label' => 'HiLang'],
            ],
        ]);

        echo $this->tabs($base, 'davranis');
        ?>

        <form class="panel" method="post" action="<?= esc_url($base . '&bolum=davranis') ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="davranis">
            <input type="hidden" name="bolum" value="davranis">

            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Davranış</h2>
                    <p class="panel-sub">Ön yüzde neyin basılacağı ve eksik çeviride ne olacağı</p>
                </div>
            </header>

            <div class="panel-body">
                <?php foreach ($settings->fields(self::SECTION_BEHAVIOR) as $field) : ?>
                    <?php if ($field->type === 'switch') : ?>
                        <div class="mb-2">
                            <?= ui_switch(
                                $field->key,
                                (bool) ($values[$field->key] ?? false),
                                $field->label,
                                $field->help
                            ) ?>
                        </div>
                    <?php else : ?>
                        <?= ui_field(
                            $field->label,
                            ui_select($field->key, $field->options, (string) ($values[$field->key] ?? '')),
                            $field->help,
                            $field->key
                        ) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <footer class="panel-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit"><?= admin_icon('save', 14) ?>Kaydet</button>
            </footer>
        </form>

        <?php
        admin_foot();

        return null;
    }
}
