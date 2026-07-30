<?php

declare(strict_types=1);

namespace HiCMS\Update;

use HiCMS\Database\Migrator;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Update\Completed;
use HiCMS\Repository\OptionRepository;
use HiCMS\Support\Archive;
use HiCMS\Support\Fs;
use HiCMS\Support\Remote;
use HiCMS\Support\Str;
use Throwable;

/**
 * Çekirdek güncelleyici.
 *
 * **Aynı ZIP hem kurulum hem güncelleme için kullanılır.** Kurulumda arşiv
 * boş bir klasöre açılır; güncellemede ise yalnızca çekirdeğe ait yollar
 * değiştirilir:
 *
 *   Değiştirilenler : src/ admin/ index.php install.php router.php hi-cron.php
 *                     hicms.json .htaccess LICENSE README.md
 *   Korunanlar      : config.php content/ themes/ plugins/
 *
 * Sıra: yedek al → dosyaları değiştir → migration çalıştır → sürümü yaz.
 * Herhangi bir adım başarısız olursa dosyalar yedekten geri yüklenir.
 */
final class Updater
{
    /** Güncellemede yeniden yazılan yollar. */
    private const CORE_PATHS = [
        'src', 'admin', 'index.php', 'install.php', 'router.php',
        'hi-cron.php', 'hicms.json', 'LICENSE', 'README.md',
    ];

    /** Asla dokunulmayan yollar. */
    private const PRESERVED = ['config.php', 'content', 'themes', 'plugins'];

    public function __construct(
        private readonly string $rootDir,
        private readonly string $tmpDir,
        private readonly Backup $backup,
        private readonly Migrator $migrator,
        private readonly UpdateChecker $checker,
        private readonly OptionRepository $options,
        private readonly Dispatcher $events,
        private readonly string $repository,
        private readonly string $currentVersion,
    ) {
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    public function repository(): string
    {
        return $this->repository;
    }

    /**
     * Yeni sürüm var mı?
     *
     * @return array{available: bool, version: string, zip: string, notes: string, url: string, error: string}
     */
    public function check(bool $force = false): array
    {
        return $this->checker->check($this->repository, $this->currentVersion, $force);
    }

    /**
     * Uzak sürümü indirip kurar.
     *
     * @return array{ok: bool, error: string, from: string, to: string, migrations: list<string>}
     */
    public function updateFromRemote(): array
    {
        $check = $this->check(true);

        if ($check['error'] !== '') {
            return $this->failure($check['error']);
        }

        if (!$check['available']) {
            return $this->failure('Zaten en son sürümü kullanıyorsunuz.');
        }

        if ($check['zip'] === '') {
            return $this->failure('Yayında indirilebilir bir paket bulunamadı.');
        }

        if (!Fs::ensureDir($this->tmpDir)) {
            return $this->failure('Geçici klasör oluşturulamadı.');
        }

        $download = $this->tmpDir . '/core-' . $check['version'] . '.zip';
        $result   = Remote::download($check['zip'], $download);

        if (!$result['ok']) {
            return $this->failure('İndirme başarısız: ' . $result['error']);
        }

        $install = $this->updateFromZip($download);

        @unlink($download);

        return $install;
    }

    /**
     * Yerel ZIP dosyasından güncelleme.
     *
     * @return array{ok: bool, error: string, from: string, to: string, migrations: list<string>}
     */
    public function updateFromZip(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            return $this->failure('Güncelleme paketi bulunamadı.');
        }

        if (!Fs::ensureDir($this->tmpDir)) {
            return $this->failure('Geçici klasör oluşturulamadı.');
        }

        $workDir = $this->tmpDir . '/core-' . substr(Str::random(4), 0, 8);

        $extracted = Archive::extract($zipPath, $workDir);

        if (!$extracted['ok']) {
            Fs::deleteDir($workDir);

            return $this->failure((string) ($extracted['error'] ?? 'Arşiv açılamadı.'));
        }

        $sourceDir = $this->findCoreRoot($workDir);

        if ($sourceDir === null) {
            Fs::deleteDir($workDir);

            return $this->failure('Arşiv bir HiCMS çekirdek paketi değil (hicms.json + src/ bulunamadı).');
        }

        $manifest = json_decode((string) @file_get_contents($sourceDir . '/hicms.json'), true);
        $newVersion = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';

        if ($newVersion === '') {
            Fs::deleteDir($workDir);

            return $this->failure('Paketin sürüm numarası okunamadı.');
        }

        if (version_compare($newVersion, $this->currentVersion, '<')) {
            Fs::deleteDir($workDir);

            return $this->failure(Str::format(
                'Paket sürümü (%s) kurulu sürümden (%s) eski. Sürüm düşürme desteklenmiyor.',
                $newVersion,
                $this->currentVersion
            ));
        }

        // Yazma izni denetimi — yarı yolda kalmamak için önceden bak.
        foreach (self::CORE_PATHS as $path) {
            $target = $this->rootDir . '/' . $path;

            if (file_exists($target) && !is_writable($target)) {
                Fs::deleteDir($workDir);

                return $this->failure(Str::format('%s yazılabilir değil. Dosya izinlerini kontrol edin.', $path));
            }
        }

        // Güvenlik ağı: veritabanı yedeği (yüklemeler hariç — hızlı olsun).
        $backup = $this->backup->create('Güncelleme öncesi ' . $this->currentVersion, false);

        if (!$backup['ok']) {
            Fs::deleteDir($workDir);

            return $this->failure('Güncelleme öncesi yedek alınamadı: ' . $backup['error']);
        }

        // Dosyaları değiştir; eskisini geri dönüş için sakla.
        $rollbackDir = $this->tmpDir . '/rollback-' . substr(Str::random(3), 0, 6);
        Fs::ensureDir($rollbackDir);

        $replaced = [];

        foreach (self::CORE_PATHS as $path) {
            $source = $sourceDir . '/' . $path;

            if (!file_exists($source)) {
                continue;
            }

            $target = $this->rootDir . '/' . $path;

            if (file_exists($target)) {
                if (is_dir($target)) {
                    Fs::copyDir($target, $rollbackDir . '/' . $path);
                } else {
                    Fs::ensureDir(dirname($rollbackDir . '/' . $path));
                    @copy($target, $rollbackDir . '/' . $path);
                }
            }

            /*
             * Yol, denemeden ÖNCE geri alma listesine girer. Aksi hâlde yarı
             * yolda kalan yol listede olmadığı için geri yüklenmez ve — panelden
             * güncellemede `admin/` örneğinde olduğu gibi — yok edilmiş bir
             * dizinle baş başa kalınır.
             */
            $replaced[] = $path;

            $ok = is_dir($source)
                ? Fs::syncDir($source, $target)
                : Fs::replaceFile($source, $target);

            if (!$ok) {
                $this->rollback($rollbackDir, $replaced);
                Fs::deleteDir($workDir);
                Fs::deleteDir($rollbackDir);

                return $this->failure(Str::format('%s güncellenemedi, değişiklikler geri alındı.', $path));
            }
        }

        Fs::deleteDir($workDir);

        // Kilitli dosyalar yana alınmış olabilir; artıkları temizle.
        foreach (self::CORE_PATHS as $path) {
            Fs::sweepAside($this->rootDir . '/' . $path);
        }

        // Şema güncellemeleri.
        $migrations = [];

        try {
            $result = $this->migrator->migrate();

            if (!$result['ok']) {
                $this->rollback($rollbackDir, $replaced);
                Fs::deleteDir($rollbackDir);

                return $this->failure(
                    'Veritabanı güncellemesi başarısız: ' . $result['error']
                    . ' Dosyalar geri alındı, yedek: ' . $backup['file']
                );
            }

            $migrations = $result['applied'];
        } catch (Throwable $exception) {
            $this->rollback($rollbackDir, $replaced);
            Fs::deleteDir($rollbackDir);

            return $this->failure('Veritabanı güncellemesi hata verdi: ' . $exception->getMessage());
        }

        Fs::deleteDir($rollbackDir);

        $from = $this->currentVersion;

        $this->options->set('core_version', $newVersion);
        $this->options->set('last_update', [
            'from'   => $from,
            'to'     => $newVersion,
            'at'     => date('Y-m-d H:i:s'),
            'backup' => $backup['file'],
        ]);

        $this->checker->clearCache();

        $this->events->dispatch(new Completed('core', 'hicms', $from, $newVersion));

        return ['ok' => true, 'error' => '', 'from' => $from, 'to' => $newVersion, 'migrations' => $migrations];
    }

