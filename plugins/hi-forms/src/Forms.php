<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Extension\Settings;
use HiCMS\Support\Str;

/**
 * Form tanımları ve eklenti ayarları.
 *
 * TEK DEPO: her şey `hi_settings('hi-forms')` üzerinden, yani tek option
 * satırında (`plugin.hi-forms.settings`) durur. 1.0.0'da form tanımları elle
 * `plugin.hi-forms.forms` anahtarına yazılıyordu ve temizleme kuralları form
 * başına elle yazılmıştı; artık alan türü ne diyorsa o uygulanıyor
 * (`HiCMS\Content\Field::sanitize`).
 *
 * Formlar `repeater` türünde tek bir ayar alanıdır. Bu, tanımı bildirimsel
 * yapar: iç içe alanların temizlenmesi çekirdeğin işi olur, eklentide ikinci
 * bir doğrulama kuralı seti doğmaz.
 *
 * YAZMA HER ZAMAN KISMİDİR (`save($veri, null, true)`). Bir formu kaydetmek
 * diğer bölümlerin (genel, koruma) ayarlarına dokunmamalı; kısmi olmayan yazım
 * sözleşmesi gereği gönderilmeyen her anahtarı boşaltır.
 */
final class Forms
{
    public const SLUG = 'hi-forms';

    /** Ön yüzde kullanılabilen alan türleri: anahtar → panelde görünen ad. */
    public const TYPES = [
        'text'     => 'Metin',
        'email'    => 'E-posta',
        'tel'      => 'Telefon',
        'url'      => 'Web adresi',
        'number'   => 'Sayı',
        'date'     => 'Tarih',
        'textarea' => 'Uzun metin',
        'select'   => 'Açılır liste',
        'radio'    => 'Tek seçim',
        'checkbox' => 'Onay kutusu',
    ];

    /** Seçenek listesi isteyen türler. */
    public const CHOICE_TYPES = ['select', 'radio'];

    /** Koşul işleçleri: bir alan başka bir alanın değerine göre görünür. */
    public const OPERATORS = [
        'eq'     => 'şuna eşitse',
        'neq'    => 'şuna eşit değilse',
        'filled' => 'doldurulduysa',
        'empty'  => 'boş bırakıldıysa',
    ];

    public const WIDTHS = ['full' => 'Tam satır', 'half' => 'Yarım satır'];

    public static function store(): Settings
    {
        return hi_settings(self::SLUG);
    }

    /** Bir genel ayar okur. */
    public static function setting(string $key, mixed $fallback = null): mixed
    {
        return self::store()->get($key, $fallback);
    }

    public static function flag(string $key): bool
    {
        return (bool) self::setting($key, false);
    }

