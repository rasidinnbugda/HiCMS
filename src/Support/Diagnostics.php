<?php

declare(strict_types=1);

namespace HiCMS\Support;

use HiCMS\Kernel;

/**
 * Yapılandırma ve güvenlik uyarıları.
 *
 * `Requirements` sunucunun HiCMS'i çalıştırabilecek durumda olup olmadığına
 * bakar (PHP sürümü, eklentiler, yazma izinleri). Bu sınıf farklı bir soruyu
 * sorar: sunucu uygun ama KURULUM YANLIŞ YAPILANDIRILMIŞ olabilir mi?
 *
 * Buradaki maddelerin hepsi gerçek olaylardan gelir: canlıda açık kalmış hata
 * ayıklama, ters vekil arkasında yanlış görünen IP adresleri, örnek anahtarla
 * kalmış kurulum, web'den okunabilen yedek klasörü. Hiçbiri PHP hatası vermez,
 * hepsi sessizce yanlış çalışır — bu yüzden panelde görünmeleri gerekiyor.
 *
 * Her madde: ['level' => 'error'|'warn'|'ok', 'label', 'detail', 'fix']
 */
final class Diagnostics
{
    /** Kurulum sihirbazının ürettiği örnek anahtar değeri. */
    private const SAMPLE_KEY = 'bu-degeri-mutlaka-degistirin';

