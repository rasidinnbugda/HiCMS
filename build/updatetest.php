<?php

declare(strict_types=1);

/**
 * HiCMS — "aynı ZIP hem kurulum hem güncelleme" doğrulaması
 *
 * Dağıtım paketini geçici bir dizine açar, kurar, kullanıcı dosyaları ekler,
 * sonra AYNI paketi panelden güncelleme olarak uygular ve kullanıcı verisinin
 * korunduğunu, çekirdeğin yenilendiğini doğrular.
 *
 * Bu akış paneldeki `admin/system.php` üzerinden yürütülür; güncelleme kendi
 * çalıştığı dizini yeniden yazdığı için hatalar yalnızca burada görünür.
 *
 * Kullanım:
 *   php build/updatetest.php --zip=dist/hicms-0.2.0.zip --port=3306 \
 *       --user=root --pass=gizli --db=hicms_update --site=C:/tmp/hicms-site \
 *       --url=http://127.0.0.1:8180
 *
 * `--site` dizininde bir HTTP sunucusu çalışmalıdır:
 *   php -S 127.0.0.1:8180 -t <site> <site>/router.php
 *
 * DİKKAT: --db düşürülür, --site dizini silinip yeniden oluşturulur.
 *
 * @package HiCMS
 */

$root = dirname(__DIR__);

$options = [
    'zip'  => '',
    'site' => '',
    'url'  => 'http://127.0.0.1:8180',
    'host' => '127.0.0.1',
    'port' => '3306',
    'user' => 'root',
    'pass' => '',
    'db'   => 'hicms_update',
];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('~^--([a-z]+)=(.*)$~', $argument, $match) === 1 && isset($options[$match[1]])) {
        $options[$match[1]] = $match[2];
    }
}

if ($options['zip'] === '') {
    $candidates = glob($root . '/dist/hicms-*.zip') ?: [];
    sort($candidates);
    $options['zip'] = (string) end($candidates);
}

$zip  = $options['zip'];
$site = rtrim(str_replace('\\', '/', $options['site']), '/');
$base = rtrim($options['url'], '/');

if (!is_file($zip)) {
    echo "ZIP bulunamadı: {$zip}\n";
    echo "Önce `php build/make-zip.php` çalıştırın veya --zip= verin.\n";
    exit(1);
}

if ($site === '') {
    echo "--site= ile bir hedef dizin verin (sunucunun kökü olacak dizin).\n";
    exit(1);
}

echo "HiCMS kurulum + güncelleme doğrulaması\n";
echo str_repeat('-', 62) . "\n";
echo 'Paket : ' . basename($zip) . ' (' . round(filesize($zip) / 1024, 1) . " KB)\n";
echo "Dizin : {$site}\n";
echo "Sunucu: {$base}\n\n";

$passed = 0;
$failed = [];
$jar    = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        printf("  ok   %s\n", $label);

        return;
    }

    $failed[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  HATA %s%s\n", $label, $detail !== '' ? ' — ' . $detail : '');
}

/**
 * @param array<string, string> $fields
 * @param array{name: string, path: string}|null $upload
 * @return array{status: int, body: string, location: string}
 */
function request(string $url, array $fields = [], ?array $upload = null): array
{
    global $jar;

    $headers = ['Accept: text/html'];
    $body    = null;

    if ($upload !== null) {
        $boundary = '----hicms' . bin2hex(random_bytes(8));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"paket\"; filename=\"{$upload['name']}\"\r\n"
            . "Content-Type: application/zip\r\n\r\n"
            . (string) file_get_contents($upload['path']) . "\r\n"
            . "--{$boundary}--\r\n";

        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif ($fields !== []) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body      = http_build_query($fields);
    }

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = ['http' => [
        'method'          => $body === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $headers) . "\r\n",
        'timeout'         => 300,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
    ]];

    if ($body !== null) {
        $context['http']['content'] = $body;
    }

    $received = @file_get_contents($url, false, stream_context_create($context));
    $status   = 0;
    $location = '';

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
            $status = (int) $m[1];
        }

        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }

        if (stripos($header, 'Set-Cookie:') === 0
            && preg_match('~^Set-Cookie:\s*([^=]+)=([^;]*)~i', $header, $m) === 1) {
            $jar[trim($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'body' => $received === false ? '' : $received, 'location' => $location];
}

function token(string $url): string
{
    return preg_match('~name="_token"[^>]*value="([^"]+)"~', request($url)['body'], $m) === 1 ? $m[1] : '';
}

function countFiles(string $directory): int
{
    if (!is_dir($directory)) {
        return -1;
    }

    $total = 0;

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    ) as $item) {
        if ($item->isFile()) {
            $total++;
        }
    }

    return $total;
}

