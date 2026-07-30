<?php

declare(strict_types=1);

namespace HiCMS\Update;

use HiCMS\Database\Connection;
use HiCMS\Support\Archive;
use HiCMS\Support\Dates;
use HiCMS\Support\Fs;
use HiCMS\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Yedekleme ve geri yükleme.
 *
 * Bir yedek tek bir ZIP dosyasıdır ve şunları içerir:
 *   manifest.json   → sürüm, tarih, tablo listesi
 *   database.sql    → tüm HiCMS tablolarının şeması ve verisi
 *   uploads/        → medya dosyaları (isteğe bağlı)
 *
 * Güncelleme öncesinde otomatik olarak veritabanı yedeği alınır; böylece
 * migration'lar beklenmedik biçimde davranırsa geri dönüş yolu vardır.
 */
final class Backup
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $backupsDir,
        private readonly string $uploadsDir,
        private readonly string $coreVersion,
    ) {
    }

    /**
     * Yedek üretir.
     *
     * @return array{ok: bool, file: string, size: int, error: string}
     */
    public function create(string $label = '', bool $includeUploads = true): array
    {
        if (!Archive::supported()) {
            return ['ok' => false, 'file' => '', 'size' => 0, 'error' => 'PHP zip eklentisi etkin değil.'];
        }

        if (!Fs::ensureDir($this->backupsDir)) {
            return ['ok' => false, 'file' => '', 'size' => 0, 'error' => 'Yedek klasörü oluşturulamadı.'];
        }

        Fs::protect($this->backupsDir);

        $name = sprintf('hicms-%s-%s.zip', date('Ymd-His'), substr(Str::random(3), 0, 4));
        $path = $this->backupsDir . '/' . $name;

        try {
            $sql = $this->dumpDatabase();
        } catch (Throwable $exception) {
            return [
                'ok'    => false,
                'file'  => '',
                'size'  => 0,
                'error' => 'Veritabanı dökümü alınamadı: ' . $exception->getMessage(),
            ];
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'file' => '', 'size' => 0, 'error' => 'Yedek dosyası oluşturulamadı.'];
        }

        $manifest = [
            'created_at'      => Dates::stamp(),
            'core_version'    => $this->coreVersion,
            'php_version'     => PHP_VERSION,
            'label'           => $label,
            'tables'          => $this->db->ownTables(),
            'prefix'          => $this->db->prefix(),
            'includes_uploads' => $includeUploads,
        ];

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('database.sql', $sql);

        if ($includeUploads && is_dir($this->uploadsDir)) {
            foreach (Fs::listFiles($this->uploadsDir) as $relative) {
                $zip->addFile($this->uploadsDir . '/' . $relative, 'uploads/' . $relative);
            }
        }

        $zip->close();

        return ['ok' => true, 'file' => $name, 'size' => (int) @filesize($path), 'error' => ''];
    }

    /**
     * Mevcut yedekler, en yeni önce.
     *
     * @return list<array{file: string, size: int, created: string, label: string, version: string}>
     */
    public function all(): array
    {
        if (!is_dir($this->backupsDir)) {
            return [];
        }

        $backups = [];

        foreach (glob($this->backupsDir . '/*.zip') ?: [] as $path) {
            $meta = $this->readManifest($path);

            $backups[] = [
                'file'    => basename($path),
                'size'    => (int) @filesize($path),
                'created' => (string) ($meta['created_at'] ?? date('Y-m-d H:i:s', (int) @filemtime($path))),
                'label'   => (string) ($meta['label'] ?? ''),
                'version' => (string) ($meta['core_version'] ?? ''),
            ];
        }

        usort($backups, static fn(array $a, array $b): int => strcmp($b['created'], $a['created']));

        return $backups;
    }

    /**
     * Yedeği geri yükler.
     *
     * @return array{ok: bool, error: string, tables: int}
     */
    public function restore(string $file): array
    {
        $path = $this->pathFor($file);

        if ($path === null) {
            return ['ok' => false, 'error' => 'Yedek dosyası bulunamadı.', 'tables' => 0];
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return ['ok' => false, 'error' => 'Yedek dosyası açılamadı.', 'tables' => 0];
        }

        $sql = $zip->getFromName('database.sql');

        if ($sql === false) {
            $zip->close();

            return ['ok' => false, 'error' => 'Yedekte database.sql bulunamadı.', 'tables' => 0];
        }

        $tables = 0;

        try {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($this->splitStatements((string) $sql) as $statement) {
                $this->db->exec($statement);

                if (stripos($statement, 'CREATE TABLE') !== false) {
                    $tables++;
                }
            }

            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

            // Geri yükleme tabloları düşürüp yeniden kurdu: varlık belleği bayat.
            $this->db->forgetSchemaCache();
        } catch (Throwable $exception) {
            $zip->close();
            $this->db->forgetSchemaCache();

            return ['ok' => false, 'error' => 'Geri yükleme hatası: ' . $exception->getMessage(), 'tables' => $tables];
        }

        // Yüklemeleri geri yaz.
        $manifest = $this->readManifest($path);

        if (!empty($manifest['includes_uploads'])) {
            Fs::ensureDir($this->uploadsDir);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                    continue;
                }

                if (str_contains($name, '..')) {
                    continue;
                }

                $target = $this->uploadsDir . '/' . substr($name, 8);

                Fs::ensureDir(dirname($target));

                $stream = $zip->getStream($name);

                if ($stream !== false) {
                    @file_put_contents($target, $stream);
                    fclose($stream);
                }
            }
        }

        $zip->close();

        return ['ok' => true, 'error' => '', 'tables' => $tables];
    }

    public function delete(string $file): bool
    {
        $path = $this->pathFor($file);

        return $path !== null && @unlink($path);
    }

    /**
     * En yeni N yedeği tutar, kalanını siler.
     */
    public function prune(int $keep = 5): int
    {
        $backups = $this->all();
        $deleted = 0;

        foreach (array_slice($backups, max(1, $keep)) as $backup) {
            if ($this->delete($backup['file'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function totalSize(): int
    {
        return Fs::dirSize($this->backupsDir);
    }

    public function backupsDir(): string
    {
        return $this->backupsDir;
    }

    /**
     * Tüm HiCMS tablolarını SQL olarak döker.
     */
    public function dumpDatabase(): string
    {
        $sql = "-- HiCMS veritabanı dökümü\n"
            . '-- Tarih: ' . Dates::stamp() . "\n"
            . '-- Sürüm: ' . $this->coreVersion . "\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($this->db->ownTables() as $table) {
            $create = $this->db->selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
            $createSql = (string) ($create['Create Table'] ?? '');

            if ($createSql === '') {
                continue;
            }

            $sql .= sprintf("DROP TABLE IF EXISTS `%s`;\n%s;\n\n", $table, $createSql);

            $offset = 0;
            $chunk  = 200;

            while (true) {
                $rows = $this->db->select(sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, $chunk, $offset));

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $columns = array_map(static fn(string $c): string => '`' . $c . '`', array_keys($row));
                    $values  = array_map([$this, 'quote'], array_values($row));

                    $sql .= sprintf(
                        "INSERT INTO `%s` (%s) VALUES (%s);\n",
                        $table,
                        implode(', ', $columns),
                        implode(', ', $values)
                    );
                }

                $offset += $chunk;

                if (count($rows) < $chunk) {
                    break;
                }
            }

            $sql .= "\n";
        }

        return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    private function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $this->db->pdo()->quote((string) $value);
    }

    /**
     * SQL dökümünü tek tek ifadelere ayırır.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $inString   = false;
        $quote      = '';
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString) {
                if ($char === '\\') {
                    $buffer .= $char . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    $inString = false;
                }

                $buffer .= $char;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote    = $char;
                $buffer  .= $char;
                continue;
            }

            // Yorum satırlarını atla.
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-' && trim($buffer) === '') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === ';') {
                $trimmed = trim($buffer);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return [];
        }

        $json = $zip->getFromName('manifest.json');
        $zip->close();

        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Dosya adını güvenli biçimde çözer — yol dışına çıkılamaz.
     */
    private function pathFor(string $file): ?string
    {
        $file = basename($file);

        if ($file === '' || !str_ends_with($file, '.zip')) {
            return null;
        }

        $path = $this->backupsDir . '/' . $file;

        return is_file($path) ? $path : null;
    }
}