    /**
     * @return list<array{level: string, label: string, detail: string, fix: string}>
     */
    public static function run(Kernel $app): array
    {
        $rows   = [];
        $config = $app->config();

        /* ------------------------------------------------ hata ayıklama */

        $debug = (bool) $config->get('debug', false);

        $rows[] = $debug
            ? [
                'level'  => 'error',
                'label'  => 'Hata ayıklama açık',
                'detail' => 'PHP hataları ziyaretçilere görünüyor; dosya yolları ve sorgu '
                    . 'ayrıntıları sızabilir.',
                'fix'    => 'config.php içinde \'debug\' => false yapın.',
            ]
            : [
                'level'  => 'ok',
                'label'  => 'Hata ayıklama kapalı',
                'detail' => 'Hatalar ziyaretçilere gösterilmiyor.',
                'fix'    => '',
            ];

        /* ------------------------------------------------ imza anahtarları */

        $keys    = (array) $config->get('keys', []);
        $weak    = [];

        foreach (['app', 'cookie'] as $name) {
            $value = (string) ($keys[$name] ?? '');

            if ($value === '' || $value === self::SAMPLE_KEY || strlen($value) < 32) {
                $weak[] = $name;
            }
        }

        $rows[] = $weak !== []
            ? [
                'level'  => 'error',
                'label'  => 'İmza anahtarı zayıf',
                'detail' => 'Şu anahtarlar örnek değerde ya da çok kısa: ' . implode(', ', $weak)
                    . '. Önizleme bağlantıları ve "beni hatırla" çerezi taklit edilebilir.',
                'fix'    => 'php -r "echo bin2hex(random_bytes(32));" ile üretip config.php\'ye yazın.',
            ]
            : [
                'level'  => 'ok',
                'label'  => 'İmza anahtarları güçlü',
                'detail' => 'app ve cookie anahtarları rastgele ve yeterli uzunlukta.',
                'fix'    => '',
            ];

        /* ------------------------------------------------ vekil ayarı */

        $trusted = (array) $config->get('trusted_proxies', []);
        $server  = $app->request()->server;
        $hasXff  = ($server['HTTP_X_FORWARDED_FOR'] ?? '') !== ''
            || ($server['HTTP_CF_CONNECTING_IP'] ?? '') !== '';

        if ($hasXff && $trusted === []) {
            /*
             * En sinsi durum: istek vekil başlığı taşıyor ama güvenilen vekil
             * bildirilmemiş. HiCMS başlığı (doğru biçimde) yok sayıyor, yani
             * denetim günlüğündeki ve oran sınırlamasındaki adresler VEKİLİN
             * adresi oluyor — hepsi aynı. Hata verilmiyor, sonuç yanlış.
             */
            $rows[] = [
                'level'  => 'warn',
                'label'  => 'Ters vekil arkasında olabilirsiniz',
                'detail' => 'İstek X-Forwarded-For taşıyor ama güvenilen vekil bildirilmemiş. '
                    . 'Bu başlıklara güvenilmiyor (doğru davranış) ama tüm ziyaretçiler vekilin '
                    . 'adresinden görünüyor: denetim günlüğündeki IP\'ler gerçek ziyaretçiyi '
                    . 'göstermiyor.',
                'fix'    => 'config.php içinde \'trusted_proxies\' => [\'<vekil-ip>\'] ekleyin. '
                    . 'Giriş sınırlaması kullanıcı adına dayandığı için site kilitlenmez.',
            ];
        } elseif ($trusted !== []) {
            $rows[] = [
                'level'  => 'ok',
                'label'  => 'Güvenilen vekil bildirilmiş',
                'detail' => count($trusted) . ' adres/aralık: ' . implode(', ', array_map('strval', $trusted)),
                'fix'    => '',
            ];
        }

        /* ------------------------------------------------ HTTPS ve çerez */

        $url    = (string) $config->get('url', '');
        $secure = str_starts_with(strtolower($url), 'https://');

        if ($url !== '' && !$secure) {
            $rows[] = [
                'level'  => 'warn',
                'label'  => 'Site adresi HTTPS değil',
                'detail' => 'Oturum çerezi "secure" işaretini alamıyor; ağı dinleyen biri '
                    . 'oturumu ele geçirebilir.',
                'fix'    => 'Sunucuda TLS açın ve config.php\'deki \'url\' değerini https:// yapın.',
            ];
        }

        /* ------------------------------------------------ korunan klasörler */

        foreach (['backups', 'tmp', 'cache'] as $folder) {
            $path = $app->rootDir() . '/content/' . $folder;

            if (!is_dir($path)) {
                continue;
            }

            $guard = $path . '/.htaccess';

            if (!is_file($guard)) {
                $rows[] = [
                    'level'  => 'warn',
                    'label'  => 'content/' . $folder . ' korumasız',
                    'detail' => 'Klasörde .htaccess yok. Apache dışı bir sunucuda (nginx, Caddy) '
                        . 'bu dosya zaten etkisizdir; yedekleriniz web\'den indirilebilir olabilir.',
                    'fix'    => 'Sunucu yapılandırmasında /content/' . $folder . ' erişimini kapatın.',
                ];
            }
        }

        /* ------------------------------------------------ config.php erişimi */

        if (is_file($app->rootDir() . '/config.php')) {
            $mode = @fileperms($app->rootDir() . '/config.php');

            if ($mode !== false && ($mode & 0o004) !== 0) {
                $rows[] = [
                    'level'  => 'warn',
                    'label'  => 'config.php herkese okunabilir',
                    'detail' => 'Dosya izinleri "diğerleri" için okuma veriyor. Paylaşımlı '
                        . 'barındırmada başka hesaplar veritabanı şifrenizi görebilir.',
                    'fix'    => 'chmod 640 config.php',
                ];
            }
        }

        /* ------------------------------------------------ planlı görevler */

        $stale = $app->scheduler()->overdue(3600);

        if ($stale > 0) {
            $rows[] = [
                'level'  => 'warn',
                'label'  => $stale . ' planlı görev gecikmiş',
                'detail' => 'Görevler istek sonrasında çalışır; site hiç ziyaret edilmiyorsa '
                    . 'çalışmazlar. Yayın zamanlaması ve budama işlemeyebilir.',
                'fix'    => 'Gerçek bir cron kurun: * * * * * php ' . $app->rootDir() . '/hi-cron.php',
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{level: string, label: string, detail: string, fix: string}> $rows
     * @return array{error: int, warn: int, ok: int}
     */
    public static function summary(array $rows): array
    {
        $summary = ['error' => 0, 'warn' => 0, 'ok' => 0];

        foreach ($rows as $row) {
            $level = $row['level'];

            if (isset($summary[$level])) {
                $summary[$level]++;
            }
        }

        return $summary;
    }
}
