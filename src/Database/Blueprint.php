<?php

declare(strict_types=1);

namespace HiCMS\Database;

/**
 * Tablo tanımı kurucu.
 *
 * Yabancı anahtar kısıtı bilinçli olarak kullanılmaz: paylaşımlı hostinglerde
 * eski MySQL sürümleri ve karışık depolama motorları yüzünden migration'lar
 * kırılganlaşıyor. Bütünlük depo (repository) katmanında korunur, hız için
 * gereken indeksler ise eksiksiz tanımlanır.
 */
final class Blueprint
{
    /** @var list<string> */
    private array $columns = [];

    /** @var list<string> */
    private array $indexes = [];

    private ?string $current = null;

    private string $engine = 'InnoDB';

    private string $charset = 'utf8mb4';

    private string $collation = 'utf8mb4_unicode_ci';

    /** Otomatik artan birincil anahtar. */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = sprintf('`%s` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', self::clean($name));
        $this->current   = null;

        return $this;
    }

    public function string(string $name, int $length = 191): self
    {
        return $this->add($name, sprintf('VARCHAR(%d)', max(1, min(255, $length))));
    }

    /** Kısa ad / anahtar sütunları için 191 karakter (utf8mb4 indeks sınırı). */
    public function key(string $name): self
    {
        return $this->add($name, 'VARCHAR(191)');
    }

    public function text(string $name): self
    {
        return $this->add($name, 'TEXT');
    }

    public function longText(string $name): self
    {
        return $this->add($name, 'LONGTEXT');
    }

    /**
     * JSON verisi. MySQL 5.7 öncesi uyumluluğu için LONGTEXT kullanılır;
     * doğrulama uygulama katmanında yapılır.
     */
    public function json(string $name): self
    {
        return $this->add($name, 'LONGTEXT');
    }

    public function integer(string $name, bool $unsigned = false): self
    {
        return $this->add($name, 'INT' . ($unsigned ? ' UNSIGNED' : ''));
    }

    public function bigInteger(string $name, bool $unsigned = false): self
    {
        return $this->add($name, 'BIGINT' . ($unsigned ? ' UNSIGNED' : ''));
    }

    public function smallInteger(string $name): self
    {
        return $this->add($name, 'SMALLINT');
    }

    public function boolean(string $name): self
    {
        return $this->add($name, 'TINYINT(1)')->default(0);
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->add($name, sprintf('DECIMAL(%d,%d)', $precision, $scale));
    }

    public function timestamp(string $name): self
    {
        return $this->add($name, 'DATETIME');
    }

    /** created_at + updated_at ikilisi. */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();

        return $this;
    }

    public function ip(string $name = 'ip'): self
    {
        return $this->add($name, 'VARCHAR(45)');
    }

    /** Son eklenen sütunu NULL kabul eder hale getirir. */
    public function nullable(bool $nullable = true): self
    {
        if (!$nullable) {
            return $this;
        }

        return $this->modify(static fn(string $sql): string => str_replace(' NOT NULL', ' NULL', $sql));
    }

    public function default(mixed $value): self
    {
        $literal = match (true) {
            $value === null    => 'NULL',
            is_bool($value)    => $value ? '1' : '0',
            is_int($value),
            is_float($value)   => (string) $value,
            default            => "'" . str_replace("'", "''", (string) $value) . "'",
        };

        return $this->modify(static fn(string $sql): string => $sql . ' DEFAULT ' . $literal);
    }

    public function comment(string $text): self
    {
        $escaped = str_replace("'", "''", $text);

        return $this->modify(static fn(string $sql): string => $sql . " COMMENT '{$escaped}'");
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(array|string $columns, ?string $name = null): self
    {
        return $this->addIndex('INDEX', $columns, $name);
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(array|string $columns, ?string $name = null): self
    {
        return $this->addIndex('UNIQUE', $columns, $name);
    }

    /**
     * @param list<string> $columns
     */
    public function primary(array $columns): self
    {
        $quoted = array_map(static fn(string $c): string => '`' . self::clean($c) . '`', $columns);

        $this->indexes[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';

        return $this;
    }

    public function fullText(string $columns): self
    {
        return $this->addIndex('FULLTEXT', $columns, null);
    }

    /**
     * CREATE TABLE gövdesini üretir.
     */
    public function toSql(string $table): string
    {
        $lines = array_merge($this->columns, $this->indexes);

        return sprintf(
            "CREATE TABLE `%s` (\n  %s\n) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s",
            $table,
            implode(",\n  ", $lines),
            $this->engine,
            $this->charset,
            $this->collation
        );
    }

    /**
     * ALTER TABLE için sütun tanımlarını döndürür.
     *
     * @return list<string>
     */
    public function columnDefinitions(): array
    {
        return $this->columns;
    }

    /** @return list<string> */
    public function indexDefinitions(): array
    {
        return $this->indexes;
    }

    private function add(string $name, string $type): self
    {
        $name = self::clean($name);

        $this->columns[] = sprintf('`%s` %s NOT NULL', $name, $type);
        $this->current   = $name;

        return $this;
    }

    private function modify(callable $mutator): self
    {
        if ($this->columns === []) {
            return $this;
        }

        $lastIndex = count($this->columns) - 1;

        $this->columns[$lastIndex] = $mutator($this->columns[$lastIndex]);

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    private function addIndex(string $type, array|string $columns, ?string $name): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $clean   = array_map([self::class, 'clean'], $columns);
        $quoted  = array_map(static fn(string $c): string => '`' . $c . '`', $clean);

        $indexName = self::clean($name ?? implode('_', $clean) . '_idx');

        $this->indexes[] = match ($type) {
            'UNIQUE'   => sprintf('UNIQUE KEY `%s` (%s)', $indexName, implode(', ', $quoted)),
            'FULLTEXT' => sprintf('FULLTEXT KEY `%s` (%s)', $indexName, implode(', ', $quoted)),
            default    => sprintf('KEY `%s` (%s)', $indexName, implode(', ', $quoted)),
        };

        return $this;
    }

    private static function clean(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $value);
    }
}
