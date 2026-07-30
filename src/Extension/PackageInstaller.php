<?php

declare(strict_types=1);

namespace HiCMS\Extension;

use HiCMS\Database\Migrator;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Update\Completed;
use HiCMS\Support\Archive;
use HiCMS\Support\Fs;
use HiCMS\Support\Remote;
use HiCMS\Support\Str;

/**
 * Tema ve eklenti paketlerini kurar/günceller.
 *
 * Akış her iki tür için aynıdır:
 *   1. ZIP geçici klasöre açılır (yol dışına çıkma denetimiyle)
 *   2. `hicms.json` okunur, uyumluluk denetlenir
 *   3. Var olan kurulum yedeklenir, yenisi yerine taşınır
 *   4. Hata olursa yedek geri yüklenir — site yarı kurulmuş paketle kalmaz
 *   5. Paketin migration'ları çalıştırılır
 */
final class PackageInstaller
{
    public function __construct(
        private readonly string $themesDir,
        private readonly string $pluginsDir,
        private readonly string $tmpDir,
        private readonly string $coreVersion,
        private readonly Dispatcher $events,
        private readonly Migrator $migrator,
    ) {
    }

    /**
     * Yerel bir ZIP dosyasından kurar.
     *
     * @return array{ok: bool, error: string, slug: string, type: string,
     *               version: string, previous: string, updated: bool}
     */
    public function installFromZip(string $zipPath, ?string $expectedType = null): array
    {
        $fail = static fn(string $error): array => [
            'ok' => false, 'error' => $error, 'slug' => '', 'type' => '',
            'version' => '', 'previous' => '', 'updated' => false,
        ];

        if (!is_file($zipPath)) {
            return $fail('ZIP dosyası bulunamadı.');
        }

        $workDir = $this->tmpDir . '/pkg-' . substr(Str::random(4), 0, 8);

        if (!Fs::ensureDir($workDir)) {
            return $fail('Geçici klasör oluşturulamadı. content/ izinlerini kontrol edin.');
        }

        $extracted = Archive::extract($zipPath, $workDir);

        if (!$extracted['ok']) {
            Fs::deleteDir($workDir);

            return $fail((string) ($extracted['error'] ?? 'Arşiv açılamadı.'));
        }

        $packageDir = $this->findPackageRoot($workDir);

        if ($packageDir === null) {
            Fs::deleteDir($workDir);

            return $fail('Arşivde hicms.json bulunamadı. Bu bir HiCMS paketi değil.');
        }

        // Türü künyeden oku; belirtilmemişse dosya varlığından tahmin et.
        $probe = Manifest::fromDirectory($packageDir, $expectedType ?? 'plugin');
        $type  = $probe->type !== '' ? $probe->type : ($expectedType ?? 'plugin');

        if (!in_array($type, ['theme', 'plugin'], true)) {
            Fs::deleteDir($workDir);

            return $fail('Paket türü tanınamadı (theme veya plugin olmalı).');
        }

        if ($expectedType !== null && $type !== $expectedType) {
            Fs::deleteDir($workDir);

            return $fail(Str::format('Bu bir %s paketi; %s beklenmişti.', $type, $expectedType));
        }

        $manifest = Manifest::fromDirectory($packageDir, $type);

        if (!$manifest->valid) {
            Fs::deleteDir($workDir);

            return $fail($manifest->error);
        }

        $compatibility = $manifest->checkCompatibility($this->coreVersion);

        if (!$compatibility['ok']) {
            Fs::deleteDir($workDir);

            return $fail($compatibility['error']);
        }

        if ($type === 'theme' && !is_readable($packageDir . '/index.php')) {
            Fs::deleteDir($workDir);

            return $fail('Temada index.php bulunamadı.');
        }

        $slug = Str::slug($manifest->slug);

        if ($slug === '') {
            Fs::deleteDir($workDir);

            return $fail('Paket kısa adı geçersiz.');
        }

        $baseDir   = $type === 'theme' ? $this->themesDir : $this->pluginsDir;
        $target    = $baseDir . '/' . $slug;
        $isUpdate  = is_dir($target);
        $previous  = '';
        $backupDir = '';

        if ($isUpdate) {
            $existing = Manifest::fromDirectory($target, $type);
            $previous = $existing->valid ? $existing->version : '';

            $backupDir = $this->tmpDir . '/bak-' . $slug . '-' . substr(Str::random(3), 0, 6);

            if (!Fs::copyDir($target, $backupDir)) {
                Fs::deleteDir($workDir);

                return $fail('Mevcut kurulum yedeklenemedi, güncelleme iptal edildi.');
            }

            if (!Fs::deleteDir($target)) {
                Fs::deleteDir($workDir);
                Fs::deleteDir($backupDir);

                return $fail('Mevcut klasör silinemedi. Dosya izinlerini kontrol edin.');
            }
        }

        if (!Fs::copyDir($packageDir, $target)) {
            // Geri al.
            if ($backupDir !== '' && is_dir($backupDir)) {
                Fs::deleteDir($target);
                Fs::copyDir($backupDir, $target);
                Fs::deleteDir($backupDir);
            }

            Fs::deleteDir($workDir);

            return $fail('Paket hedefe kopyalanamadı.');
        }

        Fs::deleteDir($workDir);

        if ($backupDir !== '') {
            Fs::deleteDir($backupDir);
        }

        // Paketin migration'larını çalıştır.
        if (is_dir($target . '/migrations')) {
            $this->migrator->addSource($type . ':' . $slug, $target . '/migrations');
            $this->migrator->migrate();
        }

        if ($isUpdate) {
            $this->events->dispatch(new Completed($type, $slug, $previous, $manifest->version));
        }

        return [
            'ok'       => true,
            'error'    => '',
            'slug'     => $slug,
            'type'     => $type,
            'version'  => $manifest->version,
            'previous' => $previous,
            'updated'  => $isUpdate,
        ];
    }

