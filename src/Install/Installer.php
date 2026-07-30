<?php

declare(strict_types=1);

namespace HiCMS\Install;

use HiCMS\Config;
use HiCMS\Database\Connection;
use HiCMS\Kernel;
use HiCMS\Model\Entry;
use HiCMS\Model\Term;
use HiCMS\Model\User;
use HiCMS\Support\Dates;
use HiCMS\Support\Fs;
use HiCMS\Support\Str;
use Throwable;

/**
 * Kurulum sihirbazının iş katmanı.
 *
 * Sıra: gereksinimler → veritabanı bağlantısı → config.php → migration'lar →
 * yönetici hesabı → varsayılan içerik. Her adım geri bildirim döndürür; arayüz
 * yalnızca bunları gösterir.
 */
final class Installer
{
    public function __construct(private readonly string $rootDir)
    {
    }

    public function isInstalled(): bool
    {
        if (!is_readable($this->rootDir . '/config.php')) {
            return false;
        }

        $config = Config::fromFile($this->rootDir . '/config.php');

        if ((string) $config->get('db.name', '') === '') {
            return false;
        }

        try {
            return (new Connection((array) $config->get('db', [])))->tableExists('users');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Veritabanı bağlantısını sınar; istenirse veritabanını oluşturur.
     *
     * @param array<string, mixed> $db
     * @return array{ok: bool, error: string, version: string, created: bool}
     */
    public function testDatabase(array $db, bool $createIfMissing = false): array
    {
        $test = Connection::test($db);

        if ($test['ok']) {
            return ['ok' => true, 'error' => '', 'version' => $test['version'], 'created' => false];
        }

        // Veritabanı yoksa oluşturmayı dene (kullanıcının yetkisi varsa).
        if ($createIfMissing) {
            $created = Connection::createDatabase($db);

            if ($created['ok']) {
                $retry = Connection::test($db);

                if ($retry['ok']) {
                    return ['ok' => true, 'error' => '', 'version' => $retry['version'], 'created' => true];
                }

                return ['ok' => false, 'error' => $retry['error'], 'version' => '', 'created' => true];
            }

            return [
                'ok'      => false,
                'error'   => $test['error'] . ' — Oluşturma da denendi: ' . $created['error'],
                'version' => '',
                'created' => false,
            ];
        }

        return ['ok' => false, 'error' => $test['error'], 'version' => '', 'created' => false];
    }

    /**
     * Kurulumu tamamlar.
     *
     * @param array{
     *   db: array<string, mixed>,
     *   url: string, locale: string, timezone: string,
     *   site: array{title: string, tagline: string, description: string},
     *   admin: array{username: string, email: string, password: string, name: string},
     *   demo: bool
     * } $input
     * @return array{ok: bool, error: string, steps: list<string>, adminUrl: string}
     */
    public function install(array $input): array
    {
        $steps = [];

        $fail = static fn(string $error, array $steps): array => [
            'ok' => false, 'error' => $error, 'steps' => $steps, 'adminUrl' => '',
        ];

        // 1. Veritabanı
        $test = $this->testDatabase($input['db'], true);

        if (!$test['ok']) {
            return $fail('Veritabanına bağlanılamadı: ' . $test['error'], $steps);
        }

        $steps[] = $test['created']
            ? 'Veritabanı oluşturuldu (MySQL ' . $test['version'] . ')'
            : 'Veritabanı bağlantısı doğrulandı (MySQL ' . $test['version'] . ')';

        // 2. Yönetici bilgileri doğrulaması — config yazmadan önce.
        $admin = $input['admin'];

        if (trim($admin['username']) === '' || preg_match('/^[a-z0-9_.-]{3,32}$/i', $admin['username']) !== 1) {
            return $fail('Kullanıcı adı 3–32 karakter olmalı, yalnızca harf, sayı, nokta, tire ve alt çizgi.', $steps);
        }

        if (filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
            return $fail('Geçerli bir e-posta adresi girin.', $steps);
        }

        if (strlen($admin['password']) < 10) {
            return $fail('Şifre en az 10 karakter olmalıdır.', $steps);
        }

        // 3. Klasörler
        foreach (['content', 'content/uploads', 'content/backups', 'content/cache', 'content/tmp'] as $relative) {
            if (!Fs::ensureDir($this->rootDir . '/' . $relative)) {
                return $fail($relative . ' klasörü oluşturulamadı. Dosya izinlerini kontrol edin.', $steps);
            }
        }

        Fs::protect($this->rootDir . '/content/backups');
        Fs::protect($this->rootDir . '/content/tmp');
        Fs::protect($this->rootDir . '/content/cache');

        $steps[] = 'İçerik klasörleri hazırlandı';

        // 4. config.php
        $config = new Config([
            'db' => [
                'host'    => (string) ($input['db']['host'] ?? 'localhost'),
                'port'    => (int) ($input['db']['port'] ?? 3306),
                'name'    => (string) ($input['db']['name'] ?? ''),
                'user'    => (string) ($input['db']['user'] ?? ''),
                'pass'    => (string) ($input['db']['pass'] ?? ''),
                'charset' => 'utf8mb4',
                'prefix'  => (string) ($input['db']['prefix'] ?? 'hi_'),
            ],
            'url'        => rtrim((string) $input['url'], '/'),
            'locale'     => (string) ($input['locale'] ?? 'tr_TR'),
            'timezone'   => (string) ($input['timezone'] ?? 'Europe/Istanbul'),
            'debug'      => false,
            'repository' => 'rasidinnbugda/HiCMS',
            'keys'       => [
                'app'    => Str::random(32),
                'cookie' => Str::random(32),
            ],
            /*
             * Boş yazılır ama YAZILIR: kullanıcı config.php'yi açtığında bu
             * ayarın var olduğunu görsün. Boş liste "hiçbir vekil başlığına
             * güvenme" demek; ters vekil arkasındaki kurulumlar burayı doldurur.
             */
            'trusted_proxies' => [],
        ]);

        if (!$config->writeTo($this->rootDir . '/config.php')) {
            return $fail(
                'config.php yazılamadı. Kök dizinin yazılabilir olduğundan emin olun '
                . 'veya dosyayı elle oluşturun.',
                $steps
            );
        }

        $steps[] = 'config.php oluşturuldu';

        /*
         * Bu noktadan sonrası veritabanına yazar. Buradaki her hata — bağlantı
         * kopması, yetki eksiği, geçersiz şema — kullanıcıya açık bir mesaj
         * olarak dönmeli. Yakalanmayan bir istisna boş bir 500 sayfası üretir ve
         * kullanıcı neyin bozulduğunu göremez; o yüzden tamamı sarmalanmıştır.
         */
        try {
            // 5. Çekirdeği yeni yapılandırmayla başlat
            Kernel::reset();
            $app = Kernel::boot($this->rootDir, false);

            // 6. Migration'lar
            $migration = $app->migrator()->migrate();

            if (!$migration['ok']) {
                return $fail('Veritabanı tabloları oluşturulamadı: ' . $migration['error'], $steps);
            }

            $steps[] = count($migration['applied']) . ' veritabanı adımı uygulandı';

            // 7. Yönetici hesabı
            $user              = new User();
            $user->username    = strtolower(trim($admin['username']));
            $user->email       = strtolower(trim($admin['email']));
            $user->displayName = trim($admin['name']) !== '' ? trim($admin['name']) : $user->username;
            $user->role        = 'admin';
            $user->status      = 'active';

            $created = $app->users()->create($user, $admin['password']);

            if (!$created['ok']) {
                return $fail('Yönetici hesabı oluşturulamadı: ' . $created['error'], $steps);
            }

            $steps[] = 'Yönetici hesabı oluşturuldu';

            // 8. Ayarlar ve varsayılan içerik
            $app->loadExtensions();

            $this->seedOptions($app, $input);
            $steps[] = 'Site ayarları yazıldı';

            if (!empty($input['demo'])) {
                $this->seedContent($app, $user);
                $steps[] = 'Örnek içerik eklendi';
            } else {
                $this->seedMinimal($app, $user);
                $steps[] = 'Temel sayfalar eklendi';
            }

            $this->seedSchedule($app);
            $steps[] = 'Planlı görevler kaydedildi';

            $app->audit()->record(
                action: 'system.install',
                userId: $user->id,
                actor: $user->displayName,
                summary: 'HiCMS ' . Kernel::VERSION . ' kuruldu',
            );
        } catch (Throwable $exception) {
            return $fail(
                sprintf(
                    '%s — %s (%s satır %d)',
                    $this->stageLabel($steps),
                    $exception->getMessage(),
                    basename($exception->getFile()),
                    $exception->getLine()
                ),
                $steps
            );
        }

        return ['ok' => true, 'error' => '', 'steps' => $steps, 'adminUrl' => $app->urls()->admin()];
    }

    /**
     * Hangi aşamada kaldığımızı mesajın başına yazar — kullanıcı nereye
     * bakacağını bilsin.
     *
     * @param list<string> $steps
     */
    private function stageLabel(array $steps): string
    {
        $last = end($steps);

        return match (true) {
            $last === false                                => 'Kurulum başlarken hata',
            str_contains((string) $last, 'config.php')     => 'Veritabanı tabloları kurulurken hata',
            str_contains((string) $last, 'adımı')          => 'Yönetici hesabı oluşturulurken hata',
            str_contains((string) $last, 'Yönetici')       => 'Site ayarları yazılırken hata',
            str_contains((string) $last, 'ayarları')       => 'Varsayılan içerik eklenirken hata',
            default                                        => 'Kurulum tamamlanırken hata',
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function seedOptions(Kernel $app, array $input): void
    {
        $site = (array) ($input['site'] ?? []);

        $app->options()->setMany([
            'site_title'          => (string) ($site['title'] ?? 'HiCMS Sitesi'),
            'site_tagline'        => (string) ($site['tagline'] ?? ''),
            'site_description'    => (string) ($site['description'] ?? ''),
            'admin_email'         => (string) ($input['admin']['email'] ?? ''),
            'core_version'        => Kernel::VERSION,
            'installed_at'        => Dates::stamp(),
            'active_theme'        => 'hiblog',
            'active_plugins'      => [],
            'posts_per_page'      => 8,
            'feed_count'          => 15,
            'permalink_structure' => 'route',
            'comments_open'       => true,
            'comment_moderation'  => true,
            'comment_depth'       => 3,
            'show_featured'       => true,
            'search_engine_index' => true,
            'default_scheme'      => 'auto',
            'upload_max_bytes'    => 16777216,
            'allow_svg'           => false,
            'log_retention_days'  => 180,
            // Kayıt başına tutulan sürüm sayısı ve çöp kutusu bekleme süresi.
            // Budama core.prune_logs görevinde yapılıyor.
            'revision_keep'       => 20,
            'trash_days'          => 30,
            'footer_note'         => 'HiCMS ile üretildi.',
            'social'              => ['x' => '', 'instagram' => '', 'linkedin' => '', 'github' => '', 'youtube' => ''],
        ]);
    }

    /**
     * Kurulumda her koşulda oluşturulan sayfalar ve menü.
     */
    private function seedMinimal(Kernel $app, User $admin): void
    {
        $about = $this->createEntry($app, [
            'type'   => 'page',
            'title'  => 'Hakkında',
            'slug'   => 'hakkinda',
            'author' => $admin->id,
            'blocks' => [
                $this->paragraph('Bu sayfayı panelden düzenleyerek kendinizi ya da kurumunuzu tanıtabilirsiniz.', true),
                $this->heading('Ne yapıyoruz?'),
                $this->paragraph('Buraya çalışma alanınızı anlatan birkaç paragraf yazın.'),
            ],
        ]);

        $contact = $this->createEntry($app, [
            'type'   => 'page',
            'title'  => 'İletişim',
            'slug'   => 'iletisim',
            'author' => $admin->id,
            'blocks' => [
                $this->paragraph('Bize aşağıdaki adresten ulaşabilirsiniz.'),
                ['type' => 'callout', 'data' => [
                    'tone'  => 'info',
                    'title' => 'E-posta',
                    'text'  => $admin->email,
                ]],
            ],
        ]);

        $privacy = $this->createEntry($app, [
            'type'   => 'page',
            'title'  => 'Gizlilik Politikası',
            'slug'   => 'gizlilik-politikasi',
            'author' => $admin->id,
            'blocks' => [
                $this->paragraph('Bu sitede yalnızca sitenin çalışması için gereken veriler işlenir.'),
                $this->heading('Çerezler'),
                $this->paragraph('Tema tercihiniz (açık/karanlık) tarayıcınızın yerel depolamasında tutulur ve sunucuya gönderilmez.'),
                $this->heading('Yorumlar'),
                $this->paragraph('Yorum bırakırken verdiğiniz ad ve e-posta adresi yalnızca yorumun yayınlanması için saklanır. E-posta adresi hiçbir koşulda yayınlanmaz.'),
            ],
        ]);

        $app->menus()->save('ana-menu', 'Ana Menü', [
            ['label' => 'Anasayfa', 'url' => '', 'type' => 'home'],
            ['label' => 'Hakkında', 'url' => 'hakkinda', 'type' => 'page', 'ref' => $about],
            ['label' => 'İletişim', 'url' => 'iletisim', 'type' => 'page', 'ref' => $contact],
        ]);

        $app->menus()->save('alt-menu', 'Alt Menü', [
            ['label' => 'Hakkında', 'url' => 'hakkinda', 'type' => 'page', 'ref' => $about],
            ['label' => 'İletişim', 'url' => 'iletisim', 'type' => 'page', 'ref' => $contact],
            ['label' => 'Gizlilik Politikası', 'url' => 'gizlilik-politikasi', 'type' => 'page', 'ref' => $privacy],
        ]);

        $app->menus()->assign(['primary' => 'ana-menu', 'footer' => 'alt-menu']);

        /*
         * Alan kimlikleri tema tarafından belirlenir; hiblog `sidebar` ve
         * `footer` kaydeder. Burada var olmayan bir kimliğe yazılırsa
         * bileşenler ne panelde ne sitede görünür.
         */
        $areas  = array_keys($app->widgets()->areas());
        $target = $app->widgets()->hasArea('sidebar') ? 'sidebar' : (string) ($areas[0] ?? 'sidebar');

        $app->options()->set('widgets', [
            $target => [
                ['type' => 'search', 'title' => 'Arama', 'settings' => ['placeholder' => 'Yazılarda ara…']],
                ['type' => 'recent', 'title' => 'Son Yazılar', 'settings' => ['count' => 4, 'numbered' => true]],
                ['type' => 'terms', 'title' => 'Kategoriler', 'settings' => ['taxonomy' => 'category', 'show_count' => true]],
            ],
        ]);
    }

    /**
     * Örnek içerik: kategoriler ve tanıtım yazıları.
     */
    private function seedContent(Kernel $app, User $admin): void
    {
        $this->seedMinimal($app, $admin);

        $categories = [
            ['name' => 'Yazılım', 'slug' => 'yazilim', 'color' => '#95389e',
             'description' => 'Kod yazma pratikleri ve mimari kararlar.'],
            ['name' => 'Tasarım', 'slug' => 'tasarim', 'color' => '#2563eb',
             'description' => 'Arayüz tasarımı, tipografi ve görsel dil.'],
            ['name' => 'Rehber', 'slug' => 'rehber', 'color' => '#0d9488',
             'description' => 'Adım adım anlatımlar.'],
        ];

        $termIds = [];

        foreach ($categories as $index => $definition) {
            $term              = new Term();
            $term->taxonomy    = 'category';
            $term->name        = $definition['name'];
            $term->slug        = $definition['slug'];
            $term->color       = $definition['color'];
            $term->description = $definition['description'];
            $term->position    = $index;

            $termIds[$definition['slug']] = $app->terms()->create($term);
        }

        foreach (['hicms', 'baslangic', 'tema'] as $tag) {
            $app->terms()->findOrCreateByName('tag', ucfirst($tag));
        }

        $this->createEntry($app, [
            'type'     => 'post',
            'title'    => 'HiCMS kuruldu: buradan nasıl devam edilir?',
            'slug'     => 'hicms-kuruldu-nasil-devam-edilir',
            'author'   => $admin->id,
            'featured' => true,
            'excerpt'  => 'Kurulum tamamlandı. Sıradaki üç adım: site kimliğini ayarlamak, ilk yazıyı yazmak ve temayı kendinize göre biçimlendirmek.',
            'terms'    => ['category' => [$termIds['rehber'] ?? 0]],
            'blocks'   => [
                $this->paragraph('Kurulum tamam. Bu yazı, siteyi yayına hazırlarken izleyebileceğiniz sırayı anlatıyor — okuduktan sonra silebilirsiniz.', true),
                $this->heading('1. Site kimliğini ayarlayın'),
                $this->paragraph('Panelde <strong>Ayarlar → Genel</strong> bölümünden site başlığını, sloganı ve açıklamayı düzenleyin. Bu üç alan hem tarayıcı sekmesinde hem arama sonuçlarında görünür.'),
                $this->heading('2. İlk yazınızı yazın'),
                $this->paragraph('İçerik blok blok kurulur: paragraf, başlık, görsel, alıntı, kod, galeri. Blokları sürükleyerek sıralayabilirsiniz. Böylece içerik yapısı korunur ve tema değiştirdiğinizde düzen bozulmaz.'),
                ['type' => 'callout', 'data' => [
                    'tone'  => 'info',
                    'title' => 'Blok tabanlı içerik ne kazandırır?',
                    'text'  => 'İçerik HTML yığını olarak değil, yapılandırılmış veri olarak saklanır. Aynı içeriği ileride farklı bir tasarımla ya da JSON API üzerinden sunmak mümkün olur.',
                ]],
                $this->heading('3. Temayı biçimlendirin'),
                $this->paragraph('<strong>Görünüm → Menüler</strong> ve <strong>Bileşenler</strong> bölümlerinden gezinti ve yan sütunu düzenleyin. Tema değiştirmek isterseniz <strong>Görünüm → Temalar</strong>.'),
                ['type' => 'divider', 'data' => ['style' => 'dots']],
                $this->paragraph('Sorularınız için dokümantasyona göz atabilirsiniz. İyi yayınlar!'),
            ],
        ]);

        $this->createEntry($app, [
            'type'    => 'post',
            'title'   => 'Blok editörüyle içerik kurmak',
            'slug'    => 'blok-editoruyle-icerik-kurmak',
            'author'  => $admin->id,
            'excerpt' => 'Paragraf, başlık, görsel, galeri, alıntı, kod ve bilgi kutusu. Her blok kendi verisini taşır; tema onu kendi tasarımıyla basar.',
            'terms'   => ['category' => [$termIds['tasarim'] ?? 0]],
            'blocks'  => [
                $this->paragraph('Bu yazı yerleşik blokların nasıl göründüğünü tek sayfada gösteriyor.', true),
                $this->heading('Alıntı'),
                ['type' => 'quote', 'data' => [
                    'text' => 'İyi bir içerik yönetim sistemi, kendisinden başkasının genişletebildiği ölçüde iyidir.',
                    'cite' => 'HiCMS tasarım notları',
                ]],
                $this->heading('Liste'),
                ['type' => 'list', 'data' => [
                    'style' => 'bullet',
                    'items' => [
                        'Bloklar sıralı bir dizi olarak saklanır',
                        'Her blok türü kendi alanlarını tanımlar',
                        'Panel formu bu tanımdan otomatik üretilir',
                    ],
                ]],
                $this->heading('Kod'),
                ['type' => 'code', 'data' => [
                    'language' => 'php',
                    'code'     => "hi_register_block('fiyat-tablosu', [\n    'label'  => 'Fiyat Tablosu',\n    'fields' => [/* … */],\n    'render' => fn(array \$data, \$r) => '…',\n]);",
                ]],
                $this->heading('Bilgi kutusu'),
                ['type' => 'callout', 'data' => [
                    'tone'  => 'warning',
                    'title' => 'Not',
                    'text'  => 'Özel HTML bloğu güvenli etiket kümesine indirgenir; betik ve olay öznitelikleri temizlenir.',
                ]],
            ],
        ]);

        $this->createEntry($app, [
            'type'    => 'post',
            'title'   => 'Tema geliştirmeye başlamak',
            'slug'    => 'tema-gelistirmeye-baslamak',
            'author'  => $admin->id,
            'excerpt' => 'Bir temanın çalışması için gereken en az iki dosya: hicms.json ve index.php. Geri kalanı şablon hiyerarşisi.',
            'terms'   => ['category' => [$termIds['yazilim'] ?? 0]],
            'blocks'  => [
                $this->paragraph('Tema geliştirmek için build adımı, derleyici ya da paket yöneticisi gerekmez: PHP dosyaları ve bir CSS dosyası yeterlidir.', true),
                $this->heading('Şablon hiyerarşisi'),
                ['type' => 'list', 'data' => [
                    'style' => 'bullet',
                    'items' => [
                        'Tek yazı: <code>single-post.php</code> → <code>single.php</code> → <code>index.php</code>',
                        'Sayfa: <code>page-{kisa-ad}.php</code> → <code>page.php</code> → <code>index.php</code>',
                        'Kategori: <code>taxonomy-category.php</code> → <code>taxonomy.php</code> → <code>archive.php</code>',
                    ],
                ]],
                $this->heading('Döngü'),
                ['type' => 'code', 'data' => [
                    'language' => 'php',
                    'code'     => "<?php while (hi_has_next()) : hi_the_entry(); ?>\n    <article <?= hi_entry_class() ?>>\n        <h2><a href=\"<?= hi_permalink() ?>\"><?= hi_title() ?></a></h2>\n        <p><?= hi_excerpt() ?></p>\n    </article>\n<?php endwhile; ?>",
                ]],
            ],
        ]);
    }

    private function seedSchedule(Kernel $app): void
    {
        $app->scheduler()->every('core.publish_scheduled', '5 minutes');
        $app->scheduler()->every('core.check_updates', '12 hours');
        $app->scheduler()->every('core.prune_logs', '1 day');
        $app->scheduler()->every('core.clean_tmp', '1 day');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createEntry(Kernel $app, array $data): int
    {
        $entry              = new Entry();
        $entry->type        = (string) ($data['type'] ?? 'post');
        $entry->title       = (string) ($data['title'] ?? '');
        $entry->slug        = (string) ($data['slug'] ?? '');
        $entry->excerpt     = (string) ($data['excerpt'] ?? '');
        $entry->blocks      = (array) ($data['blocks'] ?? []);
        $entry->authorId    = (int) ($data['author'] ?? 0);
        $entry->status      = 'published';
        $entry->featured    = (bool) ($data['featured'] ?? false);
        $entry->publishedAt = Dates::stamp();

        $result = $app->content()->save($entry, [], (array) ($data['terms'] ?? []));

        return (int) $result['id'];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function paragraph(string $text, bool $lead = false): array
    {
        return ['type' => 'paragraph', 'data' => ['text' => $text, 'lead' => $lead]];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function heading(string $text, string $level = 'h2'): array
    {
        return ['type' => 'heading', 'data' => ['text' => $text, 'level' => $level]];
    }
}