    public static function number(string $key, int $fallback = 0): int
    {
        $value = self::setting($key, $fallback);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /** @return list<string> */
    public static function lines(string $key): array
    {
        $value = self::setting($key, []);

        return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    }

    /* ---------------------------------------------------------------------
     * Ayar tanımı
     * ------------------------------------------------------------------ */

    /**
     * Ayarları çekirdeğe tarif eder. `boot()` içinde bir kez çağrılır.
     *
     * "formlar" bölümü panelin genel ayar ekranında BASILMAZ — kendi düzenleme
     * ekranı var. Bölüm olarak tanımlanmasının nedeni depolama ve temizlemenin
     * aynı sözleşmeden geçmesi.
     */
    public static function describe(): void
    {
        self::store()
            ->section('genel', 'Genel', [
                [
                    'key'     => 'notify',
                    'type'    => 'switch',
                    'label'   => 'E-posta bildirimi gönder',
                    'help'    => 'Kapatıldığında gönderiler yalnızca panelde birikir.',
                    'default' => true,
                ],
                [
                    'key'     => 'notify_to',
                    'type'    => 'lines',
                    'label'   => 'Bildirim adresleri',
                    'help'    => 'Her satıra bir adres. Formun kendi adres listesi boşsa bunlar kullanılır.',
                    'default' => [],
                ],
                [
                    'key'     => 'from_name',
                    'type'    => 'text',
                    'label'   => 'Gönderen adı',
                    'help'    => 'Boşsa site adı kullanılır.',
                    'default' => '',
                ],
                [
                    'key'     => 'from_email',
                    'type'    => 'email',
                    'label'   => 'Gönderen adresi',
                    'help'    => 'Boşsa sunucunun varsayılanı kullanılır. Alan adınızla aynı olması '
                        . 'iletinin istenmeyene düşmesini azaltır.',
                    'default' => '',
                ],
                [
                    'key'     => 'success_message',
                    'type'    => 'text',
                    'label'   => 'Varsayılan başarı mesajı',
                    'default' => 'Mesajınız alındı. En kısa sürede dönüş yapacağız.',
                ],
                [
                    'key'     => 'consent_text',
                    'type'    => 'textarea',
                    'rows'    => 2,
                    'label'   => 'Onay metni',
                    'help'    => 'Onay kutusu açık olan formlarda gösterilir.',
                    'default' => 'Verdiğim bilgilerin bu talebi yanıtlamak amacıyla saklanmasını kabul ediyorum.',
                ],
                [
                    'key'     => 'store_ip',
                    'type'    => 'switch',
                    'label'   => 'IP adresini sakla',
                    'help'    => 'Kapalıyken IP yerine geri döndürülemez kısa bir özet saklanır; '
                        . 'gönderim aralığı sınırı yine çalışır.',
                    'default' => true,
                ],
            ])
            ->section('koruma', 'İstenmeyen koruması', [
                [
                    'key'     => 'honeypot',
                    'type'    => 'switch',
                    'label'   => 'Bal küpü alanı',
                    'help'    => 'Ekranda görünmeyen bir alan eklenir. Doldurulmuşsa gönderi istenmeyendir.',
                    'default' => true,
                ],
                [
                    'key'     => 'min_seconds',
                    'type'    => 'number',
                    'label'   => 'En kısa doldurma süresi',
                    'help'    => 'Saniye. Formun açılışıyla gönderimi arasında bundan az süre geçtiyse '
                        . 'gönderi istenmeyen sayılır. 0 = kapalı.',
                    'default' => 3,
                ],
                [
                    'key'     => 'window',
                    'type'    => 'number',
                    'label'   => 'Aralık penceresi',
                    'help'    => 'Saniye. Aynı ziyaretçiden gelen gönderiler bu pencerede sayılır.',
                    'default' => 60,
                ],
                [
                    'key'     => 'max_in_window',
                    'type'    => 'number',
                    'label'   => 'Pencerede en fazla gönderim',
                    'help'    => '0 = sınırsız.',
                    'default' => 2,
                ],
                [
                    'key'     => 'blocklist',
                    'type'    => 'lines',
                    'label'   => 'Yasaklı sözcükler',
                    'help'    => 'Her satıra bir sözcük. Gönderinin herhangi bir alanında geçiyorsa '
                        . 'istenmeyen sayılır.',
                    'default' => [],
                ],
                [
                    'key'     => 'keep_spam',
                    'type'    => 'switch',
                    'label'   => 'İstenmeyenleri karantinada tut',
                    'help'    => 'Kapalıyken istenmeyen gönderi hiç kaydedilmez. Açık tutmak, yanlış '
                        . 'işaretlenen gerçek bir gönderiyi kurtarma şansı verir.',
                    'default' => true,
                ],
            ])
            ->section('formlar', 'Form tanımları', [
                [
                    'key'    => 'forms',
                    'type'   => 'repeater',
                    'label'  => 'Formlar',
                    'fields' => self::formShape(),
                ],
                /*
                 * Göç izi. Ayar olarak tanımlı çünkü OKUNMASI BEDAVA olması
                 * gerekiyor: ayar satırı autoload'lı, yani zaten bellekte.
                 * Ayrı bir option anahtarı olsaydı silindikten sonra her
                 * istekte bir "yok mu?" sorgusu doğardı.
                 */
                ['key' => 'migrated', 'type' => 'switch', 'label' => 'Göç tamamlandı', 'default' => false],
            ]);
    }

    /** @return list<array<string, mixed>> */
    private static function formShape(): array
    {
        return [
            ['key' => 'slug', 'type' => 'text', 'label' => 'Kısa ad'],
            ['key' => 'name', 'type' => 'text', 'label' => 'Ad'],
            ['key' => 'success', 'type' => 'text', 'label' => 'Başarı mesajı'],
            ['key' => 'redirect', 'type' => 'text', 'label' => 'Yönlendirme'],
            ['key' => 'submit_label', 'type' => 'text', 'label' => 'Düğme yazısı'],
            ['key' => 'notify_to', 'type' => 'lines', 'label' => 'Bildirim adresleri', 'default' => []],
            ['key' => 'reply_field', 'type' => 'text', 'label' => 'Yanıt adresi alanı'],
            ['key' => 'consent', 'type' => 'switch', 'label' => 'Onay kutusu'],
            ['key' => 'autoreply', 'type' => 'switch', 'label' => 'Otomatik yanıt'],
            ['key' => 'autoreply_subject', 'type' => 'text', 'label' => 'Otomatik yanıt konusu'],
            ['key' => 'autoreply_body', 'type' => 'textarea', 'rows' => 5, 'label' => 'Otomatik yanıt metni'],
            [
                'key'    => 'fields',
                'type'   => 'repeater',
                'label'  => 'Alanlar',
                'fields' => [
                    ['key' => 'key', 'type' => 'text', 'label' => 'Anahtar'],
                    ['key' => 'label', 'type' => 'text', 'label' => 'Etiket'],
                    ['key' => 'type', 'type' => 'select', 'label' => 'Tür', 'options' => self::TYPES],
                    ['key' => 'required', 'type' => 'switch', 'label' => 'Zorunlu'],
                    ['key' => 'placeholder', 'type' => 'text', 'label' => 'Yer tutucu'],
                    ['key' => 'help', 'type' => 'text', 'label' => 'Açıklama'],
                    ['key' => 'choices', 'type' => 'lines', 'label' => 'Seçenekler', 'default' => []],
                    ['key' => 'width', 'type' => 'select', 'label' => 'Genişlik', 'options' => self::WIDTHS],
                    ['key' => 'cond_field', 'type' => 'text', 'label' => 'Koşul alanı'],
                    ['key' => 'cond_op', 'type' => 'select', 'label' => 'Koşul', 'options' => self::OPERATORS],
                    ['key' => 'cond_value', 'type' => 'text', 'label' => 'Koşul değeri'],
                ],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     * Okuma
     * ------------------------------------------------------------------ */

    /**
     * Tanımlı formlar, kısa ada göre anahtarlanmış.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $rows  = self::setting('forms', []);
        $forms = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = Str::slug((string) ($row['slug'] ?? ''));

            if ($slug === '' || isset($forms[$slug])) {
                continue;
            }

            $forms[$slug] = self::normalize($slug, $row);
        }

        return $forms;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * Blok editöründeki açılır liste için.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $slug => $form) {
            $options[$slug] = (string) $form['name'];
        }

        return $options !== [] ? $options : ['' => 'Tanımlı form yok'];
    }

    /**
     * Bir tanımı eksiksiz hâle getirir: eksik anahtarlar varsayılana, alanlar
     * anahtarsızlardan arındırılmış ve tekilleştirilmiş.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(string $slug, array $row): array
    {
        $fields = [];
        $seen   = [];

        foreach ((array) ($row['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = Str::slug((string) ($field['key'] ?? ''), '_');

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $type       = (string) ($field['type'] ?? 'text');

            $fields[] = [
                'key'         => $key,
                'label'       => trim((string) ($field['label'] ?? '')) !== ''
                    ? trim((string) $field['label']) : $key,
                'type'        => isset(self::TYPES[$type]) ? $type : 'text',
                'required'    => !empty($field['required']),
                'placeholder' => (string) ($field['placeholder'] ?? ''),
                'help'        => (string) ($field['help'] ?? ''),
                'choices'     => array_values(array_filter(
                    array_map('trim', array_map('strval', (array) ($field['choices'] ?? []))),
                    static fn(string $choice): bool => $choice !== ''
                )),
                'width'       => isset(self::WIDTHS[(string) ($field['width'] ?? '')])
                    ? (string) $field['width'] : 'full',
                'cond_field'  => Str::slug((string) ($field['cond_field'] ?? ''), '_'),
                'cond_op'     => isset(self::OPERATORS[(string) ($field['cond_op'] ?? '')])
                    ? (string) $field['cond_op'] : 'eq',
                'cond_value'  => (string) ($field['cond_value'] ?? ''),
            ];
        }

        /*
         * Kendine ya da var olmayan bir alana bakan koşul sessizce düşürülür.
         * Bırakılırsa alan HİÇ görünmez ve zorunluysa form gönderilemez hâle
         * gelir — hata mesajı da olmadığı için sebebi bulunmaz.
         */
        $keys = array_column($fields, 'key');

        foreach ($fields as $index => $field) {
            if ($field['cond_field'] !== ''
                && ($field['cond_field'] === $field['key'] || !in_array($field['cond_field'], $keys, true))) {
                $fields[$index]['cond_field'] = '';
            }
        }

        return [
            'slug'              => $slug,
            'name'              => trim((string) ($row['name'] ?? '')) !== ''
                ? trim((string) $row['name']) : $slug,
            'success'           => (string) ($row['success'] ?? ''),
            'redirect'          => (string) ($row['redirect'] ?? ''),
            'submit_label'      => trim((string) ($row['submit_label'] ?? '')) !== ''
                ? trim((string) $row['submit_label']) : 'Gönder',
            'notify_to'         => array_values(array_filter(
                array_map('trim', array_map('strval', (array) ($row['notify_to'] ?? []))),
                static fn(string $mail): bool => filter_var($mail, FILTER_VALIDATE_EMAIL) !== false
            )),
            'reply_field'       => Str::slug((string) ($row['reply_field'] ?? ''), '_'),
            'consent'           => !empty($row['consent']),
            'autoreply'         => !empty($row['autoreply']),
            'autoreply_subject' => (string) ($row['autoreply_subject'] ?? ''),
            'autoreply_body'    => (string) ($row['autoreply_body'] ?? ''),
            'fields'            => $fields,
        ];
    }

    /* ---------------------------------------------------------------------
     * Yazma
     * ------------------------------------------------------------------ */

    /**
     * Bir formu ekler ya da değiştirir.
     *
     * @param array<string, mixed> $form
     */
    public static function put(array $form): void
    {
        $slug = Str::slug((string) ($form['slug'] ?? ''));

        if ($slug === '') {
            return;
        }

        $forms        = self::all();
        $forms[$slug] = self::normalize($slug, $form);

        self::persist($forms);
    }

    public static function forget(string $slug): void
    {
        $forms = self::all();

        if (!isset($forms[$slug])) {
            return;
        }

        unset($forms[$slug]);

        self::persist($forms);
    }

    /**
     * @param array<string, array<string, mixed>> $forms
     */
    private static function persist(array $forms): void
    {
        // Kısmi yazma: yalnızca "forms" anahtarına dokunulur.
        self::store()->save(['forms' => array_values($forms)], null, true);
    }

    /* ---------------------------------------------------------------------
     * 1.0.0 → 1.1.0 veri göçü
     * ------------------------------------------------------------------ */

    /**
     * Eski `plugin.hi-forms.forms` option'ını yeni ayar deposuna taşır.
     *
     * Eski biçim kısa ada göre anahtarlanmış bir haritaydı; yenisi liste.
     * Taşınan tanımların eksik anahtarları (koşul, genişlik, seçenekler)
     * `normalize()` ile varsayılana oturur, yani göç sonrası formlar aynı
     * çalışır.
     *
     * Göç EN FAZLA BİR KEZ dener: sonuç ayar deposundaki `migrated` bayrağına
     * yazılır. Bayrak okuması bedava (ayar satırı autoload'lı), o yüzden bu
     * denetim her istekte çağrılabilir.
     */
    public static function migrateLegacy(): void
    {
        if (self::flag('migrated')) {
            return;
        }

        $options = hi()->options();
        $legacy  = 'plugin.' . self::SLUG . '.forms';

        if ($options->has($legacy)) {
            $stored  = $options->get($legacy, []);
            $current = self::all();

            foreach (is_array($stored) ? $stored : [] as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $slug = Str::slug((string) ($row['slug'] ?? $key));

                if ($slug === '' || isset($current[$slug])) {
                    continue;
                }

                // Eski tanımda tek adres vardı ("notify"), yenisinde liste var.
                $row['notify_to'] = array_values(array_filter([trim((string) ($row['notify'] ?? ''))]));

                // 1.0.0 onay kutusunu her formda zorunlu basıyordu; davranış korunur.
                $row['consent'] = true;

                $current[$slug] = self::normalize($slug, $row);
            }

            self::persist($current);

            $options->delete($legacy);
        }

        self::store()->save(['migrated' => true], null, true);
    }
}