    /**
     * Uzak bir ZIP adresinden kurar.
     *
     * @return array{ok: bool, error: string, slug: string, type: string,
     *               version: string, previous: string, updated: bool}
     */
    public function installFromUrl(string $url, ?string $expectedType = null): array
    {
        if (!Fs::ensureDir($this->tmpDir)) {
            return [
                'ok' => false, 'error' => 'Geçici klasör oluşturulamadı.', 'slug' => '',
                'type' => '', 'version' => '', 'previous' => '', 'updated' => false,
            ];
        }

        $download = $this->tmpDir . '/dl-' . substr(Str::random(4), 0, 8) . '.zip';
        $result   = Remote::download($url, $download);

        if (!$result['ok']) {
            return [
                'ok' => false, 'error' => 'İndirme başarısız: ' . $result['error'], 'slug' => '',
                'type' => '', 'version' => '', 'previous' => '', 'updated' => false,
            ];
        }

        $install = $this->installFromZip($download, $expectedType);

        @unlink($download);

        return $install;
    }

    /**
     * Yüklenen dosyadan kurar ($_FILES girdisi).
     *
     * @param array<string, mixed> $file
     * @return array{ok: bool, error: string, slug: string, type: string,
     *               version: string, previous: string, updated: bool}
     */
    public function installFromUpload(array $file, ?string $expectedType = null): array
    {
        $blank = [
            'ok' => false, 'error' => '', 'slug' => '', 'type' => '',
            'version' => '', 'previous' => '', 'updated' => false,
        ];

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'Dosya yüklenemedi.'] + $blank;
        }

        $name = strtolower((string) ($file['name'] ?? ''));

        if (!str_ends_with($name, '.zip')) {
            return ['error' => 'Yalnızca .zip dosyası yükleyebilirsiniz.'] + $blank;
        }

        if (!Fs::ensureDir($this->tmpDir)) {
            return ['error' => 'Geçici klasör oluşturulamadı.'] + $blank;
        }

        $temp = $this->tmpDir . '/up-' . substr(Str::random(4), 0, 8) . '.zip';

        $moved = is_uploaded_file((string) $file['tmp_name'])
            ? @move_uploaded_file((string) $file['tmp_name'], $temp)
            : @rename((string) $file['tmp_name'], $temp);

        if (!$moved) {
            return ['error' => 'Yüklenen dosya taşınamadı.'] + $blank;
        }

        $install = $this->installFromZip($temp, $expectedType);

        @unlink($temp);

        return $install;
    }

    /**
     * Açılmış klasörde `hicms.json` içeren dizini bulur.
     * GitHub kaynak arşivleri tek bir sarmalayıcı klasör içerir.
     */
    private function findPackageRoot(string $directory): ?string
    {
        if (is_readable($directory . '/hicms.json')) {
            return $directory;
        }

        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            if (is_readable($child . '/hicms.json')) {
                return $child;
            }
        }

        return null;
    }
}
