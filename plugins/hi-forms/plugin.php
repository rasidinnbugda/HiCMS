<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Content\BlockRenderer;
use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Http\Response;
use HiCMS\Http\Router;
use HiCMS\Plugin\Plugin as BasePlugin;

/**
 * HiForms — form oluşturucu ve gönderi kutusu
 *
 * Form tanımları ve ayarlar `hi_settings('hi-forms')` deposunda, gönderiler
 * kendi tablosunda durur. İçeriğe yerleştirme çekirdeğin blok API'siyle
 * yapılır: eklenti bir `form` bloğu kaydeder, blok editöründe kendiliğinden
 * görünür.
 *
 * 0.3.0'DA DEĞİŞENLER
 *   • Ayarlar bildirimsel: elle option okuma/yazma kalmadı (bkz. Forms).
 *   • Kancalar `forget()` ile SÖKÜLMEZ. `boot()` sahiplik altında çalışıyor;
 *     devre dışı bırakıldığında çekirdek `forgetOwner('plugin:hi-forms')`
 *     çağırıp yalnızca bu eklentinin kayıtlarını siliyor. `forget()` çağırmak
 *     aynı kancayı kullanan başka eklentileri de susturur.
 *   • Panel betiği veriyi `hi_admin_data()` ile alır ve kurulumunu
 *     `HiAdmin.onMount()` içinde yapar: anında sayfa geçişinde bölge değişince
 *     satır içi betikler çalışmaz, veri öğeleri ise okunabilir kalır.
 *
 * DERİNLİK
 *   • Panelden alan tanımı: tür, zorunluluk, yer tutucu, açıklama, genişlik,
 *     seçenek listesi ve sıra.
 *   • KOŞULLU ALAN: bir alan başka bir alanın değerine göre görünür. Karar
 *     sunucuda da verilir; betik yalnızca ekranı buna uydurur.
 *   • Sunucu tarafı doğrulama, hatalı gönderimde girilen değerlerin korunması.
 *   • E-posta bildirimi ve gönderene otomatik yanıt.
 *   • İstenmeyen koruması: bal küpü, imzalı süre tuzağı, gönderim aralığı
 *     sınırı, yasaklı sözcük. Yakalananlar karantinaya alınır.
 *   • Gönderi listesi, tek gönderi ekranı ve CSV dışa aktarımı.
 */
final class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Ayar sözleşmesi: her istekte tarif edilir, hiçbir şey yazılmaz.
        Forms::describe();

        // 1.0.0 tanımlarını yeni depoya taşır; en fazla bir kez çalışır.
        Forms::migrateLegacy();

        $this->registerBlock();

        hi_on('routing.register', static function (Router $router): void {
            $router->post(
                '/form-gonder',
                static fn(array $params, $request): Response => Handler::submit($request),
                'forms.submit',
                2
            );
        });

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $unread = Submissions::unread();

            $event->add('content', [
                'slug'  => 'plugin:' . Forms::SLUG,
                'label' => 'Formlar',
                'icon'  => 'inbox',
                'url'   => Admin::url(),
                'badge' => $unread > 0 ? $unread : null,
                'alert' => true,
            ]);
        });

        hi_on('admin.page.' . Forms::SLUG, static function (): void {
            Admin::screen();
        });
    }

    /**
     * Etkinleştirmede bir örnek form yazılır — boş bir eklenti ekranı ne
     * yapılacağını anlatmaz.
     */
    public function activate(): void
    {
        Forms::describe();
        Forms::migrateLegacy();

        if (Forms::all() !== []) {
            return;
        }

        Forms::put([
            'slug'         => 'iletisim',
            'name'         => 'İletişim',
            'consent'      => true,
            'reply_field'  => 'eposta',
            'submit_label' => 'Gönder',
            'notify_to'    => array_filter([(string) hi_option('admin_email', '')]),
            'fields'       => [
                ['key' => 'ad', 'label' => 'Adınız', 'type' => 'text',
                 'required' => true, 'width' => 'half'],
                ['key' => 'eposta', 'label' => 'E-posta', 'type' => 'email',
                 'required' => true, 'width' => 'half'],
                ['key' => 'konu', 'label' => 'Konu', 'type' => 'select', 'required' => true,
                 'choices' => ['Bilgi talebi', 'Teklif talebi', 'Diğer']],
                ['key' => 'kurum', 'label' => 'Kurum adı', 'type' => 'text',
                 'cond_field' => 'konu', 'cond_op' => 'eq', 'cond_value' => 'Teklif talebi',
                 'help' => 'Teklif talebinde kurum adı gerekiyor.', 'required' => true],
                ['key' => 'mesaj', 'label' => 'Mesajınız', 'type' => 'textarea', 'required' => true],
            ],
        ]);
    }

    /**
     * Kaldırılırken kendi verisini temizler.
     *
     * Tablo migration'ın `down()` yolundan düşürülür; burada yalnızca ayar
     * satırı silinir. `deactivate()` HİÇBİR ŞEY SİLMEZ — devre dışı bırakmak
     * geri alınabilir bir işlem olmalı.
     */
    public function uninstall(): void
    {
        hi_settings(Forms::SLUG)->forget();
    }

    /**
     * Blok: içeriğe form yerleştirme.
     *
     * Seçenek listesi tanımlı formlardan gelir, yani yeni bir form kaydedildiği
     * an blok editöründe görünür.
     */
    private function registerBlock(): void
    {
        hi_register_block('form', [
            'label'       => 'Form',
            'icon'        => 'inbox',
            'group'       => 'düzen',
            'description' => 'Panelde tanımlı bir formu içeriğe yerleştirir.',
            'fields'      => [
                [
                    'key'     => 'form',
                    'type'    => 'select',
                    'label'   => 'Form',
                    'options' => Forms::options(),
                ],
                [
                    'key'         => 'baslik',
                    'type'        => 'text',
                    'label'       => 'Başlık',
                    'placeholder' => 'İsteğe bağlı',
                ],
            ],
            'render' => static fn(array $data, BlockRenderer $renderer): string => Renderer::render(
                (string) ($data['form'] ?? ''),
                (string) ($data['baslik'] ?? '')
            ),
        ]);
    }
}
