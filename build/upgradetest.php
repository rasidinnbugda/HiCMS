<?php

declare(strict_types=1);

/**
 * 0.2.0 → 0.3.0 SÜRÜM YÜKSELTMESİ
 *
 * Aynı ZIP ile kurulum+güncelleme testi (build/updatetest.php) aynı sürümü
 * uyguluyor, yani migration çalıştırmıyor. Bu betik gerçek yükseltmeyi dener:
 * eski paketle kur, içerik üret, sonra YENİ paketi güncelleme olarak uygula ve
 * yeni migration'ın mevcut veriye zarar vermeden uygulandığını doğrula.
 *
 * php upgrade.php <site-dizini> <url> <db-port> <db-adi> <eski-zip> <yeni-zip>
 */

$site    = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$base    = rtrim($argv[2] ?? 'http://127.0.0.1:8195', '/');
$port    = $argv[3] ?? '3307';
$dbName  = $argv[4] ?? 'hicms_upgrade';
$oldZip  = $argv[5] ?? 'C:/Users/HP/HiCMS/dist/hicms-0.2.0.zip';
$newZip  = $argv[6] ?? 'C:/Users/HP/HiCMS/dist/hicms-0.3.0.zip';

$pass = 0;
$fail = [];
$jar  = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        printf("  ok   %s\n", $label);

        return;
    }

    $fail[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  HATA %s%s\n", $label, $detail !== '' ? ' — ' . $detail : '');
}

/**
 * @param array<string, string> $fields
 * @param array{name: string, path: string}|null $upload
 * @return array{status: int, body: string, location: string}
 */
function http(string $url, array $fields = [], ?array $upload = null): array
{
    global $jar;

    $headers = ['Accept: text/html'];
    $body    = null;

    if ($upload !== null) {
        $boundary = '----hi' . bin2hex(random_bytes(8));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"paket\"; filename=\"{$upload['name']}\"\r\n"
            . "Content-Type: application/zip\r\n\r\n"
            . (string) file_get_contents($upload['path']) . "\r\n--{$boundary}--\r\n";

        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif ($fields !== []) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body      = http_build_query($fields);
    }

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $ctx = ['http' => [
        'method'          => $body === null ? 'GET' : 'POST',
        'header'          => implode("\r\n", $headers) . "\r\n",
        'timeout'         => 300,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
    ]];

    if ($body !== null) {
        $ctx['http']['content'] = $body;
    }

    $received = @file_get_contents($url, false, stream_context_create($ctx));
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
    return preg_match('~name="_token"[^>]*value="([^"]+)"~', http($url)['body'], $m) === 1 ? $m[1] : '';
}

echo "0.2.0 → 0.3.0 yükseltme testi\n" . str_repeat('-', 60) . "\n";

/* ------------------------------------------------- 1. eski sürümü kur */

echo "\n1. 0.2.0 kurulumu\n";

$pdo = new PDO("mysql:host=127.0.0.1;port={$port}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $dbName) . '`');

$zip = new ZipArchive();
$zip->open($oldZip);
$zip->extractTo($site);
$zip->close();

if (is_file($site . '/config.php')) {
    unlink($site . '/config.php');
}

$password = 'YukseltmeTesti#2026';

$install = http($base . '/install.php', [
    'action'    => 'install',
    'db_host'   => '127.0.0.1',
    'db_port'   => $port,
    'db_name'   => $dbName,
    'db_user'   => 'root',
    'db_pass'   => '',
    'db_prefix' => 'hi_',
    'url'       => $base,
    'locale'    => 'tr_TR',
    'timezone'  => 'Europe/Istanbul',
    'title'     => 'Yükseltme Testi',
    'tagline'   => 'eski sürümden yeniye',
    'name'      => 'Raşidin Buğda',
    'username'  => 'yukselt',
    'email'     => 'yukselt@ornek.test',
    'password'  => $password,
    'demo'      => '1',
]);

check('0.2.0 kuruldu', str_contains($install['body'], 'Panele git')
    || str_contains($install['body'], 'Kurulum tamamlandı'));

$db = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$before = [
    'tablo'   => count($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)),
    'icerik'  => (int) $db->query('SELECT COUNT(*) FROM hi_content')->fetchColumn(),
    'terim'   => (int) $db->query('SELECT COUNT(*) FROM hi_terms')->fetchColumn(),
    'ayar'    => (int) $db->query('SELECT COUNT(*) FROM hi_options')->fetchColumn(),
    'surum'   => (string) $db->query("SELECT value FROM hi_options WHERE name = 'core_version'")->fetchColumn(),
    'baslik'  => (string) $db->query('SELECT title FROM hi_content ORDER BY id LIMIT 1')->fetchColumn(),
];

