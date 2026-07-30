<?php
/**
 * HiCMS — Örnek yapılandırma
 *
 * Bu dosyayı `config.php` adıyla kopyalayın ve değerleri kendi kurulumunuza
 * göre düzenleyin. `config.php` sürüm kontrolüne dahil edilmez.
 *
 * @package HiCMS
 */

/* -------------------------------------------------------------------------
 * Veritabanı — 2. aşamada devreye girecek
 * ---------------------------------------------------------------------- */

define('HICMS_DB_HOST', 'localhost');
define('HICMS_DB_NAME', 'hicms');
define('HICMS_DB_USER', 'root');
define('HICMS_DB_PASS', '');
define('HICMS_DB_CHARSET', 'utf8mb4');
define('HICMS_DB_PREFIX', 'hi_');

/* -------------------------------------------------------------------------
 * Site
 * ---------------------------------------------------------------------- */

// Site adresini sabitlemek isterseniz açın. Kapalıysa istekten türetilir.
// define('HICMS_URL', 'https://ornek.com');

// Arayüz dili: core/languages/ altındaki dosya adı.
define('HICMS_LOCALE', 'tr_TR');

// Geliştirme sırasında true yapın; canlıda kesinlikle false olmalı.
define('HICMS_DEBUG', true);

/* -------------------------------------------------------------------------
 * Güvenlik
 * ---------------------------------------------------------------------- */

// Oturum ve çerez imzalama anahtarı. Kurulumda rastgele üretilmelidir.
define('HICMS_SECRET_KEY', 'bu-degeri-mutlaka-degistirin');
