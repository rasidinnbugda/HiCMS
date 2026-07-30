<?php

declare(strict_types=1);

/**
 * HiCMS — gerçek veritabanına karşı uçtan uca doğrulama
 *
 * `build/smoke.php` veritabanına dokunmaz; bu yüzden yalnızca çalışma anında
 * görülen hataları (geçersiz SQL, kilitli dosya, oturum akışı) kaçırır. Bu
 * betik kurulum sihirbazını gerçekten çalıştırır, panelde yazma işlemleri
 * yapar ve sonucu doğrudan veritabanından okur.
 *
 * Kullanım:
 *   php -S 127.0.0.1:8130 router.php        (ayrı bir kabukta)
 *   php build/dbtest.php --url=http://127.0.0.1:8130 --port=3306 \
 *       --user=root --pass=gizli --db=hicms_test
 *
 * DİKKAT: --db ile verilen veritabanı DÜŞÜRÜLÜP yeniden kurulur ve kök
 * dizindeki config.php üzerine yazılır. Yalnızca geliştirme ortamında çalıştırın.
 *
 * @package HiCMS
 */

$root = dirname(__DIR__);

/* ----------------------------------------------------------- argümanlar */

$options = [
    'url'  => 'http://127.0.0.1:8130',
    'host' => '127.0.0.1',
    'port' => '3306',
    'user' => 'root',
    'pass' => '',
    'db'   => 'hicms_dbtest',
];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('~^--([a-z]+)=(.*)$~', $argument, $match) === 1 && isset($options[$match[1]])) {
        $options[$match[1]] = $match[2];
    }
}

$base = rtrim($options['url'], '/');
$dsn  = sprintf('mysql:host=%s;port=%s', $options['host'], $options['port']);

echo "HiCMS veritabanı doğrulaması\n";
echo str_repeat('-', 62) . "\n";
echo "Sunucu      : {$base}\n";
echo "Veritabanı  : {$options['db']} ({$options['host']}:{$options['port']})\n\n";

/* -------------------------------------------------------------- yardımcılar */

$passed = 0;
$failed = [];
$jar    = [];

/** @var array<int, string> $cookieJar */
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
 * @param array<string, string> $post
 * @return array{status: int, body: string, location: string}
 */
function request(string $url, array $post = [], bool $follow = false, bool $anonymous = false): array
{
    global $jar;

    $headers = ['Accept: text/html'];

    if (!$anonymous && $jar !== []) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $options = ['http' => [
        'method'          => $post === [] ? 'GET' : 'POST',
        'timeout'         => 120,
        'ignore_errors'   => true,
        'follow_location' => $follow ? 1 : 0,
        'max_redirects'   => $follow ? 5 : 0,
    ]];

    if ($post !== []) {
        array_unshift($headers, 'Content-Type: application/x-www-form-urlencoded');
        $options['http']['content'] = http_build_query($post);
    }

    $options['http']['header'] = implode("\r\n", $headers) . "\r\n";

    $body     = @file_get_contents($url, false, stream_context_create($options));
    $status   = 0;
    $location = '';

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
            $status = (int) $m[1];
        }

        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }

        if (!$anonymous
            && stripos($header, 'Set-Cookie:') === 0
            && preg_match('~^Set-Cookie:\s*([^=]+)=([^;]*)~i', $header, $m) === 1) {
            $jar[trim($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'body' => $body === false ? '' : $body, 'location' => $location];
}

function token(string $url): string
{
    $page = request($url, [], true);

    return preg_match('~name="_token"[^>]*value="([^"]+)"~', $page['body'], $m) === 1 ? $m[1] : '';
}

/**
 * @param list<string> $contains
 */
function page(string $label, string $path, int $expect = 200, array $contains = []): void
{
    global $base;

    $response = request($base . $path, [], true);
    $problems = [];

    if ($response['status'] !== $expect) {
        $problems[] = "durum {$response['status']} (beklenen {$expect})";
    }

    foreach ($contains as $needle) {
        if (!str_contains($response['body'], $needle)) {
            $problems[] = "'{$needle}' yok";
        }
    }

    if (preg_match('~(Fatal error|Parse error|Uncaught \w|Warning:\s)~', $response['body'], $m) === 1) {
        $problems[] = 'PHP hatası: ' . $m[1];
    }

    check($label, $problems === [], implode('; ', $problems));
}

/* --------------------------------------------------- 0. sunucu ve veritabanı */

echo "Hazırlık\n";

$reachable = @file_get_contents($base . '/install.php', false, stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true],
]));

