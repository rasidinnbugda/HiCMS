<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Events\Media\Uploaded;
use HiCMS\Events\Render\BlockRendering;
use HiCMS\Extension\Settings;
use HiCMS\Plugin\Plugin as BasePlugin;
use Throwable;

/**
 * HiMedia — medya boru hattı
 *
 * Çekirdek yalnızca dosyayı güvenle kaydeder ve `sizes` doluysa srcset basar.
 * Bu eklenti o boşluğu doldurur:
 *
 *   1. Yükleme anında türev genişlikler + WebP/AVIF kopyaları (ayardan kapanır)
 *   2. Görsel bloğunu `<picture>` olarak basma — format seçimi tarayıcıda
 *   3. Var olan medya için toplu üretim (parti parti, kaldığı yerden sürer)
 *   4. Kullanılmayan dosya denetimi — hiçbir içerikte geçmeyen medya
 *   5. Alt metin denetimi — alt metni boş görseller, tek ekrandan doldurulur
 *   6. Klasör ve etiketle gruplama, süzgeçli kitaplık
 *
 * GD yoksa: üretimle ilgili her şey sessizce devre dışı kalır, panelde gerekçe
 * yazılır, ölümcül hata verilmez. Denetim ve gruplama GD'siz de çalışır.
 *
 * Eklenti kapatıldığında hiçbir şey bozulmaz: kancaları çekirdek söker, var
 * olan kopyalar diskte ve `sizes` içinde kalır, tema aynı kodla çalışır.
 *
 * 0.3.0 SÖZLEŞMELERİ
 *   • Ayarlar `hi_settings('hi-media')` ile bildirimsel; elle option okuma yok.
 *     0.2.0'dan kalan tek tek option satırları ilk açılışta taşınır.
 *   • `forget()` KULLANILMAZ — kancaları `forgetOwner()` ile çekirdek söker.
 *   • Panel verisi `hi_admin_data()` ile JSON olarak basılır; kurulum
 *     `HiAdmin.onMount()` içinde yapılır.
 */
final class Plugin extends BasePlugin
{
    /** Panel ekranının kendi adresi. */
    public const SELF_URL = 'plugin.php?eklenti=hi-media';

    /** 0.2.0'dan taşınacak eski option anahtarları. */
    private const LEGACY_KEYS = ['auto', 'webp', 'quality'];

    public function boot(): void
    {
        $settings = self::settings();

        $this->migrateLegacyOptions($settings);

        $store    = new Store(hi()->db());
        $pipeline = new Pipeline($settings, $store);

        /* --------------------------------------------------------------
         * Yükleme anında üretim
         * ----------------------------------------------------------- */

        hi_listen(Uploaded::class, static function (Uploaded $event) use ($pipeline): void {
            if (!$pipeline->isAutomatic() || !Processor::processable($event->item)) {
                return;
            }

            /*
             * `sizes` burada YAZILMAZ: Uploader olaydan sonra `$event->item->sizes`
             * dolu ise kendisi kaydediyor. İki kez yazmamak için persistSizes
             * kapalı; denetim kaydı da yükleme başına bir satırla yeterli.
             */
            $pipeline->run($event->item, persistSizes: false, audit: false);
        });

        /*
         * Dış çağrı noktası: başka bir eklenti ya da tema tek bir dosyanın
         * kopyalarını yeniden ürettirebilir.
         */
        hi_on('media.regenerate', static function (int $mediaId) use ($pipeline): void {
            $item = hi()->mediaRepo()->find($mediaId);

            if ($item !== null) {
                $pipeline->run($item);
            }
        });

        /* --------------------------------------------------------------
         * Ön yüz: <picture> yükseltmesi
         * ----------------------------------------------------------- */

        if ((bool) $settings->get('picture', true)) {
            $picture = new Picture($store);

            hi_listen(BlockRendering::class, static function (BlockRendering $event) use ($picture): void {
                $picture->upgrade($event);
            });
        }

        /* --------------------------------------------------------------
         * Panel
         * ----------------------------------------------------------- */

        hi_on('admin.notices', static function () use ($settings): void {
            if (Processor::hasGd()) {
                self::warnAboutUnsupportedFormats($settings);

                return;
            }

            echo ui_notice(
                'warning',
                'HiMedia kopya üretimi için PHP GD eklentisi gerekiyor; sunucuda etkin değil. '
                . 'Yüklemeler etkilenmez, yalnızca türev ve WebP/AVIF üretimi durur.',
                '<a class="btn btn-sm" href="' . esc_url(self::SELF_URL) . '">Ayrıntı</a>'
            );
        });

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('media.upload')) {
                return;
            }

