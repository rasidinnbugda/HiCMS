<?php

declare(strict_types=1);

namespace HiCMS;

use HiCMS\Auth\Auth;
use HiCMS\Auth\Roles;
use HiCMS\Content\BlockRegistry;
use HiCMS\Content\BlockRenderer;
use HiCMS\Content\Permalinks;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Connection;
use HiCMS\Database\Migrator;
use HiCMS\Database\Schema;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\System\Booted;
use HiCMS\Extension\PackageInstaller;
use HiCMS\Http\Csrf;
use HiCMS\Http\Request;
use HiCMS\Http\Router;
use HiCMS\Http\Url;
use HiCMS\I18n\Translator;
use HiCMS\Media\Uploader;
use HiCMS\Plugin\PluginManager;
use HiCMS\Repository\AuditLogRepository;
use HiCMS\Repository\CommentRepository;
use HiCMS\Repository\ContentRepository;
use HiCMS\Repository\MediaRepository;
use HiCMS\Repository\OptionRepository;
use HiCMS\Repository\TermRepository;
use HiCMS\Repository\UserRepository;
use HiCMS\Scheduler\Scheduler;
use HiCMS\Theme\Assets;
use HiCMS\Theme\Menus;
use HiCMS\Theme\Templates;
use HiCMS\Theme\ThemeManager;
use HiCMS\Theme\ViewContext;
use HiCMS\Theme\Widgets;
use HiCMS\Update\Backup;
use HiCMS\Update\Updater;
use HiCMS\Update\UpdateChecker;
use RuntimeException;

/**
 * HiCMS çekirdeği.
 *
 * Tek bir giriş noktasıdır: yapılandırmayı okur, servisleri tanımlar (tembel),
 * eklentileri ve temayı yükler. Servisler istendiğinde kurulur — bir istek
 * yalnızca gerçekten kullandığı parçaların bedelini öder.
 *
 * Kullanım:
 *
 *     $app = Kernel::boot(__DIR__);
 *     $app->urls()->to('hakkinda');
 */
final class Kernel
{
    public const VERSION = '0.3.0';

    private static ?self $instance = null;

    private Container $container;

    private bool $booted = false;

    /**
     * Eklenti başına ayar tanımı. Container'da değil burada tutuluyor: anahtar
     * çalışma anında belirlenen bir kısa ad, servis adı değil.
     *
     * @var array<string, Extension\Settings>
     */
    private array $pluginSettings = [];

    /** @var array<string, string> */
    private array $paths;

    private function __construct(
        private readonly string $rootDir,
        private readonly Config $config,
    ) {
        $this->container = new Container();

        $this->paths = [
            'root'      => $this->rootDir,
            'src'       => $this->rootDir . '/src',
            'admin'     => $this->rootDir . '/admin',
            'themes'    => $this->rootDir . '/themes',
            'plugins'   => $this->rootDir . '/plugins',
            'content'   => $this->rootDir . '/content',
            'uploads'   => $this->rootDir . '/content/uploads',
            'cache'     => $this->rootDir . '/content/cache',
            'backups'   => $this->rootDir . '/content/backups',
            'tmp'       => $this->rootDir . '/content/tmp',
            'languages' => $this->rootDir . '/src/languages',
        ];
    }

    /* ---------------------------------------------------------------------
     * Önyükleme
     * ------------------------------------------------------------------ */

    public static function boot(string $rootDir, bool $loadExtensions = true): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        require_once $rootDir . '/src/Autoloader.php';
        Autoloader::register($rootDir . '/src');

        $config = Config::fromFile($rootDir . '/config.php');

        $app = new self($rootDir, $config);
        self::$instance = $app;

        $app->configureRuntime();
        $app->registerServices();

        require_once $rootDir . '/src/functions.php';

        if ($loadExtensions && $app->isInstalled()) {
            $app->loadExtensions();
        }