printf("     tablo=%d içerik=%d terim=%d ayar=%d sürüm=%s\n",
    $before['tablo'], $before['icerik'], $before['terim'], $before['ayar'], $before['surum']);

check('0.2.0 sürüm damgası doğru', str_contains($before['surum'], '0.2.0'), $before['surum']);
check('revisions tablosu HENÜZ yok', !in_array('hi_revisions',
    $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true));

/* ------------------------------------------------- 2. yeni paketi uygula */

echo "\n2. 0.3.0 güncellemesi (panelden)\n";

$auth = http($base . '/admin/login.php', [
    '_token'    => token($base . '/admin/login.php'),
    'kullanici' => 'yukselt',
    'sifre'     => $password,
]);

check('panele giriş yapıldı', $auth['status'] === 303, 'durum ' . $auth['status']);

$page = http($base . '/admin/system.php?sekme=guncelleme');
$tok  = preg_match('~name="_token"[^>]*value="([^"]+)"~', $page['body'], $m) === 1 ? $m[1] : '';

$upload = http(
    $base . '/admin/system.php?sekme=guncelleme',
    ['_token' => $tok, 'islem' => 'update-upload'],
    ['name' => basename($newZip), 'path' => $newZip]
);

check('paket kabul edildi', in_array($upload['status'], [200, 302, 303], true), 'durum ' . $upload['status']);

$after = http($base . '/admin/system.php?sekme=guncelleme');

if (preg_match('~<div class="alert alert-err[^"]*">(.*?)</div>~s', $after['body'], $m) === 1) {
    echo '     panel hatası: ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($m[1]))) . "\n";
}

/* ------------------------------------------------- 3. migration uygulandı mı */

echo "\n3. Şema yükseltmesi\n";

$tablesNow = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

check('revisions tablosu OLUŞTU', in_array('hi_revisions', $tablesNow, true),
    implode(', ', array_map('strval', $tablesNow)));

$cols = array_map(
    static fn(array $r): string => (string) $r['Field'],
    $db->query('SHOW COLUMNS FROM hi_content')->fetchAll(PDO::FETCH_ASSOC)
);

check('revision_no sütunu eklendi', in_array('revision_no', $cols, true));
check('trashed_at sütunu eklendi', in_array('trashed_at', $cols, true));

check('sürüm damgası 0.3.0 oldu',
    str_contains((string) $db->query("SELECT value FROM hi_options WHERE name = 'core_version'")->fetchColumn(), '0.3.0'),
    (string) $db->query("SELECT value FROM hi_options WHERE name = 'core_version'")->fetchColumn());

/* ------------------------------------------------- 4. mevcut veri korundu mu */

echo "\n4. Mevcut veri\n";

check('içerik sayısı korundu',
    (int) $db->query('SELECT COUNT(*) FROM hi_content')->fetchColumn() === $before['icerik'],
    $before['icerik'] . ' → ' . $db->query('SELECT COUNT(*) FROM hi_content')->fetchColumn());

check('terim sayısı korundu',
    (int) $db->query('SELECT COUNT(*) FROM hi_terms')->fetchColumn() === $before['terim']);

check('ilk içeriğin başlığı korundu',
    (string) $db->query('SELECT title FROM hi_content ORDER BY id LIMIT 1')->fetchColumn() === $before['baslik'],
    $before['baslik']);

check('blok ağacı okunabilir durumda',
    is_array(json_decode((string) $db->query(
        'SELECT blocks FROM hi_content WHERE blocks IS NOT NULL ORDER BY id LIMIT 1'
    )->fetchColumn(), true)));

/* ------------------------------------------------- 5. yükseltmeden sonra site */

echo "\n5. Yükseltmeden sonra\n";

foreach (['anasayfa' => '/', 'besleme' => '/feed', 'gösterge' => '/admin/index.php'] as $label => $path) {
    $r = http($base . $path);

    check(
        $label . ' çalışıyor',
        $r['status'] === 200 && preg_match('~(Fatal error|Parse error|Uncaught \w)~', $r['body']) !== 1,
        'durum ' . $r['status']
    );
}

$editor = http($base . '/admin/content-edit.php?id=4');

check('editör açılıyor', $editor['status'] === 200);
check('editörde sürüm alanı var', str_contains($editor['body'], 'name="beklenen_surum"'));
check('editörde zengin metin betiği var', str_contains($editor['body'], 'richtext.js'));
check('editör verisi JSON öğesinde', str_contains($editor['body'], 'id="hi-editor-data"'));

echo "\n" . str_repeat('-', 60) . "\n";

if ($fail === []) {
    echo "YUKSELTME DOGRULANDI ({$pass} denetim)\n";
    exit(0);
}

printf("BASARISIZ: %d / %d\n\n", count($fail), $pass + count($fail));

foreach ($fail as $f) {
    echo "  - {$f}\n";
}

exit(1);