            $event->add('content', [
                'slug'  => 'plugin:hi-media',
                'label' => 'Medya boru hattı',
                'icon'  => 'sliders',
                'url'   => self::SELF_URL,
            ]);
        });

        $scriptUrl = $this->assetUrl('assets/js/media.js') . '?v=' . rawurlencode($this->manifest()->version);

        hi_on('admin.page.hi-media', static function () use ($settings, $store, $pipeline, $scriptUrl): null {
            return (new Screen(
                $settings,
                $store,
                $pipeline,
                new Usage(hi()->db()),
                $scriptUrl
            ))->render();
        });
    }

    /**
     * Ayar tanımı.
     *
     * Bildirimsel: alanlar tarif edilir; formu basmak, doğrulamak, temizlemek ve
     * saklamak çekirdeğin işi olur. `hi_settings()` eklenti başına aynı nesneyi
     * döndürdüğü için ön yüz, panel ve boru hattı aynı tanımı paylaşır.
     */
    public static function settings(): Settings
    {
        $settings = hi_settings('hi-media');

        if ($settings->sections() !== []) {
            return $settings;
        }

        $settings->section('uretim', 'Üretim', [
            ['key' => 'auto', 'type' => 'switch', 'label' => 'Yükleme anında üret', 'default' => true,
             'help' => 'Kapalıysa kopyalar yalnızca Toplu işlem ekranından üretilir.'],
            ['key' => 'webp', 'type' => 'switch', 'label' => 'WebP kopyası üret', 'default' => true,
             'help' => 'Birincil format WebP olur ve srcset ona yazılır. Kapatılırsa '
                . 'türevler kaynağın formatında üretilir.'],
            ['key' => 'avif', 'type' => 'switch', 'label' => 'AVIF kopyası üret', 'default' => false,
             'help' => 'WebP\'den küçük ama üretimi belirgin biçimde yavaştır. AVIF kopyalar '
                . 'srcset\'e girmez; <picture> ile sunulur.'],
            ['key' => 'picture', 'type' => 'switch', 'label' => 'Modern biçimleri <picture> ile sun',
             'default' => true,
             'help' => 'AVIF kopyalar srcset\'e giremez (tek srcset iki biçim taşıyamaz); bu seçenek '
                . 'onları görsel bloğunda <source> olarak ekler. Çekirdek zaten <picture> basıyorsa '
                . 'yalnızca eksik biçim eklenir, iç içe sarma olmaz.'],
        ], 'Hangi kopyaların ne zaman üretileceği.');

        $settings->section('kalite', 'Kalite ve ölçü', [
            ['key' => 'quality', 'type' => 'number', 'label' => 'JPEG / WebP kalitesi', 'default' => 82,
             'help' => '40–95 arası. Yükseldikçe dosya büyür; 80 civarı gözle ayırt edilmez.'],
            ['key' => 'avif_quality', 'type' => 'number', 'label' => 'AVIF kalitesi', 'default' => 52,
             'help' => '30–80 arası. AVIF aynı görsel kaliteyi daha düşük sayıda verir.'],
            ['key' => 'widths', 'type' => 'lines', 'label' => 'Türev genişlikleri', 'rows' => 4,
             'default' => [480, 800, 1280, 1920],
             'help' => 'Her satıra bir piksel değeri. Kaynaktan büyük olanlar atlanır. '
                . 'Değiştirip toplu üretim çalıştırırsanız eski dosyalar silinir.'],
            ['key' => 'batch', 'type' => 'number', 'label' => 'Toplu işlemde parti boyutu', 'default' => 8,
             'help' => '1–50 arası. AVIF açıksa küçük tutun; her görsel saniyeler alabilir.'],
        ], 'Sıkıştırma ve üretilecek ölçüler.');

        return $settings;
    }

    /**
     * Etkinleştirme: ayar satırı açıkça yazılır.
     *
     * Gerekli değil (okuma varsayılanlarla birleşiyor) ama satırın var olması
     * yedekte ve tanılamada ayarların görünmesini sağlıyor.
     */
    public function activate(): void
    {
        $settings = self::settings();
        $settings->save($settings->all(), null, true);
    }

    /**
     * Kaldırma: üretilen her kopya diskten silinir, `media.sizes` boşaltılır.
     *
     * BU ADIM ATLANAMAZ. `sizes` içindeki adresler bu eklentinin ürettiği
     * dosyaları gösteriyor; dosyalar silinip kayıt bırakılırsa tema kırık
     * srcset basar. Tersi de olmaz: kayıt silinip dosya bırakılırsa kimsenin
     * bakmadığı yüzlerce dosya diskte kalır.
     */
    public function uninstall(): void
    {
        try {
            $store   = new Store(hi()->db());
            $uploads = hi()->uploader()->uploadsDir();
            $touched = [];

            foreach ($store->allVariants() as $variant) {
                $mediaId = (int) ($variant['media_id'] ?? 0);
                $file    = (string) ($variant['file'] ?? '');

                if ($file !== '' && is_file($uploads . '/' . $file)) {
                    @unlink($uploads . '/' . $file);
                }

                if ($mediaId > 0) {
                    $touched[$mediaId] = true;
                }
            }

            // Medya başına tek güncelleme: kırık srcset kalmasın.
            foreach (array_keys($touched) as $mediaId) {
                hi()->mediaRepo()->updateSizes($mediaId, []);
            }
        } catch (Throwable) {
            // Kaldırma her koşulda tamamlanmalı; tablolar migration ile düşer.
        }

        self::settings()->forget();
        hi()->options()->delete($this->optionKey('legacy_moved'));
    }

    /* ---------------------------------------------------------------------
     * Göç
     * ------------------------------------------------------------------ */

    /**
     * 0.2.0 ayarlarını yeni tek satırlık depoya taşır.
     *
     * Eski sürüm her ayarı kendi option satırında tutuyordu
     * (`plugin.hi-media.auto` gibi). Taşıma bir kez yapılır ve bayrakla
     * işaretlenir; bayrak autoload önbelleğinden okunduğu için sonraki
     * isteklerde ek sorgu çıkmaz.
     */
    private function migrateLegacyOptions(Settings $settings): void
    {
        if ($this->option('legacy_moved', false) === true) {
            return;
        }

        $legacy = [];

        foreach (self::LEGACY_KEYS as $key) {
            $stored = $this->option($key, null);

            if ($stored !== null) {
                $legacy[$key] = $stored;
            }
        }

        if ($legacy !== []) {
            // Kısmi yazma: verilmeyen ayarlar varsayılanda kalır, silinmez.
            $settings->save($legacy, null, true);

            foreach (array_keys($legacy) as $key) {
                hi()->options()->delete($this->optionKey($key));
            }
        }

        $this->setOption('legacy_moved', true);
    }

    /**
     * Ayar açıkken sunucu desteklemiyorsa sessiz kalmak yerine söyle.
     */
    private static function warnAboutUnsupportedFormats(Settings $settings): void
    {
        $missing = [];

        if ((bool) $settings->get('webp', true) && !Processor::hasWebp()) {
            $missing[] = 'WebP';
        }

        if ((bool) $settings->get('avif', false) && !Processor::hasAvif()) {
            $missing[] = 'AVIF';
        }

        if ($missing === []) {
            return;
        }

        echo ui_notice(
            'warning',
            'HiMedia ayarında açık olan şu format(lar) bu sunucudaki GD sürümünde yazılamıyor: '
            . implode(', ', $missing) . '. Üretim kaynağın formatıyla sürüyor.'
        );
    }
}
