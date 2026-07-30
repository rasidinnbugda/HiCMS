<?php

declare(strict_types=1);

/**
 * BEŞ EKLENTİ BİRLİKTE
 *
 * Her eklenti bağımsız taşındı ve her biri yalnızca kendisiyle sınandı. Beşi
 * aynı anda etkinken ilk kez burada çalışıyor: kanca çakışması, aynı ada iki
 * blok kaydı, aynı rotaya iki eklenti, çelişkili meta etiketi, kapatma
 * sırasında birbirinin kancasını sökme gibi sorunlar yalnızca burada görünür.
 *
 * php allplugins.php <taban-url> <db-port> <db-adi>
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8130', '/');
$port = $argv[2] ?? '3307';
$name = $argv[3] ?? 'hicms_dev';

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
 * @param array<string, string> $post
 * @return array{status: int, body: string}
 */
function http(string $url, array $post = [], bool $follow = true): array
{
    global $jar;

    $h = ['Accept: text/html'];

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        $h[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $o = ['http' => [
        'method'          => $post === [] ? 'GET' : 'POST',
        'timeout'         => 120,
        'ignore_errors'   => true,
        'follow_location' => $follow ? 1 : 0,
        'max_redirects'   => $follow ? 5 : 0,
    ]];

    if ($post !== []) {
        array_unshift($h, 'Content-Type: application/x-www-form-urlencoded');
        $o['http']['content'] = http_build_query($post);
    }

    $o['http']['header'] = implode("\r\n", $h) . "\r\n";

    $body = @file_get_contents($url, false, stream_context_create($o));
    $code = 0;

    foreach ($http_response_header ?? [] as $x) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $x, $m) === 1) {
            $code = (int) $m[1];
        }

        if (stripos($x, 'Set-Cookie:') === 0
            && preg_match('~^Set-Cookie:\s*([^=]+)=([^;]*)~i', $x, $m) === 1) {
            $jar[trim($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $code, 'body' => $body === false ? '' : $body];
}

function token(string $url): string
{
    return preg_match('~name="_token"[^>]*value="([^"]+)"~', http($url)['body'], $m) === 1 ? $m[1] : '';
}

/** Sayfada PHP hatası var mı? */
function clean(string $body): bool
{
    return preg_match('~(Fatal error|Parse error|Uncaught \w|Warning:\s|Deprecated:\s)~', $body) !== 1;
}

echo "Beş eklenti birlikte\n" . str_repeat('-', 58) . "\n";

$db = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$name};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/* ------------------------------------------------------------------ giriş */

$auth = http($base . '/admin/login.php', [
    '_token'    => token($base . '/admin/login.php'),
    'kullanici' => 'dbtest',
    'sifre'     => 'DbTestSifresi#2026',
], false);

check('panele giriş yapıldı', $auth['status'] === 303, 'durum ' . $auth['status']);

/* --------------------------------------------------- hepsini etkinleştir */

echo "\nEtkinleştirme\n";

$slugs = ['hi-seo', 'hi-lang', 'hi-types', 'hi-forms', 'hi-media'];

foreach ($slugs as $slug) {
    http($base . '/admin/plugins.php', [
        '_token'  => token($base . '/admin/plugins.php'),
        'islem'   => 'activate',
        'eklenti' => $slug,
    ]);

    $active = (string) $db->query("SELECT value FROM hi_options WHERE name = 'active_plugins'")->fetchColumn();

    check($slug . ' etkin', str_contains($active, $slug), $active);
}

$activeNow = (string) $db->query("SELECT value FROM hi_options WHERE name = 'active_plugins'")->fetchColumn();

check('beşi birlikte etkin', count(array_filter($slugs, static fn(string $s): bool
    => str_contains($activeNow, $s))) === 5, $activeNow);

/* ------------------------------------------------------- panel gezinmesi */

echo "\nPanel (beşi etkinken)\n";

