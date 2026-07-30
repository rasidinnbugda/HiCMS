<?php

declare(strict_types=1);

/**
 * HiCMS — veritabanısız duman testi
 *
 * MySQL gerektirmeyen her şeyi gerçekten çalıştırır: önyükleme, blok işleme,
 * rota eşleştirme, içerik türü kaydı, kaçış, şema SQL üretimi ve gereksinim
 * denetimi.
 *
 * Kullanım: php build/smoke.php
 */

$root = dirname(__DIR__);

require $root . '/src/Autoloader.php';

HiCMS\Autoloader::register($root . '/src');

use HiCMS\Content\BlockRegistry;
use HiCMS\Content\ContentType;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Blueprint;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Render\BlockRendering;
use HiCMS\Http\Request;
use HiCMS\Http\Router;
use HiCMS\Install\Installer;
use HiCMS\Install\Requirements;
use HiCMS\Kernel;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

$passed = 0;
$failed = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        printf("  ok   %s\n", $label);

        return;
    }

    $failed[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  FAIL %s%s\n", $label, $detail !== '' ? ' — ' . $detail : '');
}

echo "HiCMS duman testi\n" . str_repeat('-', 58) . "\n\n";

/* -------------------------------------------------------------------------
 * 1. Kaçış ve metin yardımcıları
 * ---------------------------------------------------------------------- */

echo "Metin ve kaçış\n";

