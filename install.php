<?php

declare(strict_types=1);

/**
 * HiCMS — Kurulum sihirbazı
 *
 * Aynı ZIP hem kurulum hem güncelleme için kullanılır: bu dosya yalnızca
 * `config.php` yokken çalışır, kurulum tamamlandığında kendini kilitler.
 *
 * @package HiCMS
 */

use HiCMS\Install\Installer;
use HiCMS\Install\Requirements;
use HiCMS\Support\Str;

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    exit('HiCMS için PHP 8.2 veya üstü gerekir. Kurulu sürüm: ' . PHP_VERSION);
}

require __DIR__ . '/src/Autoloader.php';
HiCMS\Autoloader::register(__DIR__ . '/src');

/*
 * Kurulum sırasında hatalar HER ZAMAN görünür olmalı. Aksi hâlde bir sorun
 * çıktığında kullanıcı boş bir 500 sayfası görür ve neyin bozulduğunu
 * anlayamaz. `Kernel::boot()` yapılandırmayı okuduğunda display_errors'ı
 * kendi ayarına göre değiştirdiği için bu değerler kurulum akışının içinde
 * yeniden uygulanır (bkz. `showErrors()`).
 */
$showErrors = static function (): void {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
};

$showErrors();

// Kurulum yarıda ölürse sebebi ekrana yaz — sessiz 500 bırakma.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    printf(
        '<div style="font:14px/1.6 system-ui,sans-serif;max-width:660px;margin:40px auto;padding:16px 18px;'
        . 'border:1px solid #f0c8c4;border-radius:8px;background:#fdf0ef;color:#b3261e">'
        . '<strong style="display:block;margin-bottom:6px">Kurulum beklenmedik biçimde durdu</strong>'
        . '<p style="margin:0 0 8px">%s</p>'
        . '<p style="margin:0;font:12px/1.5 ui-monospace,monospace;color:#7a1f19">%s satır %d</p></div>',
        htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(basename($error['file']), ENT_QUOTES, 'UTF-8'),
        (int) $error['line']
    );
});

$installer = new Installer(__DIR__);

// Kurulu sistemde sihirbaz kapalıdır.
if ($installer->isInstalled()) {
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    http_response_code(403);
    $lockedMessage = 'HiCMS zaten kurulu. Güvenlik için bu dosyayı silebilirsiniz.';
    $adminLink     = $base . '/admin/';
} else {
    $lockedMessage = '';
    $adminLink     = '';
}

/* -------------------------------------------------------------------------
 * Durum
 * ---------------------------------------------------------------------- */

$step     = (int) ($_GET['adim'] ?? 1);
$errors   = [];
$notices  = [];
$steps    = [];
$adminUrl = '';

$guessedUrl = (function (): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path   = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    return $scheme . '://' . $host . $path;
})();

$form = [
    'db_host'   => (string) ($_POST['db_host'] ?? 'localhost'),
    'db_port'   => (string) ($_POST['db_port'] ?? '3306'),
    'db_name'   => (string) ($_POST['db_name'] ?? 'hicms'),
    'db_user'   => (string) ($_POST['db_user'] ?? 'root'),
    'db_pass'   => (string) ($_POST['db_pass'] ?? ''),
    'db_prefix' => (string) ($_POST['db_prefix'] ?? 'hi_'),
    'url'       => (string) ($_POST['url'] ?? $guessedUrl),
    'title'     => (string) ($_POST['title'] ?? ''),
    'tagline'   => (string) ($_POST['tagline'] ?? ''),
    'username'  => (string) ($_POST['username'] ?? ''),
    'name'      => (string) ($_POST['name'] ?? ''),
    'email'     => (string) ($_POST['email'] ?? ''),
    'password'  => (string) ($_POST['password'] ?? ''),
    'demo'      => isset($_POST['demo']) || !isset($_POST['action']),
    'locale'    => (string) ($_POST['locale'] ?? 'tr_TR'),
    'timezone'  => (string) ($_POST['timezone'] ?? 'Europe/Istanbul'),
];

$requirements = Requirements::check(__DIR__);
$canProceed   = Requirements::passes($requirements);

$action = $lockedMessage === '' ? (string) ($_POST['action'] ?? '') : '';

