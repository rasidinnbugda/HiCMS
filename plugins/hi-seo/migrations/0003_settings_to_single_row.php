<?php

declare(strict_types=1);

use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiSEO — ayarları tek option satırına taşır
 *
 * 1.0.0 her ayarı kendi satırında tutuyordu:
 *
 *     plugin.hi-seo.json_ld       → true
 *     plugin.hi-seo.sitemap       → true
 *     plugin.hi-seo.robots_extra  → "…"
 *     plugin.hi-seo.sitemap_stamp → 1785447467
 *
 * 0.3.0 ayar sözleşmesi (`hi_settings()`) tek satır kullanıyor:
 * `plugin.hi-seo.settings` içinde bir dizi. Bu göç olmadan kurulu sitelerin
 * ayarları sessizce varsayılana dönerdi — sitemap kapatılmış bir site
 * güncellemeden sonra yeniden sitemap basmaya başlardı.
 *
 * `sitemap_stamp` taşınmaz: yalnızca "içerik değişti" damgasıydı ve artık
 * lastmod doğrudan içerikten hesaplanıyor.
 *
 * Göç FİKİRSİZ (idempotent): eski anahtar yoksa hiçbir şey yapmaz, yeni satır
 * varsa yalnızca eksik anahtarları doldurur.
 */
return new class {
    /** eski option adı → yeni ayar anahtarı */
    private const MAP = [
        'plugin.hi-seo.json_ld'      => 'json_ld',
        'plugin.hi-seo.sitemap'      => 'sitemap',
        'plugin.hi-seo.robots_extra' => 'robots_extra',
    ];

    private const DROP = ['plugin.hi-seo.sitemap_stamp'];

    public function up(Schema $schema, Connection $db): void
    {
        // Kuru koşuda (duman testi) veritabanı yok; yalnızca şema SQL'i üretilir.
        if ($schema->isDryRun() || !$db->tableExists('options')) {
            return;
        }

        $target = 'plugin.hi-seo.settings';
        $stored = $this->read($db, $target);
        $stored = is_array($stored) ? $stored : [];
        $moved  = false;

        foreach (self::MAP as $old => $key) {
            $value = $this->read($db, $old);

            if ($value === null) {
                continue;
            }

            // Yeni satırda o anahtar zaten varsa kullanıcının son kararı odur.
            if (!array_key_exists($key, $stored)) {
                $stored[$key] = $value;
            }

            $db->delete('options', ['name' => $old]);
            $moved = true;
        }

        foreach (self::DROP as $old) {
            $db->delete('options', ['name' => $old]);
        }

        if (!$moved && $stored === []) {
            return;
        }

        $encoded = (string) json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($db->builder('options')->where('name', $target)->exists()) {
            $db->update('options', ['value' => $encoded, 'autoload' => 1], ['name' => $target]);

            return;
        }

        $db->insert('options', ['name' => $target, 'value' => $encoded, 'autoload' => 1]);
    }

    /**
     * Geri alma ayarları eski dağınık biçime döndürür; 1.0.0'a dönen bir site
     * ayarlarını kaybetmesin.
     */
    public function down(Schema $schema, Connection $db): void
    {
        if ($schema->isDryRun() || !$db->tableExists('options')) {
            return;
        }

        $stored = $this->read($db, 'plugin.hi-seo.settings');

        if (!is_array($stored)) {
            return;
        }

        foreach (self::MAP as $old => $key) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }

            $encoded = (string) json_encode($stored[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($db->builder('options')->where('name', $old)->exists()) {
                $db->update('options', ['value' => $encoded], ['name' => $old]);

                continue;
            }

            $db->insert('options', ['name' => $old, 'value' => $encoded, 'autoload' => 1]);
        }
    }

    private function read(Connection $db, string $name): mixed
    {
        $raw = $db->builder('options')->where('name', $name)->value('value');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $raw;
    }
};