$pages = [
    'gösterge'      => '/admin/index.php',
    'içerik listesi' => '/admin/content.php',
    'içerik düzenle' => '/admin/content-edit.php?id=4',
    'medya'         => '/admin/media.php',
    'eklentiler'    => '/admin/plugins.php',
    'ayarlar'       => '/admin/settings.php?sekme=genel',
    'sistem durum'  => '/admin/system.php?sekme=durum',
    'kullanıcılar'  => '/admin/users.php',
    'terimler'      => '/admin/terms.php?taxonomy=category',
    'menüler'       => '/admin/menus.php',
    'görünüm'       => '/admin/appearance.php',
];

foreach ($pages as $label => $path) {
    $r = http($base . $path);

    check($label, $r['status'] === 200 && clean($r['body']), 'durum ' . $r['status']
        . (clean($r['body']) ? '' : ', PHP hatası'));
}

/* ------------------------------------------------ eklenti kendi ekranları */

echo "\nEklenti ekranları\n";

foreach ($slugs as $slug) {
    $r = http($base . '/admin/plugin.php?eklenti=' . $slug);

    check($slug . ' ekranı', $r['status'] === 200 && clean($r['body']), 'durum ' . $r['status']
        . (clean($r['body']) ? '' : ', PHP hatası'));
}

/* -------------------------------------------------------------- ön yüz */

echo "\nÖn yüz (beşi etkinken)\n";

foreach ([
    'anasayfa' => '/',
    'tek yazı' => '/yazi/hicms-kuruldu-nasil-devam-edilir',
    'sayfa'    => '/hakkinda',
    'kategori' => '/kategori/rehber',
    'arama'    => '/arama?q=blok',
    'besleme'  => '/feed',
    // PHP sayısal dizi anahtarını tamsayıya çevirir; etiket bilinçli olarak
    // sayıyla başlamıyor.
    'bilinmeyen adres' => '/boyle-bir-adres-yok',
] as $label => $path) {
    $label    = (string) $label;
    $r        = http($base . $path);
    $expected = $label === 'bilinmeyen adres' ? 404 : 200;

    check($label, $r['status'] === $expected && clean($r['body']), 'durum ' . $r['status']
        . (clean($r['body']) ? '' : ', PHP hatası'));
}

/* ------------------------------------------- meta etiketi mükerrer mi */

echo "\nMeta etiketleri\n";

$single = http($base . '/yazi/hicms-kuruldu-nasil-devam-edilir')['body'];

foreach (['og:title', 'og:description', 'twitter:card', 'canonical'] as $tag) {
    $count = substr_count($single, $tag);

    check(
        $tag . ' bir kez basılıyor',
        $count <= 1,
        $count . ' kez'
    );
}

/* ------------------------------------------- kapatma başkasını bozmuyor */

echo "\nSırayla kapatma\n";

foreach (array_reverse($slugs) as $slug) {
    $r = http($base . '/admin/plugins.php', [
        '_token'  => token($base . '/admin/plugins.php'),
        'islem'   => 'deactivate',
        'eklenti' => $slug,
    ]);

    $home = http($base . '/');
    $dash = http($base . '/admin/index.php');

    check(
        $slug . ' kapatıldı, site ve panel sağlam',
        clean($home['body']) && clean($dash['body']) && $home['status'] === 200 && $dash['status'] === 200,
        'ön yüz ' . $home['status'] . ' / panel ' . $dash['status']
    );
}

$after = (string) $db->query("SELECT value FROM hi_options WHERE name = 'active_plugins'")->fetchColumn();

check('hepsi kapandı', !array_filter($slugs, static fn(string $s): bool => str_contains($after, $s)), $after);

echo "\n" . str_repeat('-', 58) . "\n";
printf("geçen: %d  düşen: %d\n", $pass, count($fail));

foreach ($fail as $f) {
    echo "  - {$f}\n";
}

exit($fail === [] ? 0 : 1);