if ($reachable === false) {
    echo "  HATA sunucuya erişilemedi. Ayrı bir kabukta şunu çalıştırın:\n";
    echo "       php -S 127.0.0.1:8130 router.php\n";
    exit(1);
}

try {
    $server = new PDO($dsn, $options['user'], $options['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $options['db']) . '`');
    check('veritabanı sunucusuna bağlanıldı', true);
} catch (Throwable $exception) {
    echo '  HATA veritabanına bağlanılamadı: ' . $exception->getMessage() . "\n";
    exit(1);
}

if (is_file($root . '/config.php')) {
    unlink($root . '/config.php');
}

/* ------------------------------------------------------------- 1. kurulum */

echo "\nKurulum sihirbazı\n";

$password = 'DbTestSifresi#2026';

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
    'title'     => 'HiCMS Doğrulama',
    'tagline'   => 'gerçek veritabanı testi',
    'name'      => 'Doğrulama Kullanıcısı',
    'username'  => 'dbtest',
    'email'     => 'dbtest@ornek.test',
    'password'  => $password,
    'demo'      => '1',
]);

preg_match_all('~<div class="alert alert-err">(.*?)</div>~s', $install['body'], $errors);

foreach ($errors[1] as $block) {
    echo '       ' . trim((string) preg_replace('/\s+/', ' ', strip_tags($block))) . "\n";
}

check(
    'kurulum tamamlandı',
    str_contains($install['body'], 'Panele git') || str_contains($install['body'], 'Kurulum tamamlandı'),
    'durum ' . $install['status']
);

check('config.php yazıldı', is_file($root . '/config.php'));

$db = new PDO(
    $dsn . ';dbname=' . $options['db'] . ';charset=utf8mb4',
    $options['user'],
    $options['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables   = array_map('strval', $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$expected = [
    'migrations', 'options', 'users', 'user_tokens', 'login_attempts',
    'content', 'content_meta', 'terms', 'term_entry',
    'media', 'comments', 'audit_log', 'jobs',
];

$missing = array_diff(array_map(static fn(string $t): string => 'hi_' . $t, $expected), $tables);

check('çekirdek tabloları kuruldu', $missing === [], implode(', ', $missing));

$count = static fn(string $table): int => (int) $db->query('SELECT COUNT(*) FROM `hi_' . $table . '`')->fetchColumn();

check('yönetici hesabı oluşturuldu', $count('users') === 1, $count('users') . ' kullanıcı');
check('örnek içerik eklendi', $count('content') === 6, $count('content') . ' içerik');
check('kategoriler ve etiketler eklendi', $count('terms') === 6, $count('terms') . ' terim');
check('site ayarları yazıldı', $count('options') >= 20, $count('options') . ' ayar');
check('planlı görevler kaydedildi', $count('jobs') === 4, $count('jobs') . ' görev');

$blocks = $db->query('SELECT blocks FROM hi_content WHERE blocks IS NOT NULL LIMIT 1')->fetchColumn();

check(
    'blok ağacı JSON olarak saklandı',
    is_string($blocks) && is_array(json_decode($blocks, true)),
    is_string($blocks) ? substr($blocks, 0, 60) : 'boş'
);

/* ------------------------------------------------------------- 2. ön yüz */

echo "\nÖn yüz\n";

page('anasayfa', '/', 200, ['HiCMS Doğrulama', 'gerçek veritabanı testi']);
page('tek yazı', '/yazi/hicms-kuruldu-nasil-devam-edilir', 200, ['Site kimliğini ayarlayın']);
page('blok dökümü', '/yazi/blok-editoruyle-icerik-kurmak', 200, ['Alıntı', 'fiyat-tablosu']);
page('sayfa', '/hakkinda', 200, ['Ne yapıyoruz']);
page('kategori arşivi', '/kategori/rehber', 200, ['Rehber']);
page('etiket arşivi', '/etiket/hicms');
page('arama', '/arama?q=blok', 200, ['blok']);
page('besleme', '/feed', 200, ['<rss', '<item>']);
page('yan sütun bileşenleri', '/', 200, ['Son Yazılar', 'Kategoriler', 'class="sidebar"']);
page('bilinmeyen adres 404', '/boyle-bir-sey-yok', 404);

/* --------------------------------------------------------------- 3. panel */

echo "\nPanel\n";

$auth = request($base . '/admin/login.php', [
    '_token'    => token($base . '/admin/login.php'),
    'kullanici' => 'dbtest',
    'sifre'     => $password,
]);

check('oturum açıldı', $auth['status'] === 303, 'durum ' . $auth['status']);

check(
    'yanlış şifre reddedildi',
    !str_contains(request($base . '/admin/login.php', [
        '_token'    => token($base . '/admin/login.php'),
        'kullanici' => 'dbtest',
        'sifre'     => 'yanlis-sifre',
    ])['body'], 'Gösterge')
);

foreach ([
    'gösterge paneli'    => ['/admin/index.php', []],
    'içerik listesi'     => ['/admin/content.php', ['hicms-kuruldu']],
    'sayfa listesi'      => ['/admin/content.php?tur=page', ['hakkinda']],
    'içerik düzenleyici' => ['/admin/content-edit.php?id=4', []],
    'terimler'           => ['/admin/terms.php?taxonomy=category', ['Rehber']],
    'yorumlar'           => ['/admin/comments.php', []],
    'medya'              => ['/admin/media.php', []],
    'kullanıcılar'       => ['/admin/users.php', ['dbtest']],
    'profil'             => ['/admin/profile.php', []],
    'menüler'            => ['/admin/menus.php', ['Ana Menü']],
    'bileşenler'         => ['/admin/widgets.php', ['Son Yazılar']],
    'görünüm'            => ['/admin/appearance.php', ['hiblog']],
    'eklentiler'         => ['/admin/plugins.php', ['HiSEO']],
    'ayarlar'            => ['/admin/settings.php?sekme=genel', []],
    'roller'             => ['/admin/settings.php?sekme=roller', []],
    'sistem: güncelleme' => ['/admin/system.php?sekme=guncelleme', []],
    'sistem: yedek'      => ['/admin/system.php?sekme=yedek', []],
    'sistem: durum'      => ['/admin/system.php?sekme=durum', []],
    'sistem: görev'      => ['/admin/system.php?sekme=gorev', ['core.']],
    'sistem: günlük'     => ['/admin/system.php?sekme=gunluk', ['system.install']],
] as $label => [$path, $needles]) {
    page($label, $path, 200, $needles);
}

/* ------------------------------------------------------- 4. yazma işlemleri */

echo "\nYazma işlemleri\n";

/*
 * Satır içi biçimler editörün ürettiği biçimde yazılır. Kaydedildikten sonra
 * geri okunduğunda AYNEN durmalı: istemcide uygulanan bir biçim sunucuda
 * kayboluyorsa zengin metin özelliği çalışmıyor demektir.
 *
 * Aynı dizide zararlı girdiler de var — onların DÜŞTÜĞÜ doğrulanıyor.
 */
$inline = '<strong>kalın</strong>, <em>italik</em>, <code>kod</code>, <mark>işaret</mark>, '
    . '<s>çizili</s>, m<sup>2</sup>, H<sub>2</sub>O ve <a href="/hakkinda">bağlantı</a>';

$hostile = 'zararsız<script>alert(1)</script> <strong style="color:red" onclick="alert(2)">x</strong> '
    . '<a href="javascript:alert(3)">y</a>';

$tree = json_encode([
    ['type' => 'paragraph', 'data' => ['text' => 'Doğrulama betiğinin yazdığı paragraf.', 'lead' => true]],
    ['type' => 'heading',   'data' => ['text' => 'Ara başlık', 'level' => 'h2']],
    ['type' => 'paragraph', 'data' => ['text' => $inline, 'lead' => false]],
    ['type' => 'paragraph', 'data' => ['text' => $hostile, 'lead' => false]],
    ['type' => 'quote',     'data' => ['text' => 'Test edilmeyen kod, çalıştığı varsayılan koddur.', 'cite' => 'Doğrulama']],
], JSON_UNESCAPED_UNICODE);

request($base . '/admin/content-edit.php?tur=post', [
    '_token'  => token($base . '/admin/content-edit.php?tur=post'),
    'baslik'  => 'Doğrulama betiğiyle oluşturulan yazı',
    'kisa_ad' => 'dogrulama-yazisi',
    'ozet'    => 'Otomatik oluşturuldu.',
    'bloklar' => $tree,
    'durum'   => 'published',
    'yazar'   => '1',
    'tarih'   => date('Y-m-d\TH:i'),
]);

$created = $db->query("SELECT id, title, status, blocks FROM hi_content WHERE slug = 'dogrulama-yazisi'")
    ->fetch(PDO::FETCH_ASSOC);

check('yazı oluşturuldu', $created !== false);

$newId = (int) ($created['id'] ?? 0);

if ($created !== false) {
    check('durum yayında', $created['status'] === 'published', (string) $created['status']);
    check('beş blok saklandı', count((array) json_decode((string) $created['blocks'], true)) === 5);
    page('yeni yazı ön yüzde', '/yazi/dogrulama-yazisi', 200, ['Doğrulama betiğinin yazdığı']);

    /* ------------------------------------------- satır içi biçim gidiş-dönüşü */

    $tree = (array) json_decode((string) $created['blocks'], true);
    $kept = (string) ($tree[2]['data']['text'] ?? '');
    $bad  = (string) ($tree[3]['data']['text'] ?? '');

    foreach (['strong', 'em', 'code', 'mark', 's', 'sup', 'sub'] as $tag) {
        check("satır içi <{$tag}> korundu", str_contains($kept, '<' . $tag . '>'), $kept);
    }

    check('satır içi bağlantı korundu', str_contains($kept, 'href="/hakkinda"'), $kept);
    check('Türkçe karakterler korundu', str_contains($kept, 'işaret') && str_contains($kept, 'çizili'));

    check('script bloğu düştü', !str_contains(strtolower($bad), 'alert(1)'), $bad);
    check('style özniteliği düştü', !str_contains($bad, 'style='), $bad);
    check('onclick özniteliği düştü', !str_contains(strtolower($bad), 'onclick'), $bad);
    check('javascript: şeması düştü', !str_contains(strtolower($bad), 'javascript:'), $bad);
    check('zararsız metin korundu', str_contains($bad, 'zararsız'), $bad);

    // Ön yüzde de gerçek biçim basılmalı, ham etiket değil.
    $rendered = request($base . '/yazi/dogrulama-yazisi', [], true)['body'];

    check('ön yüzde kalın basıldı', str_contains($rendered, '<strong>kalın</strong>'));
    check('ön yüzde ham etiket görünmüyor', !str_contains($rendered, '&lt;strong&gt;kalın'));
    check('ön yüzde alert sızmadı', !str_contains(strtolower($rendered), 'alert(1)'));
}

if ($newId > 0) {
    /*
     * `beklenen_surum` artık ZORUNLU: alanın yokluğu iyimser kilidi atlamak
     * anlamına geleceği için sunucu isteği reddediyor. Test de gerçek panelin
     * gönderdiği alanı göndermek zorunda.
     */
    $revNow = (int) $db->query('SELECT revision_no FROM hi_content WHERE id = ' . $newId)->fetchColumn();

    request($base . '/admin/content-edit.php?id=' . $newId, [
        '_token'         => token($base . '/admin/content-edit.php?id=' . $newId),
        'beklenen_surum' => (string) $revNow,
        'baslik'         => 'Doğrulama yazısı — güncellendi',
        'kisa_ad'        => 'dogrulama-yazisi',
        'ozet'           => 'Güncellendi.',
        'bloklar'        => $tree,
        'durum'          => 'draft',
        'yazar'          => '1',
        'tarih'          => date('Y-m-d\TH:i'),
    ]);

    $edited = $db->query('SELECT title, status FROM hi_content WHERE id = ' . $newId)->fetch(PDO::FETCH_ASSOC);

    check('başlık güncellendi', str_contains((string) ($edited['title'] ?? ''), 'güncellendi'));
    check('taslağa çekildi', ($edited['status'] ?? '') === 'draft');

    check(
        'taslak ziyaretçiye kapalı',
        request($base . '/yazi/dogrulama-yazisi', [], false, true)['status'] === 404
    );

    check(
        'taslak yazara önizlenebilir',
        request($base . '/yazi/dogrulama-yazisi', [], true)['status'] === 200
    );
}

request($base . '/admin/terms.php?taxonomy=category', [
    '_token'   => token($base . '/admin/terms.php?taxonomy=category'),
    'islem'    => 'save',
    'ad'       => 'Doğrulama Kategorisi',
    'kisa_ad'  => 'dogrulama-kategorisi',
    'aciklama' => 'Otomatik eklendi.',
    'renk'     => '#95389e',
]);

check(
    'terim eklendi',
    (int) $db->query("SELECT COUNT(*) FROM hi_terms WHERE slug = 'dogrulama-kategorisi'")->fetchColumn() === 1
);

request($base . '/admin/settings.php?sekme=genel', [
    '_token'           => token($base . '/admin/settings.php?sekme=genel'),
    'bolum'            => 'genel',
    'site_title'       => 'Başlık Değişti',
    'site_tagline'     => 'slogan değişti',
    'site_description' => 'açıklama değişti',
    'admin_email'      => 'dbtest@ornek.test',
]);

check(
    'ayar kaydedildi ve ön yüze yansıdı',
    str_contains((string) $db->query("SELECT value FROM hi_options WHERE name = 'site_title'")->fetchColumn(), 'Başlık Değişti')
        && str_contains(request($base . '/')['body'], 'Başlık Değişti')
);

request($base . '/admin/plugins.php', [
    '_token'  => token($base . '/admin/plugins.php'),
    'islem'   => 'activate',
    'eklenti' => 'hi-seo',
]);

$activePlugins = (string) $db->query("SELECT value FROM hi_options WHERE name = 'active_plugins'")->fetchColumn();
$afterPlugin   = array_map('strval', $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

check('eklenti etkinleştirildi', str_contains($activePlugins, 'hi-seo'), $activePlugins);
check('eklenti migration\'ı çalıştı', in_array('hi_seo_redirects', $afterPlugin, true));

/* ------------------------------------------------- 4a. yazma deneyimi */

echo "\nYazma deneyimi\n";

$expected = [
    'migrations', 'options', 'users', 'user_tokens', 'login_attempts',
    'content', 'content_meta', 'terms', 'term_entry',
    'media', 'comments', 'audit_log', 'jobs', 'revisions',
];

$tablesNow = array_map('strval', $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

check('sürüm tablosu kuruldu', in_array('hi_revisions', $tablesNow, true));

$cols = array_map(
    static fn(array $r): string => (string) $r['Field'],
    $db->query('SHOW COLUMNS FROM hi_content')->fetchAll(PDO::FETCH_ASSOC)
);

check('revision_no sütunu eklendi', in_array('revision_no', $cols, true));
check('trashed_at sütunu eklendi', in_array('trashed_at', $cols, true));

if ($newId > 0) {
    // Kaydetme sürüm üretmeli.
    $revCount = (int) $db->query('SELECT COUNT(*) FROM hi_revisions WHERE entry_id = ' . $newId)->fetchColumn();

    check('kaydetme sürüm üretti', $revCount >= 1, $revCount . ' sürüm');

    $rev = $db->query('SELECT kind, title, blocks FROM hi_revisions WHERE entry_id = ' . $newId
        . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    check('sürüm türü save', ($rev['kind'] ?? '') === 'save', (string) ($rev['kind'] ?? ''));
    check(
        'sürüm blok ağacını taşıyor',
        is_array(json_decode((string) ($rev['blocks'] ?? ''), true)),
        substr((string) ($rev['blocks'] ?? ''), 0, 40)
    );

    /* ------------------------------------------------- iyimser kilit */

    $current = (int) $db->query('SELECT revision_no FROM hi_content WHERE id = ' . $newId)->fetchColumn();

    check('sürüm sayacı artıyor', $current >= 1, (string) $current);

    // ESKİ sayaçla kaydetmek REDDEDİLMELİ.
    $stale = request($base . '/admin/content-edit.php?id=' . $newId, [
        '_token'         => token($base . '/admin/content-edit.php?id=' . $newId),
        'beklenen_surum' => (string) max(0, $current - 1),
        'baslik'         => 'ESKİ TABANLA YAZILDI',
        'kisa_ad'        => 'dogrulama-yazisi',
        'bloklar'        => '[]',
        'durum'          => 'draft',
        'yazar'          => '1',
        'tarih'          => date('Y-m-d\TH:i'),
    ]);

    $titleAfter = (string) $db->query('SELECT title FROM hi_content WHERE id = ' . $newId)->fetchColumn();

    check(
        'eski sayaçla kayıt reddedildi',
        !str_contains($titleAfter, 'ESKİ TABANLA'),
        $titleAfter
    );

    check(
        'çakışmada yazılan kaybolmadı (otomatik kayıt slotu)',
        (int) $db->query('SELECT COUNT(*) FROM hi_revisions WHERE entry_id = ' . $newId
            . " AND kind = 'autosave'")->fetchColumn() >= 1
    );

    // Sürüm alanı HİÇ gönderilmezse de reddedilmeli (fail-open olmamalı).
    $missing = request($base . '/admin/content-edit.php?id=' . $newId, [
        '_token'  => token($base . '/admin/content-edit.php?id=' . $newId),
        'baslik'  => 'ALAN YOKKEN YAZILDI',
        'kisa_ad' => 'dogrulama-yazisi',
        'bloklar' => '[]',
        'durum'   => 'draft',
        'yazar'   => '1',
        'tarih'   => date('Y-m-d\TH:i'),
    ]);

    check(
        'sürüm alanı yokken kayıt reddedildi',
        !str_contains(
            (string) $db->query('SELECT title FROM hi_content WHERE id = ' . $newId)->fetchColumn(),
            'ALAN YOKKEN'
        )
    );

    // DOĞRU sayaçla kaydetmek geçmeli.
    $fresh = (int) $db->query('SELECT revision_no FROM hi_content WHERE id = ' . $newId)->fetchColumn();

    request($base . '/admin/content-edit.php?id=' . $newId, [
        '_token'         => token($base . '/admin/content-edit.php?id=' . $newId),
        'beklenen_surum' => (string) $fresh,
        'baslik'         => 'DOĞRU TABANLA YAZILDI',
        'kisa_ad'        => 'dogrulama-yazisi',
        'bloklar'        => '[]',
        'durum'          => 'draft',
        'yazar'          => '1',
        'tarih'          => date('Y-m-d\TH:i'),
    ]);

    check(
        'doğru sayaçla kayıt geçti',
        str_contains(
            (string) $db->query('SELECT title FROM hi_content WHERE id = ' . $newId)->fetchColumn(),
            'DOĞRU TABANLA'
        )
    );
}

/*
 * Toplu durum değişikliği de sayacı artırmalı: bu yol save()'i çağırmıyor,
 * artırmazsa kilit o yazmayı hiç görmez.
 */
$bulkTarget = (int) $db->query("SELECT id FROM hi_content WHERE type = 'post' ORDER BY id LIMIT 1")->fetchColumn();

if ($bulkTarget > 0) {
    $before = (int) $db->query('SELECT revision_no FROM hi_content WHERE id = ' . $bulkTarget)->fetchColumn();

    request($base . '/admin/content.php?tur=post', [
        '_token' => token($base . '/admin/content.php?tur=post'),
        'islem'  => 'draft',
        'ids'    => [(string) $bulkTarget],
    ]);

    $after = (int) $db->query('SELECT revision_no FROM hi_content WHERE id = ' . $bulkTarget)->fetchColumn();

    check('toplu işlem sayacı artırdı', $after > $before, $before . ' → ' . $after);
}

/* --------------------------------------------- 4b. güvenlik regresyonları */

echo "\nGüvenlik regresyonları\n";

/*
 * Bu denetimler 0.2.0'da bulunup düzeltilen hataların geri gelmemesini sağlar.
 * Hepsi gerçek HTTP üzerinden, oturum açık olarak yapılır.
 */

// GET ile çıkış oturumu kapatmamalı; onay ekranı göstermeli.
$logoutGet = request($base . '/admin/logout.php', [], true);

check(
    'GET ile çıkış oturumu kapatmıyor',
    $logoutGet['status'] === 200 && str_contains($logoutGet['body'], 'emin misiniz'),
    'durum ' . $logoutGet['status']
);

check(
    'GET çıkıştan sonra oturum hâlâ açık',
    !str_contains(request($base . '/admin/index.php', [], true)['body'], 'name="sifre"')
);

// GET ile içerik silme reddedilmeli (tarayıcı ön-getirmesi içerik silmesin).
$deleteGet = request($base . '/admin/content-delete.php?id=1', [], false);

check(
    'GET ile silme reddediliyor',
    $deleteGet['status'] === 303,
    'durum ' . $deleteGet['status']
);

check(
    'GET silme denemesinden sonra içerik duruyor',
    (int) $db->query('SELECT COUNT(*) FROM hi_content WHERE id = 1')->fetchColumn() === 1
);

// Ayarlarda 'bolum' alanı olmadan gönderim reddedilmeli: 0.2.0'da eksik alan
// GET sekmesinden tahmin ediliyor ve YANLIŞ bölümün alanları kaydediliyordu.
$titleBefore = (string) $db->query("SELECT value FROM hi_options WHERE name = 'site_title'")->fetchColumn();

request($base . '/admin/settings.php?sekme=okuma', [
    '_token'     => token($base . '/admin/settings.php?sekme=okuma'),
    'site_title' => 'BU KAYDEDILMEMELI',
]);

check(
    'bölüm bildirilmeyen ayar gönderimi reddediliyor',
    (string) $db->query("SELECT value FROM hi_options WHERE name = 'site_title'")->fetchColumn() === $titleBefore,
    'başlık değişti'
);

// Sistem bölümünde tanınmayan işlem reddedilmeli: 0.2.0'da listede olmayan
// işlem hiçbir izin denetiminden geçmiyordu ('run-jobs' listede yoktu).
$unknown = request($base . '/admin/system.php?sekme=durum', [
    '_token' => token($base . '/admin/system.php?sekme=durum'),
    'islem'  => 'boyle-bir-islem-yok',
]);

check(
    'tanınmayan sistem işlemi reddediliyor',
    $unknown['status'] === 303,
    'durum ' . $unknown['status']
);

/* ------------------------------------------------------- 5. planlı görevler */

echo "\nPlanlı görevler\n";

$db->exec("UPDATE hi_jobs SET run_at = '2020-01-01 00:00:00' WHERE name = 'core.prune_logs'");

request($base . '/');

$job = $db->query("SELECT run_at, last_error FROM hi_jobs WHERE name = 'core.prune_logs'")->fetch(PDO::FETCH_ASSOC);

check(
    'geciken görev çalıştı ve yeniden planlandı',
    $job !== false && (string) $job['run_at'] > '2020-01-02 00:00:00',
    (string) ($job['run_at'] ?? '')
);

check('görev hatasız tamamlandı', ($job['last_error'] ?? null) === null || ($job['last_error'] ?? '') === '');

/* ---------------------------------------------------------------- 6. silme */

if ($newId > 0) {
    echo "\nSilme\n";

    // Silme artık POST: GET ile silme tarayıcı ön-getirmesine açıktı.
    request($base . '/admin/content-delete.php', [
        '_token' => token($base . '/admin/content-edit.php?id=' . $newId),
        'id'     => (string) $newId,
    ]);

    $remaining = $db->query('SELECT status FROM hi_content WHERE id = ' . $newId)->fetchColumn();

    check('yazı silindi veya çöpe taşındı', $remaining === false || $remaining === 'trash', var_export($remaining, true));
}

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
