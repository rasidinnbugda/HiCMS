<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Extension\Settings;
use HiCMS\Http\Request;
use HiCMS\Http\Response;
use HiCMS\Http\Router;
use HiCMS\Plugin\Plugin as BasePlugin;

/**
 * HiSEO — arama motoru paketi
 *
 * Altı iş yapar:
 *   1. İçerik başına arama başlığı / açıklaması (content_meta üzerinde)
 *   2. `<head>` etiketleri: title, description, canonical, robots, og:*, twitter:*
 *   3. JSON-LD yapısal veri (WebSite, BlogPosting, BreadcrumbList)
 *   4. İçerik türüne göre bölünmüş XML site haritası + robots.txt yönetimi
 *   5. 301/302/307/308 yönlendirme yöneticisi (joker karakterli kaynak dahil)
 *   6. 404 yakalayıcı: kırılan her adres birikir, panel yönlendirme önerir
 *
 * Hiçbir işlev çekirdeğe sızmaz. Eklenti kapatıldığında `forgetOwner()` bütün
 * kancaları söker; rotalar, etiketler ve panel ekranı kaybolur, içerik ve
 * yönlendirme tabloları olduğu gibi kalır.
 *
 * 0.3.0 SÖZLEŞMELERİ
 *   • Ayarlar `hi_settings('hi-seo')` ile bildirimsel: tek option satırı,
 *     temizleme ve varsayılan birleştirme çekirdeğin işi. Elle option okuma yok.
 *   • `forget()` KULLANILMAZ — kancaları çekirdek söker (bkz. PluginManager).
 *   • Panel verisi `hi_admin_data()` ile JSON olarak basılır; satır içi
 *     `<script>window.X = …</script>` anında sayfa geçişinde çalışmaz.
 */
final class Plugin extends BasePlugin
{
    /** Panel ekranının kendi adresi. */
    public const SELF_URL = 'plugin.php?eklenti=hi-seo';