        $app->booted = true;

        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Çekirdek henüz başlatılmadı. Önce Kernel::boot() çağırın.');
        }

        return self::$instance;
    }

    /**
     * Test ve kurulum akışları için örneği sıfırlar.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function configureRuntime(): void
    {
        $debug = (bool) $this->config->get('debug', false);

        error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        ini_set('display_errors', $debug ? '1' : '0');

        $timezone = (string) $this->config->get('timezone', 'Europe/Istanbul');

        if (in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        setlocale(LC_COLLATE, 'tr_TR.UTF-8', 'turkish', 'tr_TR');
    }

    /**
     * Eklentileri ve temayı yükler.
     */
    public function loadExtensions(): void
    {
        $this->blocks()->registerDefaults();
        $this->types()->registerDefaults();
        $this->widgets()->registerDefaults();

        $this->roles()->applyCustom((array) $this->options()->get('custom_roles', []));

        $this->plugins()->loadActive();
        $this->themes()->loadActive();

        $this->registerCoreJobs();

        $this->events()->dispatch(new Booted($this));
    }

    /**
     * Çekirdeğin kendi planlı görevleri.
     */
    private function registerCoreJobs(): void
    {
        $scheduler = $this->scheduler();

        $scheduler->handle('core.publish_scheduled', function (): void {
            $this->content()->publishDue();
        });

        $scheduler->handle('core.prune_logs', function (): void {
            $this->audit()->prune((int) $this->options()->get('log_retention_days', 180));
            $this->auth()->pruneAttempts();

            /*
             * Sürüm ve çöp kutusu budaması AYRI BİR GÖREV DEĞİL, buraya eklendi.
             *
             * Yeni bir `jobs` satırı yazmak migration'ın veritabanına dokunmasını
             * gerektirir; o da `build/smoke.php`'nin veritabanısız kuru
             * çalıştırmasını kırıyor (ham insert bağlanmayı dener). Mevcut görev
             * her kurulumda zaten var, dolayısıyla eski kurulumlar da budamayı
             * güncelleme sonrası kendiliğinden kazanıyor.
             */
            $this->content()->pruneRevisions((int) $this->options()->get('revision_keep', 20));
            $this->content()->purgeTrash((int) $this->options()->get('trash_days', 30));
        });

        $scheduler->handle('core.check_updates', function (): void {
            $this->updater()->check(true);
        });

        $scheduler->handle('core.clean_tmp', function (): void {
            foreach (glob($this->path('tmp') . '/*') ?: [] as $item) {
                if (@filemtime($item) < time() - 86400) {
                    is_dir($item) ? Support\Fs::deleteDir($item) : @unlink($item);
                }
            }

            // Güncellemede kilitli olduğu için yana alınan dosyalar; artık
            // serbest kalmışlarsa silinir.
            foreach (['src', 'admin'] as $directory) {
                Support\Fs::sweepAside($this->rootDir . '/' . $directory);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Servis tanımları
     * ------------------------------------------------------------------ */

    private function registerServices(): void
    {
        $c = $this->container;

        $c->set('config', $this->config);
        $c->set('kernel', $this);

        $c->singleton('events', static fn(): Dispatcher => new Dispatcher());

        $c->singleton('request', fn(): Request => Request::capture($this->baseUrlPath()));

        $c->singleton('urls', fn(Container $c): Url => new Url(
            (string) $this->config->get('url', ''),
            $c->get('request')
        ));

        $c->singleton('db', fn(): Connection => new Connection((array) $this->config->get('db', [])));

        $c->singleton('schema', fn(Container $c): Schema => new Schema($c->get('db')));

        $c->singleton('migrator', function (Container $c): Migrator {
            $migrator = new Migrator($c->get('db'), $c->get('schema'));
            $migrator->addSource('core', $this->paths['src'] . '/Database/migrations');

            return $migrator;
        });

        $c->singleton('options', fn(Container $c): OptionRepository => new OptionRepository($c->get('db')));

        $c->singleton('translator', fn(): Translator => new Translator(
            (string) $this->config->get('locale', 'tr_TR'),
            $this->paths['languages']
        ));

        $c->singleton('csrf', fn(): Csrf => new Csrf(
            (string) $this->config->get('keys.app', 'hicms-gelistirme-anahtari')
        ));

        $c->singleton('roles', static fn(): Roles => new Roles());

        $c->singleton('users', fn(Container $c): UserRepository => new UserRepository($c->get('db')));
        $c->singleton('terms', fn(Container $c): TermRepository => new TermRepository($c->get('db')));
        $c->singleton('mediaRepo', fn(Container $c): MediaRepository => new MediaRepository($c->get('db')));
        $c->singleton('audit', fn(Container $c): AuditLogRepository => new AuditLogRepository($c->get('db')));

        $c->singleton('comments', fn(Container $c): CommentRepository => new CommentRepository(
            $c->get('db'),
            $c->get('users')
        ));

        $c->singleton('types', static fn(): TypeRegistry => new TypeRegistry());
        $c->singleton('blocks', static fn(): BlockRegistry => new BlockRegistry());

        $c->singleton('content', fn(Container $c): ContentRepository => new ContentRepository(
            $c->get('db'),
            $c->get('terms'),
            $c->get('users'),
            $c->get('mediaRepo'),
            $c->get('events'),
            $c->get('types'),
            // Blok ağacını kayıt anında temizler; bkz. ContentRepository::save().
            $c->get('blocks')
        ));

        $c->singleton('links', fn(Container $c): Permalinks => new Permalinks(
            $c->get('urls'),
            $c->get('types'),
            $c->get('options')
        ));

        $c->singleton('blockRenderer', fn(Container $c): BlockRenderer => new BlockRenderer(
            $c->get('blocks'),
            $c->get('mediaRepo'),
            $c->get('urls'),
            $c->get('events')
        ));

        $c->singleton('auth', fn(Container $c): Auth => new Auth(
            $c->get('users'),
            $c->get('roles'),
            $c->get('db'),
            $c->get('events'),
            $c->get('csrf'),
            $c->get('audit'),
            $c->get('request')->isSecure(),
            $this->baseUrlPath() . '/'
        ));

        $c->singleton('uploader', fn(Container $c): Uploader => new Uploader(
            $this->paths['uploads'],
            $c->get('mediaRepo'),
            $c->get('events'),
            (int) $c->get('options')->get('upload_max_bytes', 16777216),
            (bool) $c->get('options')->get('allow_svg', false)
        ));

        $c->singleton('view', static fn(): ViewContext => new ViewContext());

        $c->singleton('router', static fn(): Router => new Router());

        $c->singleton('assets', static fn(): Assets => new Assets(self::VERSION));

        $c->singleton('menus', fn(Container $c): Menus => new Menus($c->get('options'), $c->get('urls')));

        $c->singleton('widgets', fn(Container $c): Widgets => new Widgets(
            $c->get('options'),
            $c->get('content'),
            $c->get('terms'),
            $c->get('links'),
            $c->get('urls')
        ));

        $c->singleton('themes', fn(Container $c): ThemeManager => new ThemeManager(
            $this->paths['themes'],
            $c->get('options'),
            $c->get('events'),
            $c->get('translator'),
            $c->get('types'),
            $c->get('urls'),
            $c->get('migrator'),
            self::VERSION
        ));

        $c->singleton('templates', fn(Container $c): Templates => new Templates(
            $c->get('themes'),
            $c->get('events'),
            $c->get('view')
        ));

        $c->singleton('plugins', fn(Container $c): PluginManager => new PluginManager(
            $this->paths['plugins'],
            $c->get('options'),
            $c->get('events'),
            $c->get('translator'),
            $c->get('types'),
            $c->get('migrator'),
            $this,
            self::VERSION
        ));

        $c->singleton('scheduler', fn(Container $c): Scheduler => new Scheduler($c->get('db')));

        $c->singleton('backup', fn(Container $c): Backup => new Backup(
            $c->get('db'),
            $this->paths['backups'],
            $this->paths['uploads'],
            self::VERSION
        ));

        $c->singleton('updateChecker', fn(Container $c): UpdateChecker => new UpdateChecker($c->get('options')));

        $c->singleton('updater', fn(Container $c): Updater => new Updater(
            $this->rootDir,
            $this->paths['tmp'],
            $c->get('backup'),
            $c->get('migrator'),
            $c->get('updateChecker'),
            $c->get('options'),
            $c->get('events'),
            (string) $this->config->get('repository', 'rasidinnbugda/HiCMS'),
            self::VERSION
        ));

        $c->singleton('packages', fn(Container $c): PackageInstaller => new PackageInstaller(
            $this->paths['themes'],
            $this->paths['plugins'],
            $this->paths['tmp'],
            self::VERSION,
            $c->get('events'),
            $c->get('migrator')
        ));
    }

    /* ---------------------------------------------------------------------
     * Erişimciler
     * ------------------------------------------------------------------ */

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function get(string $service): mixed
    {
        return $this->container->get($service);
    }

    public function events(): Dispatcher
    {
        return $this->container->get('events');
    }

    public function request(): Request
    {
        return $this->container->get('request');
    }

    public function urls(): Url
    {
        return $this->container->get('urls');
    }

    public function db(): Connection
    {
        return $this->container->get('db');
    }

    public function schema(): Schema
    {
        return $this->container->get('schema');
    }

    public function migrator(): Migrator
    {
        return $this->container->get('migrator');
    }

    public function options(): OptionRepository
    {
        return $this->container->get('options');
    }

    public function translator(): Translator
    {
        return $this->container->get('translator');
    }

    public function csrf(): Csrf
    {
        return $this->container->get('csrf');
    }

    public function roles(): Roles
    {
        return $this->container->get('roles');
    }

    public function auth(): Auth
    {
        return $this->container->get('auth');
    }

    public function users(): UserRepository
    {
        return $this->container->get('users');
    }

    public function content(): ContentRepository
    {
        return $this->container->get('content');
    }

    public function terms(): TermRepository
    {
        return $this->container->get('terms');
    }

    public function comments(): CommentRepository
    {
        return $this->container->get('comments');
    }

    public function mediaRepo(): MediaRepository
    {
        return $this->container->get('mediaRepo');
    }

    public function uploader(): Uploader
    {
        return $this->container->get('uploader');
    }

    public function audit(): AuditLogRepository
    {
        return $this->container->get('audit');
    }

    public function types(): TypeRegistry
    {
        return $this->container->get('types');
    }

    public function blocks(): BlockRegistry
    {
        return $this->container->get('blocks');
    }

    public function blockRenderer(): BlockRenderer
    {
        return $this->container->get('blockRenderer');
    }

    public function links(): Permalinks
    {
        return $this->container->get('links');
    }

    public function view(): ViewContext
    {
        return $this->container->get('view');
    }

    public function router(): Router
    {
        return $this->container->get('router');
    }

    public function assets(): Assets
    {
        return $this->container->get('assets');
    }

    public function menus(): Menus
    {
        return $this->container->get('menus');
    }

    public function widgets(): Widgets
    {
        return $this->container->get('widgets');
    }

    public function themes(): ThemeManager
    {
        return $this->container->get('themes');
    }

    public function templates(): Templates
    {
        return $this->container->get('templates');
    }

    public function plugins(): PluginManager
    {
        return $this->container->get('plugins');
    }

    /**
     * Bir eklentinin ayar tanımı.
     *
     * Eklenti başına tek örnek tutulur; aynı istekte iki kez çağırmak aynı
     * nesneyi verir, dolayısıyla bir yerde tanımlanan bölümler başka yerde
     * okunabilir.
     */
    public function settings(string $slug): Extension\Settings
    {
        return $this->pluginSettings[$slug] ??= new Extension\Settings($slug, $this->options());
    }

    public function scheduler(): Scheduler
    {
        return $this->container->get('scheduler');
    }

    public function backup(): Backup
    {
        return $this->container->get('backup');
    }

    public function updater(): Updater
    {
        return $this->container->get('updater');
    }

    public function updateChecker(): UpdateChecker
    {
        return $this->container->get('updateChecker');
    }

    public function packages(): PackageInstaller
    {
        return $this->container->get('packages');
    }

    /* ---------------------------------------------------------------------
     * Yol ve durum
     * ------------------------------------------------------------------ */

    public function path(string $key = 'root', string $append = ''): string
    {
        $base = $this->paths[$key] ?? $this->rootDir;

        return $append === '' ? $base : $base . '/' . ltrim($append, '/');
    }

    /** @return array<string, string> */
    public function paths(): array
    {
        return $this->paths;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Kurulum tamamlandı mı? Yapılandırma dosyası ve kullanıcı tablosu gerekir.
     */
    public function isInstalled(): bool
    {
        if (!is_readable($this->rootDir . '/config.php')) {
            return false;
        }

        if ((string) $this->config->get('db.name', '') === '') {
            return false;
        }

        try {
            return $this->db()->tableExists('users');
        } catch (\Throwable) {
            return false;
        }
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('debug', false);
    }

    /**
     * Kurulumun kök yolu — alt dizin kurulumlarında "/hicms" gibi.
     */
    public function baseUrlPath(): string
    {
        $configured = (string) $this->config->get('url', '');

        if ($configured !== '') {
            return (string) (parse_url($configured, PHP_URL_PATH) ?: '');
        }

        $script = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

        foreach (['/admin', '/install'] as $suffix) {
            if (str_ends_with($script, $suffix)) {
                $script = substr($script, 0, -strlen($suffix));
            }
        }

        return rtrim($script, '/');
    }

    /**
     * Site adı — sık kullanıldığı için kısayol.
     */
    public function siteName(): string
    {
        return (string) $this->options()->get('site_title', 'HiCMS');
    }

    public function siteTagline(): string
    {
        return (string) $this->options()->get('site_tagline', '');
    }
}