if ($action === 'test-db') {
    $result = $installer->testDatabase([
        'host' => $form['db_host'], 'port' => (int) $form['db_port'], 'name' => $form['db_name'],
        'user' => $form['db_user'], 'pass' => $form['db_pass'], 'prefix' => $form['db_prefix'],
    ], true);

    if ($result['ok']) {
        $notices[] = $result['created']
            ? 'Veritabanı bulunamadı ve oluşturuldu (MySQL ' . $result['version'] . ').'
            : 'Bağlantı başarılı (MySQL ' . $result['version'] . ').';
        $step = 3;
    } else {
        $errors[] = $result['error'];
        $step     = 2;
    }
}

if ($action === 'install') {
    try {
        $result = $installer->install([
        'db' => [
            'host' => $form['db_host'], 'port' => (int) $form['db_port'], 'name' => $form['db_name'],
            'user' => $form['db_user'], 'pass' => $form['db_pass'], 'prefix' => $form['db_prefix'],
        ],
        'url'      => $form['url'],
        'locale'   => $form['locale'],
        'timezone' => $form['timezone'],
        'site'     => [
            'title'       => $form['title'] !== '' ? $form['title'] : 'HiCMS Sitesi',
            'tagline'     => $form['tagline'],
            'description' => $form['tagline'],
        ],
        'admin' => [
            'username' => $form['username'],
            'email'    => $form['email'],
            'password' => $form['password'],
            'name'     => $form['name'],
        ],
            'demo' => $form['demo'],
        ]);
    } catch (Throwable $exception) {
        // Buraya düşmemesi gerekir; düşerse sebebini gizlemeyelim.
        $result = [
            'ok'    => false,
            'steps' => [],
            'error' => sprintf(
                '%s (%s satır %d)',
                $exception->getMessage(),
                basename($exception->getFile()),
                $exception->getLine()
            ),
        ];
    }

    // Kernel yapılandırmayı okurken display_errors'ı kapatmış olabilir.
    $showErrors();

    if ($result['ok']) {
        $steps    = $result['steps'];
        $adminUrl = $result['adminUrl'];
        $step     = 4;
    } else {
        $errors[] = $result['error'];
        $steps    = $result['steps'];
        $step     = 3;
    }
}

if ($step === 2 && !$canProceed) {
    $step = 1;
}