    public function boot(): void
    {
        $settings  = self::settings();
        $entrySeo  = new EntrySeo();
        $meta      = new Meta($settings, $entrySeo);
        $sitemap   = new Sitemap($settings, $entrySeo);
        $redirects = new Redirects($settings);

        /* --------------------------------------------------------------
         * Ön yüz rotaları
         * ----------------------------------------------------------- */

        hi_on('routing.register', static function (Router $router) use ($settings, $sitemap): void {
            if ((bool) $settings->get('sitemap', true)) {
                $router->get('/sitemap.xml', static fn(): Response => $sitemap->index(), 'seo.sitemap', 1);

                /*
                 * Site haritası içerik türüne göre bölünür; dizin dosyası
                 * çocuklarını buradan adresler. Yer tutucu kısıtı daraltıldı
                 * ki "/sitemap-<herhangi bir şey>.xml" isteği tür adı
                 * ayrıştırmasını zorlamasın.
                 */
                $router->get(
                    '/sitemap-{parca:[a-z0-9_-]+}.xml',
                    static fn(array $params): Response => $sitemap->part((string) $params['parca']),
                    'seo.sitemap.part',
                    1
                );
            }

            if ((bool) $settings->get('robots', true)) {
                $router->get('/robots.txt', static fn(): Response => $sitemap->robots(), 'seo.robots', 1);
            }
        });

        // Yönlendirmeler istek çözülmeden önce denetlenir; 404 kaydı ise
        // yanıt gönderildikten sonra (durum kodu belli olunca) yazılır.
        hi_on('routing.before', static function (Request $request) use ($redirects): void {
            $redirects->handle($request);
        });

        /* --------------------------------------------------------------
         * <head> — başlık, açıklama, etiketler
         * ----------------------------------------------------------- */

        hi_add_filter('theme.document_title', static fn(mixed $title): string => $meta->title($title));
        hi_add_filter('theme.meta_description', static fn(mixed $text): string => $meta->description($text));

        hi_on('theme.head', static fn(): null => $meta->head());

        /* --------------------------------------------------------------
         * Panel
         * ----------------------------------------------------------- */

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-seo',
                'label' => 'SEO',
                'icon'  => 'target',
                'url'   => self::SELF_URL,
            ]);
        });

        /*
         * Varlık adresleri SÜRÜMLE damgalanır. `admin_asset()` yalnızca çekirdek
         * dosyalarını damgalıyor; eklenti kendi damgasını basmazsa güncellemeden
         * sonra tarayıcı eski betiği servis eder.
         */
        $stamp     = '?v=' . rawurlencode($this->manifest()->version);
        $scriptUrl = $this->assetUrl('assets/js/seo.js') . $stamp;
        $styleUrl  = $this->assetUrl('assets/css/seo.css') . $stamp;

        hi_on('admin.page.hi-seo', static function () use (
            $settings,
            $entrySeo,
            $redirects,
            $sitemap,
            $scriptUrl,
            $styleUrl
        ): null {
            return (new Screen($settings, $entrySeo, $redirects, $sitemap, $scriptUrl, $styleUrl))->render();
        });
    }

    /**
     * Ayar tanımı.
     *
     * Bildirimsel: alanlar tarif edilir, formu basmak / doğrulamak / temizlemek
     * / saklamak çekirdeğin işi olur. Tanım istek başına bir kez kurulur;
     * `hi_settings()` eklenti başına aynı nesneyi döndürdüğü için ekran, ön yüz
     * ve site haritası aynı tanımı paylaşır.
     */
    public static function settings(): Settings
    {
        $settings = hi_settings('hi-seo');

        if ($settings->sections() !== []) {
            return $settings;
        }

        $settings->section('genel', 'Genel', [
            ['key' => 'title_pattern', 'type' => 'text', 'label' => 'Başlık kalıbı',
             'default' => '%baslik% · %site%',
             'placeholder' => '%baslik% · %site%',
             'help' => 'Yer tutucular: %baslik%, %site%, %slogan%, %sayfa%. Boş bırakılırsa '
                . 'çekirdeğin başlığı kullanılır.'],
            ['key' => 'home_title', 'type' => 'text', 'label' => 'Ana sayfa başlığı',
             'default' => '', 'help' => 'Boşsa site adı ve slogan kullanılır.'],
            ['key' => 'default_description', 'type' => 'textarea', 'label' => 'Yedek açıklama',
             'rows' => 3, 'default' => '',
             'help' => 'İçeriğin kendi açıklaması ve özeti yoksa bu metin basılır.'],
            ['key' => 'default_image', 'type' => 'text', 'label' => 'Yedek paylaşım görseli',
             'default' => '', 'placeholder' => 'https://…/paylasim.jpg',
             'help' => 'Öne çıkan görseli olmayan sayfalar için og:image adresi. Etkin tema kendi '
                . 'og:image etiketini basıyorsa bu adres yalnızca "Her zaman bas" modunda kullanılır.'],
        ], 'Arama sonucunda ve paylaşımda görünen metinler.');

        $settings->section('etiket', 'Etiketler', [
            ['key' => 'json_ld', 'type' => 'switch', 'label' => 'JSON-LD yapısal veri', 'default' => true,
             'help' => 'WebSite, BlogPosting ve BreadcrumbList şemaları basılır.'],
            ['key' => 'og_mode', 'type' => 'select', 'label' => 'Paylaşım etiketleri (og:*, twitter:*)',
             'default' => 'auto',
             'options' => [
                 'auto'   => 'Otomatik — temanın basmadıklarını tamamla',
                 'always' => 'Her zaman bas',
                 'off'    => 'Basma',
             ],
             'help' => 'Otomatik seçenekte etkin temanın şablonları taranır ve yalnızca eksik '
                . 'etiketler eklenir; hiçbir etiket iki kez yazılmaz.'],
            ['key' => 'twitter_card', 'type' => 'select', 'label' => 'Twitter kartı',
             'default' => 'summary_large_image',
             'options' => [
                 'summary_large_image' => 'Büyük görsel',
                 'summary'             => 'Özet',
             ]],
            ['key' => 'twitter_site', 'type' => 'text', 'label' => 'Twitter hesabı', 'default' => '',
             'placeholder' => '@site', 'help' => 'twitter:site etiketine yazılır.'],
            ['key' => 'noindex_search', 'type' => 'switch', 'label' => 'Arama sonuçlarını dizine ekleme',
             'default' => true],
            ['key' => 'noindex_archives', 'type' => 'switch', 'label' => 'Arşivleri dizine ekleme',
             'default' => false, 'help' => 'Kategori, etiket, tür arşivi ve yazar sayfaları.'],
            ['key' => 'noindex_paged', 'type' => 'switch', 'label' => 'İkinci ve sonraki sayfaları dizine ekleme',
             'default' => false],
        ], 'Hangi etiketlerin basıldığı ve neyin dizine girmediği.');

        $settings->section('sitemap', 'Site haritası', [
            ['key' => 'sitemap', 'type' => 'switch', 'label' => '/sitemap.xml üret', 'default' => true,
             'help' => 'Kapatılırsa adres 404 döner.'],
            ['key' => 'sitemap_terms', 'type' => 'switch', 'label' => 'Taksonomi terimlerini ekle',
             'default' => true],
            ['key' => 'sitemap_authors', 'type' => 'switch', 'label' => 'Yazar arşivlerini ekle',
             'default' => false],
            ['key' => 'sitemap_chunk', 'type' => 'number', 'label' => 'Dosya başına adres',
             'default' => 500, 'help' => 'Aşıldığında tür kendi içinde parçalanır (50–5000).'],
            ['key' => 'sitemap_exclude', 'type' => 'lines', 'label' => 'Dışlanan içerik türleri',
             'default' => [], 'rows' => 3, 'help' => 'Her satıra bir tür adı (örnek: page).'],
        ], 'Arama motorlarına verilen adres listesi.');

        $settings->section('robots', 'robots.txt', [
            ['key' => 'robots', 'type' => 'switch', 'label' => '/robots.txt üret', 'default' => true],
            ['key' => 'robots_sitemap', 'type' => 'switch', 'label' => 'Sitemap satırı ekle', 'default' => true],
            ['key' => 'crawl_delay', 'type' => 'number', 'label' => 'Crawl-delay', 'default' => 0,
             'help' => '0 ise satır basılmaz.'],
            ['key' => 'robots_disallow', 'type' => 'lines', 'label' => 'Ek Disallow yolları',
             'default' => [], 'rows' => 3, 'help' => 'Her satıra bir yol (örnek: /gizli).'],
            ['key' => 'robots_extra', 'type' => 'code', 'label' => 'Serbest ek', 'default' => '',
             'rows' => 4, 'help' => 'Otomatik kuralların altına olduğu gibi eklenir.'],
        ], 'Tarayıcılara verilen kurallar.');

        $settings->section('yonlendirme', 'Yönlendirme', [
            ['key' => 'keep_query', 'type' => 'switch', 'label' => 'Sorgu dizesini hedefe taşı',
             'default' => false, 'help' => 'Hedefte zaten ? varsa taşınmaz.'],
            ['key' => 'log_404', 'type' => 'switch', 'label' => 'Bulunamayan adresleri kaydet',
             'default' => true],
            ['key' => 'log_404_max', 'type' => 'number', 'label' => 'Kayıt sınırı', 'default' => 300,
             'help' => 'Aşılınca en eski isabetli satırlar silinir (20–5000).'],
            ['key' => 'suggest', 'type' => 'switch', 'label' => 'Yönlendirme önerisi hesapla',
             'default' => true, 'help' => 'Kırılan adresi mevcut kısa adlarla karşılaştırır.'],
        ], '404 alan adresler ve kurtarma kuralları.');

        return $settings;
    }

    /**
     * Etkinleştirmede ayar satırı açıkça yazılır.
     *
     * Gerekli değil (okuma varsayılanlarla birleşiyor) ama satırın var olması
     * yedek dosyasında ve tanılamada ayarların görünmesini sağlıyor.
     */
    public function activate(): void
    {
        $settings = self::settings();
        $settings->save($settings->all(), null, true);
    }

    /**
     * Kaldırma: ayar satırı silinir. Tablolar migration geri alımıyla düşer,
     * içerik başına yazılan SEO alanları içerikle birlikte kalır.
     */
    public function uninstall(): void
    {
        // Settings::forget() ayar SATIRINI siler; Dispatcher::forget() ile ilgisi
        // yoktur — kancalara bu eklenti hiçbir yerde elle dokunmaz.
        self::settings()->forget();
    }
}
