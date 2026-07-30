<?php

declare(strict_types=1);

/**
 * HiCMS — örnek yapılandırma
 *
 * Normalde bu dosyayı elle kopyalamanız GEREKMEZ: tarayıcıdan `install.php`
 * açtığınızda kurulum sihirbazı `config.php`'yi kendisi üretir ve anahtarları
 * rastgele oluşturur.
 *
 * Bu dosya iki durum için duruyor: kurulumu elle yapmak isteyenler ve mevcut
 * bir `config.php`'ye sonradan eklenebilecek ayarları görmek isteyenler.
 * Kullanacaksanız `config.php` adıyla kopyalayın.
 *
 * `config.php` güncellemelerde KORUNUR ve sürüm kontrolüne dahil edilmez.
 *
 * @package HiCMS
 */

return [
    /* ---------------------------------------------------------------------
     * Veritabanı — MySQL 5.7+ / MariaDB 10.3+
     * ------------------------------------------------------------------ */
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'hicms',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
        // Aynı veritabanında birden çok kurulum varsa ön eki değiştirin.
        'prefix'  => 'hi_',
    ],

    /* ---------------------------------------------------------------------
     * Site
     * ------------------------------------------------------------------ */

    // Sitenin kök adresi, sonda eğik çizgi OLMADAN. Boş bırakılırsa istekten
    // türetilir; ters vekil arkasında yanlış türetilebileceği için yazmak iyidir.
    'url' => 'https://ornek.com',

    'locale'   => 'tr_TR',
    'timezone' => 'Europe/Istanbul',

    /*
     * Hata ayıklama. CANLIDA false OLMALI.
     *
     * true iken PHP hataları ekrana basılır ve panelin altında sorgu sayacı
     * görünür. false iken hatalar gizlenir — kurulum sihirbazı bu değeri false
     * yazar.
     */
    'debug' => false,

    /* ---------------------------------------------------------------------
     * Güncelleme
     * ------------------------------------------------------------------ */

    // Panelin güncelleme aradığı GitHub deposu ("sahip/depo").
    'repository' => 'rasidinnbugda/HiCMS',

    /* ---------------------------------------------------------------------
     * Güvenlik
     * ------------------------------------------------------------------ */

    /*
     * İmzalama anahtarları. Kurulum sihirbazı bunları rastgele üretir.
     * Elle kuruyorsanız MUTLAKA değiştirin; şu komut uygun bir değer verir:
     *     php -r "echo bin2hex(random_bytes(32));"
     *
     * `app`    → önizleme bağlantısı ve genel imzalar
     * `cookie` → "beni hatırla" çerezi
     */
    'keys' => [
        'app'    => 'bu-degeri-mutlaka-degistirin',
        'cookie' => 'bu-degeri-de-mutlaka-degistirin',
    ],

    /*
     * GÜVENİLEN VEKİLLER
     *
     * Varsayılan BOŞ ve bu bilinçli: HiCMS `X-Forwarded-For` ve
     * `CF-Connecting-IP` başlıklarına yalnızca istek burada listelenen bir
     * adresten geldiğinde güvenir. Liste boşken `REMOTE_ADDR` kullanılır —
     * taklit edilemeyen tek değer.
     *
     * Neden önemli: bu başlıklar körü körüne okunursa herhangi bir istemci her
     * istekte farklı bir adres göstererek giriş oran sınırlamasını tamamen
     * atlayabilir ve denetim günlüğüne sahte adres yazdırabilir.
     *
     * Cloudflare, nginx ya da bir yük dengeleyici arkasındaysanız vekilin
     * adresini ekleyin. Tam IP ya da IPv4 CIDR yazılabilir:
     *
     *     'trusted_proxies' => ['10.0.0.1', '172.16.0.0/12'],
     *
     * Ters vekil arkasında tüm ziyaretçiler aynı adresten görünür; giriş
     * sınırlaması bu yüzden IP'ye değil kullanıcı adına dayanıyor, dolayısıyla
     * liste boş kalsa bile bir kişinin hatalı denemeleri siteyi kilitlemez.
     */
    'trusted_proxies' => [],
];
