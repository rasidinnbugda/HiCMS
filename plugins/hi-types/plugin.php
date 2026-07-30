<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Plugin\Plugin as BasePlugin;

/**
 * HiTypes — panelden içerik türü, taksonomi ve özel alan oluşturucu
 *
 * Çekirdek içerik türü **kayıt API'sini** taşır ama kutudan yalnızca Yazı ve
 * Sayfa gelir. Bu eklenti o API'nin panel arayüzü: panelden tür, taksonomi ve
 * alan tanımlarsınız; tanım `hi_settings('hi-types')` deposunda JSON olarak
 * durur ve her istekte `hi_register_content_type()` /
 * `hi_register_taxonomy()` ile kaydedilir.
 *
 * Eklenti kapatıldığında türler panelde görünmez olur ama **içerik silinmez** —
 * kayıtlar `content` tablosunda, terimler `terms` tablosunda durur; eklenti
 * yeniden açıldığında hepsi geri gelir.
 *
 * KANCA SÖKME YOK: `boot()` içinde bağlanan her kanca `plugin:hi-types`
 * sahipliği altında kaydediliyor, devre dışı bırakıldığında çekirdek
 * `Dispatcher::forgetOwner()` ile yalnızca bizim kayıtlarımızı söküyor.
 * Eklenti kodunda `forget()` çağırmak, aynı kancayı kullanan çekirdeğin ve
 * diğer eklentilerin dinleyicilerini de silerdi.
 */
final class Plugin extends BasePlugin
{
    private ?Store $store = null;

    public function boot(): void
    {
        /*
         * Tanımlar tema yüklenmeden önce kaydedilmeli: şablon hiyerarşisi,
         * rota tablosu ve panel menüsü bu kayıttan üretiliyor.
         */
        $this->store()->registerAll();

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-types',
                'label' => 'İçerik Türleri',
                'icon'  => 'grid',
                'url'   => 'plugin.php?eklenti=hi-types',
            ]);
        });

        hi_on('admin.page.hi-types', function (): void {
            (new Screen($this->store()))->render();
        });

        /*
         * Panel varlıkları ve JS verisi ÇERÇEVEDE (admin.head / admin.footer)
         * yayınlanır, ekranın gövdesinde değil. Sebep anında gezinme: nav.js
         * yalnızca `main.content` bölgesini değiştirir, alt bilgideki betik ve
         * veri düğümleri yerinde kalır. Ekranın içinde basılsalardı, bölge
         * değişimiyle gelen kurulum verisi kaybolurdu.
         *
         * Veri satır içi `<script>window.X = …</script>` ile DEĞİL,
         * `hi_admin_data()` ile geçiliyor: bölge değişiminde gelen betik
         * etiketleri çalıştırılmaz, veri düğümleri ise okunabilir kalır.
         */
        $version = '?v=' . $this->manifest->version;

        hi_admin_style($this->assetUrl('assets/css/types.css') . $version);

        /*
         * SIRA ÖNEMLİ: veri düğümü betikten ÖNCE bildirilir. İkisi de
         * `admin.footer` kancasına yazıyor ve kanca kayıt sırasıyla koşuyor;
         * betik önce basılsa, `HiAdmin.onMount()` ilk kurulumu ANINDA
         * çalıştırdığı için veri düğümü henüz ayrıştırılmamış olurdu ve
         * yapılandırma boş gelirdi. (Betik yine de bu duruma dayanıklı.)
         */
        hi_admin_data('hi-types', Screen::jsData());
        hi_admin_script($this->assetUrl('assets/js/types.js') . $version);
    }

    /**
     * Etkinleştirmede 0.2.0 deposundan göç.
     *
     * `load()` göçü kendi içinde bir kez yapar; burada çağrılması göçün
     * etkinleştirme anında (ilk sayfa isteğini beklemeden) tamamlanmasını
     * sağlar.
     */
    public function activate(): void
    {
        $this->store()->load();
    }

    /** Eklenti kaldırılırken tanımlar ve ayarlar silinir. */
    public function uninstall(): void
    {
        $this->store()->forget();
    }

    public function store(): Store
    {
        return $this->store ??= new Store($this->app, $this->manifest->slug);
    }

    /**
     * Kayıtlı tür tanımları — tema ve diğer eklentiler de okuyabilsin diye açık.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->store()->types();
    }
}
