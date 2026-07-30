<?php

declare(strict_types=1);

namespace HiCMS\Database;

use Throwable;

/**
 * Migration motoru.
 *
 * Çekirdek migration'ları `src/Database/migrations/` altındadır. Eklentiler
 * `addSource()` ile kendi dizinlerini kaydeder; kayıtlar `kaynak:dosya`
 * biçiminde saklandığı için çekirdek ve eklenti migration'ları çakışmaz.
 *
 * Her migration dosyası bir anonim sınıf döndürür:
 *
 *     return new class {
 *         public function up(Schema $schema, Connection $db): void { … }
 *         public function down(Schema $schema, Connection $db): void { … }
 *     };
 */
final class Migrator
{
    /** @var array<string, string> kaynak adı → dizin */
    private array $sources = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Schema $schema,
    ) {
    }

    public function addSource(string $name, string $directory): void
    {
        if (is_dir($directory)) {
            $this->sources[$name] = rtrim(str_replace('\\', '/', $directory), '/');
        }
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Kayıt tablosunu hazırlar.
     */
    public function prepare(): void
    {
        $this->schema->create('migrations', static function (Blueprint $table): void {
            $table->id();
            $table->key('migration');
            $table->integer('batch');
            $table->timestamp('ran_at')->nullable();
            $table->unique('migration', 'migration_unique');
        });
    }

    /**
     * Uygulanmış migration anahtarlarını döndürür.
     *
     * @return list<string>
     */
    public function applied(): array
    {
        if (!$this->schema->hasTable('migrations')) {
            return [];
        }

        return array_map(
            'strval',
            $this->db->builder('migrations')->orderBy('id')->pluck('migration')
        );
    }

    /**
     * Bekleyen migration'ları listeler.
     *
     * @return list<array{key: string, source: string, file: string}>
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $pending = [];

        foreach ($this->sources as $source => $directory) {
            $files = glob($directory . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $key = $source . ':' . basename($file, '.php');

                if (in_array($key, $applied, true)) {
                    continue;
                }

                $pending[] = ['key' => $key, 'source' => $source, 'file' => $file];
            }
        }

        return $pending;
    }

    /**
     * Bekleyen tüm migration'ları uygular.
     *
     * @return array{ok: bool, applied: list<string>, error: string}
     */
    public function migrate(): array
    {
        $this->prepare();

        $pending = $this->pending();

        if ($pending === []) {
            return ['ok' => true, 'applied' => [], 'error' => ''];
        }

        $batch   = (int) ($this->db->builder('migrations')->max('batch') ?? 0) + 1;
        $applied = [];

        foreach ($pending as $migration) {
            try {
                $instance = require $migration['file'];

                if (!is_object($instance) || !method_exists($instance, 'up')) {
                    return [
                        'ok'      => false,
                        'applied' => $applied,
                        'error'   => "Geçersiz migration dosyası: {$migration['key']}",
                    ];
                }

                $instance->up($this->schema, $this->db);

                $this->db->insert('migrations', [
                    'migration' => $migration['key'],
                    'batch'     => $batch,
                    'ran_at'    => date('Y-m-d H:i:s'),
                ]);

                $applied[] = $migration['key'];
            } catch (Throwable $exception) {
                return [
                    'ok'      => false,
                    'applied' => $applied,
                    'error'   => sprintf('%s başarısız: %s', $migration['key'], $exception->getMessage()),
                ];
            }
        }

        return ['ok' => true, 'applied' => $applied, 'error' => ''];
    }

    /**
     * Son toplu işlemi geri alır.
     *
     * @return array{ok: bool, reverted: list<string>, error: string}
     */
    public function rollback(): array
    {
        if (!$this->schema->hasTable('migrations')) {
            return ['ok' => true, 'reverted' => [], 'error' => ''];
        }

        $batch = (int) ($this->db->builder('migrations')->max('batch') ?? 0);

        if ($batch === 0) {
            return ['ok' => true, 'reverted' => [], 'error' => ''];
        }

        $rows = $this->db->builder('migrations')
            ->where('batch', $batch)
            ->orderBy('id', 'desc')
            ->get();

        $reverted = [];

        foreach ($rows as $row) {
            $key  = (string) $row['migration'];
            $file = $this->fileFor($key);

            if ($file === null) {
                continue;
            }

            try {
                $instance = require $file;

                if (is_object($instance) && method_exists($instance, 'down')) {
                    $instance->down($this->schema, $this->db);
                }

                $this->db->delete('migrations', ['id' => $row['id']]);
                $reverted[] = $key;
            } catch (Throwable $exception) {
                return ['ok' => false, 'reverted' => $reverted, 'error' => $exception->getMessage()];
            }
        }

        return ['ok' => true, 'reverted' => $reverted, 'error' => ''];
    }

    /**
     * Bir kaynağın tüm migration'larını geri alır (eklenti kaldırılırken).
     *
     * @return array{ok: bool, reverted: list<string>, error: string}
     */
    public function rollbackSource(string $source): array
    {
        if (!$this->schema->hasTable('migrations')) {
            return ['ok' => true, 'reverted' => [], 'error' => ''];
        }

        $rows = $this->db->builder('migrations')
            ->whereLike('migration', $source . ':')
            ->orderBy('id', 'desc')
            ->get();

        $reverted = [];

        foreach ($rows as $row) {
            $key  = (string) $row['migration'];
            $file = $this->fileFor($key);

            if ($file === null) {
                $this->db->delete('migrations', ['id' => $row['id']]);
                continue;
            }

            try {
                $instance = require $file;

                if (is_object($instance) && method_exists($instance, 'down')) {
                    $instance->down($this->schema, $this->db);
                }

                $this->db->delete('migrations', ['id' => $row['id']]);
                $reverted[] = $key;
            } catch (Throwable $exception) {
                return ['ok' => false, 'reverted' => $reverted, 'error' => $exception->getMessage()];
            }
        }

        return ['ok' => true, 'reverted' => $reverted, 'error' => ''];
    }

    private function fileFor(string $key): ?string
    {
        [$source, $name] = array_pad(explode(':', $key, 2), 2, '');

        if (!isset($this->sources[$source])) {
            return null;
        }

        $file = $this->sources[$source] . '/' . $name . '.php';

        return is_file($file) ? $file : null;
    }
}