    /**
     * Yüklenen ZIP ile güncelleme ($_FILES girdisi).
     *
     * @param array<string, mixed> $file
     * @return array{ok: bool, error: string, from: string, to: string, migrations: list<string>}
     */
    public function updateFromUpload(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->failure('Dosya yüklenemedi.');
        }

        if (!str_ends_with(strtolower((string) ($file['name'] ?? '')), '.zip')) {
            return $this->failure('Yalnızca .zip dosyası yükleyebilirsiniz.');
        }

        if (!Fs::ensureDir($this->tmpDir)) {
            return $this->failure('Geçici klasör oluşturulamadı.');
        }

        $temp = $this->tmpDir . '/core-up-' . substr(Str::random(4), 0, 8) . '.zip';

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? @move_uploaded_file((string) $file['tmp_name'], $temp)
            : @rename((string) $file['tmp_name'], $temp);

        if (!$moved) {
            return $this->failure('Yüklenen dosya taşınamadı.');
        }

        $result = $this->updateFromZip($temp);

        @unlink($temp);

        return $result;
    }

    /**
     * Bekleyen migration olup olmadığını söyler — güncelleme sonrası uyarı için.
     */
    public function hasPendingMigrations(): bool
    {
        return $this->migrator->pending() !== [];
    }

    /**
     * @return array{from: string, to: string, at: string, backup: string}|null
     */
    public function lastUpdate(): ?array
    {
        $last = $this->options->get('last_update');

        return is_array($last) ? $last : null;
    }

    /** @return list<string> */
    public function corePaths(): array
    {
        return self::CORE_PATHS;
    }

    /** @return list<string> */
    public function preservedPaths(): array
    {
        return self::PRESERVED;
    }

    /**
     * @param list<string> $replaced
     */
    private function rollback(string $rollbackDir, array $replaced): void
    {
        foreach ($replaced as $path) {
            $source = $rollbackDir . '/' . $path;
            $target = $this->rootDir . '/' . $path;

            if (!file_exists($source)) {
                continue;
            }

            // Geri alma da eşitlemeyle yapılır: silme adımı çalışan betiğe
            // takılıp dizini boş bırakırsa geri alma da işe yaramaz.
            if (is_dir($source)) {
                Fs::syncDir($source, $target);
            } else {
                Fs::replaceFile($source, $target);
            }
        }
    }

    private function findCoreRoot(string $directory): ?string
    {
        if (is_readable($directory . '/hicms.json') && is_dir($directory . '/src')) {
            return $directory;
        }

        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            if (is_readable($child . '/hicms.json') && is_dir($child . '/src')) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, error: string, from: string, to: string, migrations: list<string>}
     */
    private function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'from' => $this->currentVersion, 'to' => '', 'migrations' => []];
    }
}
