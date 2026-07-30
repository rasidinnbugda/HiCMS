<?php

declare(strict_types=1);

/**
 * HiCMS — dağıtım paketi üretici
 *
 * **Aynı ZIP hem kurulum hem güncelleme için kullanılır.** Kurulumda boş bir
 * klasöre açılır ve `install.php` çalıştırılır; güncellemede panel yalnızca
 * çekirdek yollarını değiştirir, `config.php` / `content/` / `themes/` /
 * `plugins/` dokunulmaz.
 *
 * Kullanım (proje kökünden):
 *   php build/make-zip.php              → dist/hicms-<sürüm>.zip
 *   php build/make-zip.php --out=C:\yol → hedef klasöre yazar
 *
 * Pakete girmeyenler: .git, dist, build, content içeriği, config.php,
 * düzenleyici artıkları.
 */

require dirname(__DIR__) . '/src/Autoloader.php';

HiCMS\Autoloader::register(dirname(__DIR__) . '/src');

use HiCMS\Kernel;
use HiCMS\Support\Archive;
use HiCMS\Support\Fs;
use HiCMS\Support\Str;

$root = dirname(__DIR__);

/* -------------------------------------------------------------------------
 * Argümanlar
 * ---------------------------------------------------------------------- */

$outDir = $root . '/dist';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--out=')) {
        $outDir = rtrim(substr($argument, 6), '/\\');
    }
}

/* -------------------------------------------------------------------------
 * Sürüm tutarlılığı denetimi
 * ---------------------------------------------------------------------- */

$manifest = json_decode((string) file_get_contents($root . '/hicms.json'), true);
$version  = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';

if ($version === '') {
    exit("hicms.json içinde sürüm bulunamadı.\n");
}

if ($version !== Kernel::VERSION) {
    printf(
        "UYARI: hicms.json (%s) ile Kernel::VERSION (%s) uyuşmuyor.\n"
        . "Güncelleyici sürüm karşılaştırmasını hicms.json'a göre yapar; ikisini eşitleyin.\n\n",
        $version,
        Kernel::VERSION
    );
}

/* -------------------------------------------------------------------------
 * Paket içeriği
 * ---------------------------------------------------------------------- */

$skipDirs = [
    '.git', '.github', '.idea', '.vscode', 'dist', 'build', 'node_modules', 'vendor',
    'HiAdmin', 'backups', 'cache', 'tmp', 'uploads',
];

$skipFiles = [
    'config.php', '.gitignore', '.gitattributes', 'Thumbs.db', '.DS_Store',
    'composer.json', 'composer.lock', 'options.json',
];

if (!Fs::ensureDir($outDir)) {
    exit("Çıktı klasörü oluşturulamadı: {$outDir}\n");
}

$zipFile = $outDir . '/hicms-' . $version . '.zip';

echo "HiCMS dağıtım paketi\n";
echo str_repeat('-', 52) . "\n";
echo "Sürüm : {$version}\n";
echo "Hedef : {$zipFile}\n\n";

// content/ altındaki çalışma dosyaları pakete girmez ama klasör yapısı girsin:
// kurulumda izinlerin hazır olması işi kolaylaştırıyor.
$placeholders = [
    'content/.gitkeep'         => "# Çalışma zamanı dosyaları buraya yazılır.\n",
    'content/uploads/.gitkeep' => "# Medya yüklemeleri: yıl/ay klasörlerine ayrılır.\n",
    'content/backups/.gitkeep' => "# Yedek ZIP dosyaları. Web erişimine kapalıdır.\n",
    'content/cache/.gitkeep'   => "# Üretilen önbellek dosyaları.\n",
    'content/tmp/.gitkeep'     => "# Güncelleme ve paket kurulumu için geçici alan.\n",
];

foreach ($placeholders as $relative => $contents) {
    Fs::ensureDir($root . '/' . dirname($relative));

    if (!is_file($root . '/' . $relative)) {
        file_put_contents($root . '/' . $relative, $contents);
    }
}

// Kök dizinde duran arşiv/döküm dosyaları pakete girmemeli: dosya adı bilinmez,
// bu yüzden uzantıya göre süzülür.
$excludedExtensions = ['zip', 'sql', 'gz', 'tar', 'log', 'bak', 'swp'];

foreach (Fs::listFiles($root, $skipDirs) as $relative) {
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

    if (in_array($extension, $excludedExtensions, true)) {
        $skipFiles[] = basename($relative);

        printf("Atlandi (.%s): %s\n", $extension, $relative);
    }
}

$skipFiles = array_values(array_unique($skipFiles));

$result = Archive::create($root, $zipFile, $skipDirs, $skipFiles);

if (!$result['ok']) {
    exit('Paket üretilemedi: ' . ($result['error'] ?? 'bilinmeyen hata') . "\n");
}

$size = (int) filesize($zipFile);

printf("Dosya sayısı : %d\n", (int) ($result['files'] ?? 0));
printf("Paket boyutu : %s\n\n", Str::bytes($size));

/* -------------------------------------------------------------------------
 * Paket doğrulaması: kurulum ve güncelleme için gerekenler var mı?
 * ---------------------------------------------------------------------- */

$required = [
    'index.php', 'install.php', 'router.php', 'hi-cron.php', 'hicms.json',
    'src/Kernel.php', 'src/Autoloader.php', 'src/functions.php',
    'admin/index.php', 'admin/login.php', 'admin/includes/bootstrap.php',
    'themes/hiblog/hicms.json', 'themes/hiblog/index.php',
];

$zip     = new ZipArchive();
$missing = [];

if ($zip->open($zipFile) === true) {
    foreach ($required as $entry) {
        if ($zip->locateName($entry) === false) {
            $missing[] = $entry;
        }
    }

    // config.php kesinlikle pakette olmamalı.
    $leaked = $zip->locateName('config.php') !== false;

    $zip->close();
} else {
    exit("Üretilen paket açılamadı.\n");
}

if ($missing !== []) {
    echo "EKSIK DOSYALAR:\n";

    foreach ($missing as $entry) {
        echo '  - ' . $entry . "\n";
    }

    exit(1);
}

if ($leaked) {
    echo "HATA: config.php pakete girmiş. Bu dosya asla dağıtılmamalı.\n";
    exit(1);
}

echo "Dogrulama: kurulum ve guncelleme icin gereken tum dosyalar pakette.\n";
echo "config.php pakete girmemis.\n\n";
echo "Kurulum : ZIP'i sunucuya acin, tarayicidan install.php adresini acin.\n";
echo "Guncelleme: Panel > Sistem > Guncellemeler bolumunden ayni ZIP'i yukleyin.\n";