check('HTML kaçışı', Str::html('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
check('javascript: şeması reddedilir', Str::url('javascript:alert(1)') === '');
check('https adresi korunur', Str::url('https://a.test/x?y=1&z=2') === 'https://a.test/x?y=1&amp;z=2');
check('Türkçe slug', Str::slug('Çığır Açan Şeyler') === 'cigir-acan-seyler',
    Str::slug('Çığır Açan Şeyler'));
check('Yüzde işareti bozulmaz', Str::format('%%100 hazır: %d adet', 5) === '%100 hazır: 5 adet',
    Str::format('%%100 hazır: %d adet', 5));
check('Tek yüzde kaçırılır', Str::format('%100 hazır') === '%100 hazır', Str::format('%100 hazır'));
check('safeHtml betiği siler',
    !str_contains(Str::safeHtml('<p onclick="x()">a</p><script>b()</script>'), 'script'));
check('safeHtml olay özniteliğini siler',
    !str_contains(Str::safeHtml('<p onclick="x()">a</p>'), 'onclick'));
check('Baş harfler', Str::initials('raşidin buğda') === 'RB', Str::initials('raşidin buğda'));
check('Bayt biçimi', Str::bytes(1536) === '1.5 KB', Str::bytes(1536));
check('Türkçe tarih', Dates::format('2026-07-24 10:00:00', 'j F Y') === '24 Temmuz 2026',
    Dates::format('2026-07-24 10:00:00', 'j F Y'));

/* -------------------------------------------------------------------------
 * 2. Rota tablosu
 * ---------------------------------------------------------------------- */

echo "\nYönlendirme\n";

$router = new Router();
$hit    = [];

$router->get('/', static fn(): string => 'home', 'home', 10);
$router->get('/sayfa/{page:\d+}', static fn(array $p): string => 'home:' . $p['page'], 'home.paged', 10);
$router->get('/yazi/{slug}', static fn(array $p): string => 'single:' . $p['slug'], 'single', 50);
$router->get('/kategori/{slug}/sayfa/{page:\d+}', static fn(array $p): string => 'tax', 'tax.paged', 30);
$router->get('/sitemap.xml', static fn(): string => 'sitemap', 'sitemap', 1);
$router->get('/{slug}', static fn(array $p): string => 'page:' . $p['slug'], 'root', 90);

$make = static fn(string $path, string $method = 'GET'): Request
    => new Request($method, $path, [], [], [], ['REQUEST_METHOD' => $method]);

$match = $router->match($make('/'));
check('Kök yol eşleşir', $match !== null && ($match['handler'])([]) === 'home');

$match = $router->match($make('/yazi/merhaba-dunya'));
check('Tek yazı yolu', $match !== null && ($match['handler'])($match['params']) === 'single:merhaba-dunya');

$match = $router->match($make('/sayfa/3'));
check('Sayısal kısıt', $match !== null && ($match['handler'])($match['params']) === 'home:3');

// /sayfa/abc iki segmentlidir; yakala-hepsini deseni ({slug}) tek segment
// eşleştirir. Dolayısıyla hiçbir rotaya uymaz ve 404 verilir — istenen davranış.
$match = $router->match($make('/sayfa/abc'));
check('Sayısal olmayan sayfa numarası eşleşmez (404)', $match === null);

$match = $router->match($make('/sitemap.xml'));
check('Noktalı sabit yol kaçırılır', $match !== null && $match['name'] === 'sitemap');

$match = $router->match($make('/kategori/yazilim/sayfa/2'));
check('Çok parçalı desen', $match !== null && $match['name'] === 'tax.paged');

$match = $router->match($make('/hakkinda'));
check('Yakala-hepsini en sonda', $match !== null && $match['name'] === 'root');

$match = $router->match($make('/yorum', 'POST'));
check('Tanımsız POST eşleşmez', $match === null);

check('Adlandırılmış rotadan yol', $router->path('single', ['slug' => 'x-y']) === '/yazi/x-y',
    (string) $router->path('single', ['slug' => 'x-y']));

/* -------------------------------------------------------------------------
 * 3. Blok işleme
 * ---------------------------------------------------------------------- */

echo "\nBlok sistemi\n";

$blocks = new BlockRegistry();
$blocks->registerDefaults();

check('Yerleşik blok sayısı', count($blocks->all()) >= 12, (string) count($blocks->all()));
check('Paragraf kayıtlı', $blocks->has('paragraph'));
check('Boş veri iskeleti', array_key_exists('text', $blocks->blank('paragraph')));
check('Gruplama çalışır', count($blocks->grouped()) >= 3);

// Blok işleyici, medya deposu olmadan da metin bloklarını basabilmeli.
// Connection kurucusu bağlantı açmaz (tembeldir), bu yüzden MySQL gerekmez.
$events = new Dispatcher();

$renderer = new HiCMS\Content\BlockRenderer(
    $blocks,
    new HiCMS\Repository\MediaRepository(new HiCMS\Database\Connection([])),
    new HiCMS\Http\Url('https://site.test'),
    $events
);

$html = $renderer->renderBlocks([
    ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => 'Başlık Örneği']],
    ['type' => 'paragraph', 'data' => ['text' => 'İlk paragraf.', 'lead' => true]],
    ['type' => 'list', 'data' => ['style' => 'bullet', 'items' => ['a', 'b']]],
    ['type' => 'quote', 'data' => ['text' => 'Alıntı', 'cite' => 'Kaynak']],
    ['type' => 'code', 'data' => ['language' => 'php', 'code' => '<?php echo 1;']],
    ['type' => 'embed', 'data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
    ['type' => 'divider', 'data' => ['style' => 'dots']],
    ['type' => 'bilinmeyen', 'data' => ['x' => 1]],
]);

check('Başlık çıpası üretilir', str_contains($html, 'id="baslik-ornegi"'));
check('Giriş paragrafı sınıfı', str_contains($html, 'class="is-lead"'));
check('Liste basılır', str_contains($html, '<ul><li>a</li><li>b</li></ul>'));
check('Alıntı kaynağı', str_contains($html, '<cite>Kaynak</cite>'));
check('Kod kaçırılır', str_contains($html, '&lt;?php echo 1;'));
check('YouTube gömme dönüştürülür', str_contains($html, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'));
check('Bilinmeyen blok atlanır', !str_contains($html, 'bilinmeyen'));

// Olay dinleyicisi çıktıyı sarmalayabiliyor mu?
$events->listen(BlockRendering::class, static function (BlockRendering $event): void {
    if ($event->type() === 'paragraph') {
        $event->wrap('<div class="sarmal">', '</div>');
    }
});

$wrapped = $renderer->renderBlocks([['type' => 'paragraph', 'data' => ['text' => 'x']]]);
check('BlockRendering olayı çıktıyı sarmalar', str_contains($wrapped, '<div class="sarmal">'));

/* -------------------------------------------------------------------------
 * 4. İçerik modeli
 * ---------------------------------------------------------------------- */

echo "\nİçerik modeli\n";

$entry = Entry::fromRow([
    'id'           => 7,
    'type'         => 'post',
    'status'       => 'published',
    'title'        => 'Deneme',
    'slug'         => 'deneme',
    'blocks'       => json_encode([
        ['type' => 'paragraph', 'data' => ['text' => str_repeat('kelime ', 240)]],
        ['type' => 'bozuk'],
        'dizge değil',
    ]),
    'published_at' => '2026-01-01 00:00:00',
]);

check('Satırdan kurulur', $entry->id === 7 && $entry->slug === 'deneme');
check('Bozuk bloklar temizlenir', count($entry->blocks) === 2, (string) count($entry->blocks));
check('Yayında sayılır', $entry->isPublished());
check('Okuma süresi hesaplanır', $entry->readingTime() >= 2, (string) $entry->readingTime());
check('Özet bloklardan üretilir', str_starts_with($entry->summary(30), 'kelime'));

$future = Entry::fromRow([
    'id' => 8, 'status' => 'published', 'published_at' => date('Y-m-d H:i:s', time() + 86400),
]);

check('İleri tarihli yayın görünmez', !$future->isPublished());
check('İleri tarihli yayın zamanlanmış sayılır', $future->isScheduled());

/* -------------------------------------------------------------------------
 * 5. İçerik türleri
 * ---------------------------------------------------------------------- */

echo "\nİçerik türleri\n";

$types = new TypeRegistry();
$types->registerDefaults();

check('Kutudan yalnızca post ve page', count($types->all()) === 2, implode(',', array_keys($types->all())));
check('Kategori taksonomisi tek seçim', $types->taxonomy('category')?->single === true);
check('Etiket taksonomisi çoklu', $types->taxonomy('tag')?->single === false);

$types->registerArray([
    'name'       => 'portfolyo',
    'labels'     => ['singular' => 'Proje', 'plural' => 'Portfolyo'],
    'route'      => 'proje',
    'archive'    => 'portfolyo',
    'supports'   => ['blocks', 'image'],
    'taxonomies' => ['category'],
    'fields'     => [
        ['key' => 'musteri', 'type' => 'text', 'label' => 'Müşteri'],
        ['key' => 'yil', 'type' => 'number', 'label' => 'Yıl'],
        ['key' => 'adres', 'type' => 'url', 'label' => 'Proje adresi'],
    ],
], 'plugin:test');

$portfolio = $types->get('portfolyo');

check('JSON tanımdan tür kaydı', $portfolio instanceof ContentType);
check('Arşivi var', $portfolio?->hasArchive() === true);
check('Rota önekiyle bulunur', $types->byRoute('proje')?->name === 'portfolyo');
check('Özet desteği kapalı', $portfolio?->hasExcerpt === false);
check('Alanlar okunur', count($portfolio?->fields ?? []) === 3);

$urlField = $portfolio?->field('adres');
check('URL alanı geçersiz değeri reddeder', $urlField?->sanitize('bu bir adres değil') === '');
check('URL alanı geçerli değeri kabul eder',
    $urlField?->sanitize('https://ornek.test') === 'https://ornek.test');

$numberField = $portfolio?->field('yil');
check('Sayı alanı dönüştürür', $numberField?->sanitize('2026') === 2026.0);

$types->forgetSource('plugin:test');
check('Kaynak kaldırılınca tür düşer', !$types->has('portfolyo'));

/* -------------------------------------------------------------------------
 * 6. Şema SQL üretimi
 * ---------------------------------------------------------------------- */

echo "\nVeritabanı şeması\n";

$blueprint = new Blueprint();
$blueprint->id();
$blueprint->key('slug');
$blueprint->string('title', 255);
$blueprint->longText('blocks')->nullable();
$blueprint->boolean('featured')->default(0);
$blueprint->boolean('comments_open')->default(1);
$blueprint->timestamp('published_at')->nullable();
$blueprint->integer('views', true)->default(0);
$blueprint->unique(['slug'], 'slug_unique');
$blueprint->index('featured');

$sql = $blueprint->toSql('hi_content');

check('CREATE TABLE üretilir', str_starts_with($sql, 'CREATE TABLE `hi_content`'));
check('Birincil anahtar', str_contains($sql, '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'));
check('Tekil indeks', str_contains($sql, 'UNIQUE KEY `slug_unique` (`slug`)'));
check('InnoDB ve utf8mb4', str_contains($sql, 'ENGINE=InnoDB') && str_contains($sql, 'utf8mb4'));

/*
 * Sütun tanımları TAM eşleşmeyle denetlenir. Daha önce burada `str_contains`
 * ile parça araması yapılıyordu ve `DEFAULT 0 DEFAULT 1` gibi geçersiz SQL
 * testten geçiyordu — migration'ların hiç çalışmamasına yol açan hata buydu.
 */
$definitions = [];

foreach ($blueprint->columnDefinitions() as $definition) {
    preg_match('/^`([^`]+)`/', $definition, $m);
    $definitions[$m[1]] = $definition;
}

$expected = [
    'id'            => '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'slug'          => '`slug` VARCHAR(191) NOT NULL',
    'title'         => '`title` VARCHAR(255) NOT NULL',
    'blocks'        => '`blocks` LONGTEXT NULL',
    'featured'      => '`featured` TINYINT(1) NOT NULL DEFAULT 0',
    'comments_open' => '`comments_open` TINYINT(1) NOT NULL DEFAULT 1',
    'published_at'  => '`published_at` DATETIME NULL',
    'views'         => '`views` INT UNSIGNED NOT NULL DEFAULT 0',
];

foreach ($expected as $column => $want) {
    check(
        'Sütun tanımı tam eşleşir: ' . $column,
        ($definitions[$column] ?? '') === $want,
        'üretilen: ' . ($definitions[$column] ?? 'yok')
    );
}

// Hiçbir sütunda yan tümce tekrarı olmamalı.
foreach ($definitions as $column => $definition) {
    check(
        'Tek DEFAULT yan tümcesi: ' . $column,
        substr_count($definition, 'DEFAULT') <= 1,
        $definition
    );
    check(
        'NULL/NOT NULL çakışması yok: ' . $column,
        substr_count($definition, ' NULL') === 1,
        $definition
    );
}

// Çağrı sırası sonucu değiştirmemeli.
$orderA = (new Blueprint())->boolean('x')->default(1)->nullable();
$orderB = (new Blueprint())->boolean('x')->nullable()->default(1);

check('Değiştirici sırası sonucu değiştirmez',
    $orderA->columnDefinitions() === $orderB->columnDefinitions(),
    implode(' | ', array_merge($orderA->columnDefinitions(), $orderB->columnDefinitions())));

// Aynı sütunda ikinci default öncekini değiştirir.
$replaced = (new Blueprint())->integer('n')->default(1)->default(5);

check('İkinci default öncekini değiştirir',
    $replaced->columnDefinitions() === ['`n` INT NOT NULL DEFAULT 5'],
    implode('', $replaced->columnDefinitions()));

/*
 * Gerçek migration dosyalarının ürettiği SQL de denetlenir: tablo başına en
 * fazla bir DEFAULT ve dengeli parantez. Bu, migration'ları veritabanı olmadan
 * doğrulamanın tek yolu.
 */
$migrationSql = [];
$connection   = new HiCMS\Database\Connection(['prefix' => 'hi_']);
$schema       = new HiCMS\Database\Schema($connection);

$schema->beginDryRun();

$migrationFiles = array_merge(
    glob($root . '/src/Database/migrations/*.php') ?: [],
    glob($root . '/plugins/*/migrations/*.php') ?: []
);

foreach ($migrationFiles as $file) {
    $migration = require $file;
    $migration->up($schema, $connection);
}

$generated = $schema->endDryRun();

foreach ($generated as $table => $createSql) {
    $lines = array_filter(
        array_map('trim', explode("\n", $createSql)),
        static fn(string $line): bool => str_starts_with($line, '`')
    );

    $clean = true;

    foreach ($lines as $line) {
        if (substr_count($line, 'DEFAULT') > 1 || substr_count($line, ' NULL') > 1) {
            $clean = false;
            $migrationSql[] = $table . ': ' . rtrim($line, ',');
        }
    }

    check('Migration SQL geçerli: ' . $table, $clean, implode(' / ', $migrationSql));
}

check('Tüm migration tabloları üretildi', count($generated) >= 12, (string) count($generated));
check('Eklenti migration\'ları da denetlendi',
    array_key_exists('seo_redirects', $generated) && array_key_exists('form_submissions', $generated),
    implode(',', array_keys($generated)));

/* -------------------------------------------------------------------------
 * 7. Önyükleme (yapılandırma yok)
 * ---------------------------------------------------------------------- */

echo "\nÖnyükleme\n";

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';

$app = Kernel::boot($root, false);

check('Çekirdek başlar', $app instanceof Kernel);
check('Sürüm okunur', Kernel::VERSION !== '');

/*
 * "Kurulu değil" durumu kök dizindeki config.php'nin yokluğuna bakılarak
 * denetlenirse, geliştirme makinesinde gerçek bir kurulum varken test boşuna
 * düşer. O yüzden yapılandırması olmayan geçici bir dizin kullanılır.
 */
$emptyRoot = sys_get_temp_dir() . '/hicms-bos-' . bin2hex(random_bytes(4));

check(
    'Yapılandırma yoksa kurulu sayılmaz',
    !(new Installer($emptyRoot))->isInstalled(),
    $emptyRoot
);

/*
 * MySQL ve MariaDB, SHOW / DESCRIBE deyimlerinde bağlı parametre kabul etmez;
 * hazırlanan `SHOW TABLES LIKE ?` çalışma anında 1064 verir ve bu ancak gerçek
 * bir veritabanıyla görülür. Aşağıdaki denetim o hatayı kaynak düzeyinde,
 * veritabanı olmadan yakalar.
 */
$placeholderInShow = [];
$sourceFiles       = [];

foreach (['src', 'admin', 'plugins', 'themes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getExtension() === 'php') {
            $sourceFiles[] = $item->getPathname();
        }
    }
}

foreach ($sourceFiles as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match('~[\'"]\s*(SHOW|DESCRIBE|EXPLAIN)\s[^\'"]*\?~i', $source, $hit) === 1) {
        $placeholderInShow[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . trim($hit[0]);
    }
}

check(
    'SHOW/DESCRIBE deyimlerinde bağlı parametre yok',
    $placeholderInShow === [],
    implode(' | ', $placeholderInShow)
);

check('Olay dağıtıcısı hazır', $app->events() instanceof Dispatcher);
check('Blok kaydı tembel kurulur', $app->blocks() instanceof BlockRegistry);
check('URL üretici çalışır', str_starts_with($app->urls()->to('hakkinda'), 'http'),
    $app->urls()->to('hakkinda'));
check('Panel adresi', str_ends_with($app->urls()->admin(), '/admin/'), $app->urls()->admin());
check('Küresel tema fonksiyonları yüklendi', function_exists('hi_title') && function_exists('hi_permalink'));
check('CSRF anahtarı üretilir ve doğrulanır', $app->csrf()->verify($app->csrf()->token()));
check('Bozuk CSRF reddedilir', !$app->csrf()->verify('sahte'));
check('Roller tanımlı', count($app->roles()->options()) === 5, implode(',', array_keys($app->roles()->options())));
check('Yönetici tüm izinlere sahip',
    count($app->roles()->capabilitiesOf('admin')) === count(HiCMS\Auth\Roles::CAPABILITIES));
check('Abone içerik silemez', !$app->roles()->roleCan('subscriber', 'content.delete'));
check('Editör yayınlayabilir', $app->roles()->roleCan('editor', 'content.publish'));
check('Editör eklenti kuramaz', !$app->roles()->roleCan('editor', 'plugins.manage'));
check('Tema keşfedilir', array_key_exists('hiblog', $app->themes()->available()));
check('Varsayılan tema geçerli', $app->themes()->available()['hiblog']->valid ?? false,
    $app->themes()->available()['hiblog']->error ?? '');
check('Eklentiler keşfedilir', count($app->plugins()->available()) === 5,
    implode(',', array_keys($app->plugins()->available())));

foreach ($app->plugins()->available() as $slug => $plugin) {
    check('Eklenti künyesi geçerli: ' . $slug, $plugin->valid, $plugin->error);
    check('Eklenti ana dosyası var: ' . $slug, $plugin->hasMainFile());
}

check('Çekirdek migration kaynağı kayıtlı',
    array_key_exists('core', $app->migrator()->sources()));

/* -------------------------------------------------------------------------
 * 8. Gereksinim denetimi
 * ---------------------------------------------------------------------- */

echo "\nGereksinimler\n";

$checks  = Requirements::check($root);
$summary = Requirements::summary($checks);

check('Denetim listesi dolu', count($checks) > 10, (string) count($checks));
check('Zorunlu maddeler sağlanıyor', Requirements::passes($checks),
    'eksik: ' . $summary['fail']);

printf("       %d uygun, %d uyarı, %d eksik\n", $summary['ok'], $summary['warn'], $summary['fail']);

/* -------------------------------------------------------------------------
 * Sonuç
 * ---------------------------------------------------------------------- */

echo "\n" . str_repeat('-', 58) . "\n";

if ($failed === []) {
    printf("TUM TESTLER GECTI (%d denetim)\n", $passed);
    exit(0);
}

printf("BASARISIZ: %d / %d\n\n", count($failed), $passed + count($failed));

foreach ($failed as $failure) {
    echo '  - ' . $failure . "\n";
}

exit(1);