/* ------------------------------------------------------- 1. paketi aç ve kur */

echo "1. Paketten kurulum\n";

$archive = new ZipArchive();

if ($archive->open($zip) !== true) {
    echo "  HATA paket açılamadı\n";
    exit(1);
}

if (!is_dir($site)) {
    mkdir($site, 0o775, true);
}

$archive->extractTo($site);
$archive->close();

check('paket dizine açıldı', is_file($site . '/install.php') && is_dir($site . '/src'));

$reachable = @file_get_contents($base . '/install.php', false, stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true],
]));

if ($reachable === false) {
    echo "  HATA sunucuya erişilemedi. Ayrı bir kabukta şunu çalıştırın:\n";
    echo "       php -S " . parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT)
        . " -t \"{$site}\" \"{$site}/router.php\"\n";
    exit(1);
}

try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%s', $options['host'], $options['port']),
        $options['user'],
        $options['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $options['db']) . '`');
} catch (Throwable $exception) {
    echo '  HATA veritabanına bağlanılamadı: ' . $exception->getMessage() . "\n";
    exit(1);
}

if (is_file($site . '/config.php')) {
    unlink($site . '/config.php');
}

$password = 'GuncellemeTesti#2026';

$install = request($base . '/install.php', [
    'action'    => 'install',
    'db_host'   => $options['host'],
    'db_port'   => $options['port'],
    'db_name'   => $options['db'],
    'db_user'   => $options['user'],
    'db_pass'   => $options['pass'],
    'db_prefix' => 'hi_',
    'url'       => $base,
    'locale'    => 'tr_TR',
    'timezone'  => 'Europe/Istanbul',
    'title'     => 'Güncelleme Testi',
    'tagline'   => 'aynı paketten kurulum',
    'name'      => 'Doğrulama Kullanıcısı',
    'username'  => 'guncelleme',
    'email'     => 'guncelleme@ornek.test',
    'password'  => $password,
    'demo'      => '0',
]);

preg_match_all('~<div class="alert alert-err">(.*?)</div>~s', $install['body'], $errors);

foreach ($errors[1] as $block) {
    echo '       ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($block))) . "\n";
}

check(
    'kurulum tamamlandı',
    str_contains($install['body'], 'Panele git') || str_contains($install['body'], 'Kurulum tamamlandı')
);

check('config.php yazıldı', is_file($site . '/config.php'));
check('ön yüz açılıyor', str_contains(request($base . '/')['body'], 'Güncelleme Testi'));

$adminBefore  = countFiles($site . '/admin');
$configBefore = (string) @file_get_contents($site . '/config.php');

/* ------------------------------------------------- 2. kullanıcı değişiklikleri */

echo "\n2. Kullanıcı dosyaları\n";

mkdir($site . '/themes/musteri-temasi', 0o775, true);
file_put_contents($site . '/themes/musteri-temasi/hicms.json', json_encode([
    'name' => 'Müşteri Teması', 'slug' => 'musteri-temasi', 'version' => '1.0.0', 'type' => 'theme',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($site . '/themes/musteri-temasi/index.php', "<?php // müşteriye özel\n");

file_put_contents($site . '/themes/hiblog/assets/musteri.css', "/* elle düzenleme */\n");

mkdir($site . '/plugins/musteri-eklentisi', 0o775, true);
file_put_contents($site . '/plugins/musteri-eklentisi/hicms.json', json_encode([
    'name' => 'Müşteri Eklentisi', 'slug' => 'musteri-eklentisi', 'version' => '1.0.0', 'type' => 'plugin',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

mkdir($site . '/content/uploads/2026/07', 0o775, true);
file_put_contents($site . '/content/uploads/2026/07/musteri-gorseli.txt', 'yüklenmiş dosya');

/*
 * Çekirdeğin gerçekten yenilendiğini iki işaretçiyle ölçeriz:
 * artık dosya silinmeli, bozulan varlık özgün hâline dönmeli.
 * PHP dosyası bozulmaz — güncellemeyi yürüten kod da o çekirdekten gelir.
 */
file_put_contents($site . '/src/ESKI-SURUM-ARTIGI.txt', 'güncelleme bunu silmeli');

$cssPath   = $site . '/admin/assets/css/admin.css';
$cssBefore = (string) file_get_contents($cssPath);
file_put_contents($cssPath, "/* bozuldu */\n");

echo "     tema, eklenti, medya, elle düzenleme eklendi\n";
echo "     src/ altına artık dosya bırakıldı, admin.css bozuldu\n";

/* ----------------------------------------------------- 3. panelden güncelle */

echo "\n3. Panelden güncelleme\n";

$auth = request($base . '/admin/login.php', [
    '_token'    => token($base . '/admin/login.php'),
    'kullanici' => 'guncelleme',
    'sifre'     => $password,
]);

check('panele giriş yapıldı', $auth['status'] === 303, 'durum ' . $auth['status']);

$updatePage = request($base . '/admin/system.php?sekme=guncelleme');

check('yükleme formu görünüyor', str_contains($updatePage['body'], 'update-upload'));

$upload = request(
    $base . '/admin/system.php?sekme=guncelleme',
    [
        '_token' => preg_match('~name="_token"[^>]*value="([^"]+)"~', $updatePage['body'], $m) === 1 ? $m[1] : '',
        'islem'  => 'update-upload',
    ],
    ['name' => basename($zip), 'path' => $zip]
);

check('paket kabul edildi', in_array($upload['status'], [200, 302, 303], true), 'durum ' . $upload['status']);

/* ------------------------------------------------------ 4. sonuç denetimi */

echo "\n4. Güncelleme sonrası\n";

clearstatcache(true);

check('artık çekirdek dosyası silindi', !is_file($site . '/src/ESKI-SURUM-ARTIGI.txt'));

check(
    'bozulan çekirdek varlığı geri getirildi',
    is_file($cssPath) && (string) file_get_contents($cssPath) === $cssBefore,
    is_file($cssPath) ? filesize($cssPath) . ' bayt (özgün ' . strlen($cssBefore) . ')' : 'dosya yok'
);

check(
    'panel dizini eksiksiz',
    countFiles($site . '/admin') === $adminBefore,
    countFiles($site . '/admin') . ' dosya (önce ' . $adminBefore . ')'
);

check('yana alınan kilitli dosya kalmadı', glob($site . '/admin/*.hicms-old') === []);
check('config.php aynen korundu', (string) @file_get_contents($site . '/config.php') === $configBefore);
check('kullanıcı teması korundu', is_file($site . '/themes/musteri-temasi/index.php'));
check('temadaki elle düzenleme korundu', is_file($site . '/themes/hiblog/assets/musteri.css'));
check('kullanıcı eklentisi korundu', is_file($site . '/plugins/musteri-eklentisi/hicms.json'));
check('yüklenen medya korundu', is_file($site . '/content/uploads/2026/07/musteri-gorseli.txt'));
check('güncelleme öncesi yedek alındı', (glob($site . '/content/backups/*') ?: []) !== []);

/* ----------------------------------------------- 5. güncellemeden sonra site */

echo "\n5. Güncellemeden sonra site\n";

// Oturum hâlâ açık olduğu için login.php panele yönlendirir; gösterge
// panelinin kendisi denetlenir.
foreach ([
    'anasayfa'        => '/',
    'besleme'         => '/feed',
    'gösterge paneli' => '/admin/index.php',
] as $label => $path) {
    $response = request($base . $path);

    check(
        $label . ' çalışıyor',
        $response['status'] === 200
            && preg_match('~(Fatal error|Parse error|Uncaught \w)~', $response['body']) !== 1,
        'durum ' . $response['status']
    );
}

$users = (new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s', $options['host'], $options['port'], $options['db']),
    $options['user'],
    $options['pass']
))->query('SELECT COUNT(*) FROM hi_users')->fetchColumn();

check('veritabanı ve kullanıcı korundu', (int) $users === 1, (string) $users);

/* ----------------------------------------------------------------- özet */

echo "\n" . str_repeat('-', 62) . "\n";

if ($failed === []) {
    echo "TUM TESTLER GECTI ({$passed} denetim)\n";
    exit(0);
}

printf("BASARISIZ: %d / %d\n\n", count($failed), $passed + count($failed));

foreach ($failed as $problem) {
    echo "  - {$problem}\n";
}

exit(1);
