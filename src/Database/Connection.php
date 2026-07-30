<?php

declare(strict_types=1);

namespace HiCMS\Database;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * MySQL/MariaDB bağlantısı.
 *
 * Bağlantı tembeldir: ilk sorguya kadar PDO kurulmaz. Böylece statik dosya
 * servis eden ya da yalnızca yönlendirme yapan istekler veritabanına hiç
 * dokunmaz.
 *
 * Tüm tablo adları önekten (`hi_`) geçer; `t()` bunu uygular.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $queryCount = 0;

    /** @var list<array{sql: string, ms: float}> Hata ayıklama için son sorgular */
    private array $log = [];

    private bool $logging = false;

    /**
     * @param array{host?: string, port?: int|string, name?: string, user?: string,
     *              pass?: string, charset?: string, prefix?: string, socket?: string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function enableLogging(bool $enabled = true): void
    {
        $this->logging = $enabled;
    }

    public function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? 'hi_');
    }

    /** Tablo adına önek uygular. */
    public function t(string $table): string
    {
        return $this->prefix() . $table;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        if (($this->config['socket'] ?? '') !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
                $this->config['socket'], $this->config['name'] ?? '', $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'] ?? 'localhost',
                (int) ($this->config['port'] ?? 3306),
                $this->config['name'] ?? '',
                $charset);
        }

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) ($this->config['user'] ?? ''),
                (string) ($this->config['pass'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Veritabanına bağlanılamadı: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }

        // Katı kip: sessiz veri kırpmalarını hataya çevirir.
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo;
    }

    /**
     * Bağlantıyı sınar — kurulum sihirbazı kullanır.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, error: string, version: string}
     */
    public static function test(array $config): array
    {
        try {
            $connection = new self($config);
            $version    = (string) $connection->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            return ['ok' => true, 'error' => '', 'version' => $version];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'version' => ''];
        }
    }

    /**
     * Sunucuya bağlanır ama veritabanını seçmez — kurulumda veritabanı
     * oluşturmak için gerekir.
     *
     * @param array<string, mixed> $config
     */
    public static function createDatabase(array $config): array
    {
        $name = (string) ($config['name'] ?? '');

        if ($name === '' || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            return ['ok' => false, 'error' => 'Geçersiz veritabanı adı.'];
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
                $config['host'] ?? 'localhost', (int) ($config['port'] ?? 3306));

            $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    public function builder(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $started = $this->logging ? microtime(true) : 0.0;

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($this->normalize($bindings));

        $this->queryCount++;

        if ($this->logging) {
            $this->log[] = ['sql' => $sql, 'ms' => round((microtime(true) - $started) * 1000, 2)];
        }

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /** Ham SQL çalıştırır (yalnızca migration ve yedek geri yükleme). */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
        $this->queryCount++;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->t($table),
            implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn(string $c): string => ":{$c}", $columns))
        );

        $this->run($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }

        $set        = [];
        $bindings   = [];

        foreach ($data as $column => $value) {
            $set[]                = "`{$column}` = :set_{$column}";
            $bindings["set_{$column}"] = $value;
        }

        $conditions = [];

        foreach ($where as $column => $value) {
            $conditions[]              = "`{$column}` = :where_{$column}";
            $bindings["where_{$column}"] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->t($table),
            implode(', ', $set),
            $conditions === [] ? '1 = 0' : implode(' AND ', $conditions)
        );

        return $this->statement($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            return 0;
        }

        $conditions = [];

        foreach (array_keys($where) as $column) {
            $conditions[] = "`{$column}` = :{$column}";
        }

        $sql = sprintf('DELETE FROM `%s` WHERE %s', $this->t($table), implode(' AND ', $conditions));

        return $this->statement($sql, $where);
    }

    /**
     * İşlem içinde çalıştırır; hata olursa geri alır.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    public function tableExists(string $table): bool
    {
        $found = $this->scalar('SHOW TABLES LIKE ?', [$this->t($table)]);

        return $found !== null;
    }

    public function columnExists(string $table, string $column): bool
    {
        $rows = $this->select(
            sprintf('SHOW COLUMNS FROM `%s` LIKE ?', $this->t($table)),
            [$column]
        );

        return $rows !== [];
    }

    /**
     * Veritabanındaki HiCMS tablolarını listeler (yedekleme kullanır).
     *
     * @return list<string>
     */
    public function ownTables(): array
    {
        $rows   = $this->select('SHOW TABLES LIKE ?', [$this->prefix() . '%']);
        $tables = [];

        foreach ($rows as $row) {
            $tables[] = (string) reset($row);
        }

        sort($tables);

        return $tables;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /** @return list<array{sql: string, ms: float}> */
    public function log(): array
    {
        return $this->log;
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Bool değerleri PDO'nun anlayacağı biçime çevirir.
     *
     * @param array<string|int, mixed> $bindings
     * @return array<string|int, mixed>
     */
    private function normalize(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 1 : 0;
            } elseif ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $bindings[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $bindings;
    }
}