$timezones = ['Europe/Istanbul', 'Europe/London', 'Europe/Berlin', 'UTC', 'America/New_York', 'Asia/Dubai'];
$summary   = Requirements::summary($requirements);
?>
<!DOCTYPE html>
<html lang="tr" data-scheme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>HiCMS Kurulumu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #fafafa; --surface: #fff; --border: #ebebeb; --border-strong: #dcdcdc;
            --text: #18181b; --muted: #71717a; --faint: #a1a1aa;
            --accent: #95389e; --accent-soft: #f8f1f9; --accent-ring: rgba(149, 56, 158, .14);
            --ok: #157f4a; --ok-soft: #edf8f2; --warn: #97600c; --warn-soft: #fdf6e9;
            --err: #b3261e; --err-soft: #fdf0ef;
            --radius: 8px;
            --font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #101012; --surface: #17171a; --border: #26262a; --border-strong: #35353b;
                --text: #ededf0; --muted: #a0a0a8; --faint: #71717a;
                --accent: #cb87d3; --accent-soft: #241a28; --accent-ring: rgba(203,135,211,.2);
                --ok: #4ec585; --ok-soft: #12251b; --warn: #e0ac48; --warn-soft: #251d10;
                --err: #f08b83; --err-soft: #261615;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 40px 20px 80px; background: var(--bg); color: var(--text);
            font-family: var(--font); font-size: 15px; line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 660px; margin: 0 auto; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; }
        .mark {
            width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center;
            background: linear-gradient(145deg, var(--accent), #5c2a8f); color: #fff;
            font-weight: 600; font-size: 16px; letter-spacing: -.02em;
        }
        .brand h1 { margin: 0; font-size: 19px; font-weight: 600; letter-spacing: -.02em; }
        .brand p { margin: 1px 0 0; font-size: 13px; color: var(--muted); }

        .steps { display: flex; gap: 6px; margin-bottom: 28px; }
        .steps span {
            flex: 1; height: 3px; border-radius: 2px; background: var(--border);
        }
        .steps span.done { background: var(--accent); }

        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 28px;
        }
        .card + .card { margin-top: 18px; }
        h2 { margin: 0 0 6px; font-size: 18px; font-weight: 600; letter-spacing: -.02em; }
        .lede { margin: 0 0 24px; color: var(--muted); font-size: 14px; }

        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; }
        .hint { margin: 6px 0 0; font-size: 12.5px; color: var(--faint); }
        .field { margin-bottom: 18px; }
        .field:last-child { margin-bottom: 0; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 560px) { .row { grid-template-columns: 1fr; } }

        input[type=text], input[type=password], input[type=email], input[type=url], select {
            width: 100%; padding: 9px 12px; font: inherit; font-size: 14px;
            color: var(--text); background: var(--surface);
            border: 1px solid var(--border-strong); border-radius: 6px;
        }
        input:focus, select:focus {
            outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-ring);
        }
        .check { display: flex; gap: 10px; align-items: flex-start; font-size: 14px; }
        .check input { margin-top: 3px; accent-color: var(--accent); width: 16px; height: 16px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; justify-content: center;
            padding: 10px 18px; font: inherit; font-size: 14px; font-weight: 500;
            border-radius: 6px; border: 1px solid var(--border-strong);
            background: var(--surface); color: var(--text); cursor: pointer; text-decoration: none;
        }
        .btn:hover { background: var(--bg); }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { filter: brightness(1.08); background: var(--accent); }
        .actions { display: flex; gap: 10px; align-items: center; margin-top: 24px; }
        .actions .spacer { flex: 1; }

        .list { list-style: none; margin: 0; padding: 0; }
        .list li {
            display: flex; align-items: flex-start; gap: 11px; padding: 10px 0;
            border-bottom: 1px solid var(--border); font-size: 14px;
        }
        .list li:last-child { border-bottom: 0; }
        .dot {
            flex: 0 0 18px; width: 18px; height: 18px; border-radius: 50%; display: grid;
            place-items: center; font-size: 11px; font-weight: 700; margin-top: 2px;
        }
        .dot.ok { background: var(--ok-soft); color: var(--ok); }
        .dot.warn { background: var(--warn-soft); color: var(--warn); }
        .dot.err { background: var(--err-soft); color: var(--err); }
        .list .meta { margin-left: auto; color: var(--faint); font-size: 12.5px; white-space: nowrap; }
        .list small { display: block; color: var(--faint); font-size: 12.5px; }

        .alert {
            padding: 13px 15px; border-radius: 6px; font-size: 14px; margin-bottom: 20px;
            border: 1px solid transparent;
        }
        .alert-err { background: var(--err-soft); color: var(--err); border-color: color-mix(in srgb, var(--err) 25%, transparent); }
        .alert-ok { background: var(--ok-soft); color: var(--ok); border-color: color-mix(in srgb, var(--ok) 25%, transparent); }
        .alert strong { display: block; margin-bottom: 2px; }

        code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: .9em; }
        .foot { margin-top: 26px; text-align: center; font-size: 12.5px; color: var(--faint); }
        .foot a { color: var(--muted); }
        .done-mark {
            width: 52px; height: 52px; border-radius: 50%; margin: 0 auto 18px; display: grid;
            place-items: center; background: var(--ok-soft); color: var(--ok); font-size: 24px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <span class="mark" aria-hidden="true">Hi</span>
        <div>
            <h1>HiCMS Kurulumu</h1>
            <p>Sürüm <?= htmlspecialchars(HiCMS\Kernel::VERSION) ?> · yaklaşık 2 dakika</p>
        </div>
    </div>

    <?php if ($lockedMessage !== '') : ?>
        <div class="card">
            <h2>Kurulum kapalı</h2>
            <p class="lede"><?= htmlspecialchars($lockedMessage) ?></p>
            <a class="btn btn-primary" href="<?= htmlspecialchars($adminLink) ?>">Yönetim paneline git</a>
        </div>
        </div></body></html>
        <?php exit;
    endif; ?>

    <div class="steps" aria-hidden="true">
        <?php for ($i = 1; $i <= 4; $i++) : ?>
            <span class="<?= $i <= $step ? 'done' : '' ?>"></span>
        <?php endfor; ?>
    </div>

    <?php foreach ($errors as $error) : ?>
        <div class="alert alert-err"><strong>Kurulum durdu</strong><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($notices as $notice) : ?>
        <div class="alert alert-ok"><?= htmlspecialchars($notice) ?></div>
    <?php endforeach; ?>

    <?php if ($step === 1) : ?>
        <div class="card">
            <h2>Sunucu gereksinimleri</h2>
            <p class="lede">
                Zorunlu maddelerin tamamı sağlanmalı. Uyarılar kurulumu engellemez ama
                bazı özellikler (yedekleme, görsel optimizasyonu) çalışmaz.
            </p>

            <ul class="list">
                <?php foreach ($requirements as $check) : ?>
                    <?php
                    $class = $check['ok'] ? 'ok' : ($check['required'] ? 'err' : 'warn');
                    $glyph = $check['ok'] ? '✓' : ($check['required'] ? '✕' : '!');
                    ?>
                    <li>
                        <span class="dot <?= $class ?>" aria-hidden="true"><?= $glyph ?></span>
                        <span>
                            <?= htmlspecialchars($check['label']) ?>
                            <?php if (!$check['ok']) : ?>
                                <small><?= htmlspecialchars($check['hint']) ?></small>
                            <?php endif; ?>
                        </span>
                        <span class="meta"><?= htmlspecialchars($check['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="actions">
                <span style="font-size:13px;color:var(--muted)">
                    <?= $summary['ok'] ?> uygun · <?= $summary['warn'] ?> uyarı · <?= $summary['fail'] ?> eksik
                </span>
                <span class="spacer"></span>
                <?php if ($canProceed) : ?>
                    <a class="btn btn-primary" href="?adim=2">Devam et</a>
                <?php else : ?>
                    <a class="btn" href="?adim=1">Yeniden denetle</a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($step === 2) : ?>
        <form class="card" method="post" action="install.php">
            <h2>Veritabanı</h2>
            <p class="lede">
                MySQL veya MariaDB bilgilerini girin. Veritabanı yoksa ve kullanıcınızın
                yetkisi varsa otomatik oluşturulur.
            </p>

            <div class="row">
                <div class="field">
                    <label for="db_host">Sunucu</label>
                    <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($form['db_host']) ?>" required>
                </div>
                <div class="field">
                    <label for="db_port">Port</label>
                    <input type="text" id="db_port" name="db_port" value="<?= htmlspecialchars($form['db_port']) ?>">
                </div>
            </div>

            <div class="field">
                <label for="db_name">Veritabanı adı</label>
                <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($form['db_name']) ?>" required>
            </div>

            <div class="row">
                <div class="field">
                    <label for="db_user">Kullanıcı</label>
                    <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($form['db_user']) ?>" required>
                </div>
                <div class="field">
                    <label for="db_pass">Şifre</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($form['db_pass']) ?>" autocomplete="off">
                </div>
            </div>

            <div class="field">
                <label for="db_prefix">Tablo öneki</label>
                <input type="text" id="db_prefix" name="db_prefix" value="<?= htmlspecialchars($form['db_prefix']) ?>">
                <p class="hint">Aynı veritabanında birden fazla kurulum barındıracaksanız öneki değiştirin.</p>
            </div>

            <div class="actions">
                <a class="btn" href="?adim=1">Geri</a>
                <span class="spacer"></span>
                <button class="btn btn-primary" type="submit" name="action" value="test-db">Bağlantıyı sına ve devam et</button>
            </div>
        </form>

    <?php elseif ($step === 3) : ?>
        <form class="card" method="post" action="install.php">
            <?php foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix'] as $hidden) : ?>
                <input type="hidden" name="<?= $hidden ?>" value="<?= htmlspecialchars($form[$hidden]) ?>">
            <?php endforeach; ?>

            <h2>Site ve yönetici hesabı</h2>
            <p class="lede">Bu bilgileri sonradan panelden değiştirebilirsiniz.</p>

            <div class="field">
                <label for="title">Site başlığı</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($form['title']) ?>"
                       placeholder="örn. Hi, Günlük" required>
            </div>

            <div class="field">
                <label for="tagline">Slogan <span style="color:var(--faint);font-weight:400">(isteğe bağlı)</span></label>
                <input type="text" id="tagline" name="tagline" value="<?= htmlspecialchars($form['tagline']) ?>"
                       placeholder="Sitenizi bir cümleyle anlatın">
            </div>

            <div class="field">
                <label for="url">Site adresi</label>
                <input type="url" id="url" name="url" value="<?= htmlspecialchars($form['url']) ?>" required>
                <p class="hint">Bağlantılar bu adrese göre üretilir. Alan adınız varsa şimdi girin.</p>
            </div>

            <div class="row">
                <div class="field">
                    <label for="locale">Arayüz dili</label>
                    <select id="locale" name="locale">
                        <option value="tr_TR" <?= $form['locale'] === 'tr_TR' ? 'selected' : '' ?>>Türkçe</option>
                        <option value="en_US" <?= $form['locale'] === 'en_US' ? 'selected' : '' ?>>English</option>
                    </select>
                </div>
                <div class="field">
                    <label for="timezone">Saat dilimi</label>
                    <select id="timezone" name="timezone">
                        <?php foreach ($timezones as $zone) : ?>
                            <option value="<?= htmlspecialchars($zone) ?>" <?= $form['timezone'] === $zone ? 'selected' : '' ?>>
                                <?= htmlspecialchars($zone) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr style="border:0;border-top:1px solid var(--border);margin:24px 0">

            <div class="row">
                <div class="field">
                    <label for="name">Adınız</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($form['name']) ?>"
                           placeholder="Yazılarda görünecek ad" required>
                </div>
                <div class="field">
                    <label for="username">Kullanıcı adı</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($form['username']) ?>"
                           pattern="[A-Za-z0-9_.\-]{3,32}" required autocomplete="username">
                    <p class="hint">Sonradan değiştirilemez.</p>
                </div>
            </div>

            <div class="field">
                <label for="email">E-posta</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" required autocomplete="email">
            </div>

            <div class="field">
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password"
                       value="<?= htmlspecialchars($form['password']) ?>">
                <p class="hint">En az 10 karakter. Öneri: <code><?= htmlspecialchars(substr(Str::random(8), 0, 14)) ?></code></p>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="demo" value="1" <?= $form['demo'] ? 'checked' : '' ?>>
                    <span>
                        <strong style="font-weight:500">Örnek içerik ekle</strong>
                        <small style="display:block;color:var(--faint);font-size:12.5px">
                            Üç tanıtım yazısı, kategoriler ve menü. Sonradan tek tek silebilirsiniz.
                        </small>
                    </span>
                </label>
            </div>

            <div class="actions">
                <a class="btn" href="?adim=2">Geri</a>
                <span class="spacer"></span>
                <button class="btn btn-primary" type="submit" name="action" value="install">Kurulumu tamamla</button>
            </div>
        </form>

        <?php if ($steps !== []) : ?>
            <div class="card">
                <h2>Tamamlanan adımlar</h2>
                <ul class="list">
                    <?php foreach ($steps as $done) : ?>
                        <li><span class="dot ok" aria-hidden="true">✓</span><span><?= htmlspecialchars($done) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

    <?php else : ?>
        <div class="card" style="text-align:center">
            <div class="done-mark" aria-hidden="true">✓</div>
            <h2>Kurulum tamamlandı</h2>
            <p class="lede">HiCMS kullanıma hazır. Panele girip ilk yazınızı yazabilirsiniz.</p>
            <a class="btn btn-primary" href="<?= htmlspecialchars($adminUrl) ?>">Yönetim paneline git</a>
        </div>

        <div class="card">
            <h2>Yapılanlar</h2>
            <ul class="list">
                <?php foreach ($steps as $done) : ?>
                    <li><span class="dot ok" aria-hidden="true">✓</span><span><?= htmlspecialchars($done) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <h2>Son iki adım</h2>
            <ul class="list">
                <li>
                    <span class="dot warn" aria-hidden="true">1</span>
                    <span>
                        <strong style="font-weight:500"><code>install.php</code> dosyasını silin</strong>
                        <small>Kurulum tamamlandığı için gerekmiyor; sihirbaz kendini kilitledi ama silmek en temizi.</small>
                    </span>
                </li>
                <li>
                    <span class="dot warn" aria-hidden="true">2</span>
                    <span>
                        <strong style="font-weight:500">Planlı görevler için cron ekleyin (isteğe bağlı)</strong>
                        <small><code>*/5 * * * * php <?= htmlspecialchars(__DIR__) ?>/hi-cron.php</code></small>
                    </span>
                </li>
            </ul>
        </div>
    <?php endif; ?>

    <p class="foot">
        HiCMS <?= htmlspecialchars(HiCMS\Kernel::VERSION) ?> ·
        <a href="https://github.com/rasidinnbugda/HiCMS" target="_blank" rel="noopener">Kaynak kodu</a>
    </p>
</div>
</body>
</html>
