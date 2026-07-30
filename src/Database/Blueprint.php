<?php

declare(strict_types=1);

namespace HiCMS\Database;

/**
 * Tablo tanımı kurucu.
 *
 * Sütunlar dizge birleştirmeyle değil **yapısal olarak** tutulur (ad, tür,
 * null'lanabilirlik, varsayılan, açıklama) ve SQL yalnızca `toSql()` anında
 * üretilir. Bunun sebebi somut bir hata: dizge eklemeli yaklaşımda `boolean()`
 * gibi kendi varsayılanını uygulayan bir yardımcının ardından `default()`
 * çağrılınca `DEFAULT 0 DEFAULT 1` gibi geçersiz SQL oluşuyordu. Yapısal tutunca
 * hangi sırayla çağrıldığından bağımsız olarak her yan tümce en fazla bir kez
 * basılır.
 *
 * Yabancı anahtar kısıtı bilinçli olarak kullanılmaz: paylaşımlı hostinglerde
 * eski MySQL sürümleri ve karışık depolama motorları yüzünden migration'lar
 * kırılganlaşıyor. Bütünlük depo (repository) katmanında korunur, hız için
 * gereken indeksler ise eksiksiz tanımlanır.
 */
final class Blueprint
{
    /**
     * @var list<array{
     *     name: string, type: string, nullable: bool, hasDefault: bool,
     *     default: mixed, comment: string, extra: string
     * }>
     */
    private array $columns = [];

    /** @var list<string> */
    private array $indexes = [];

    private string $engine = 'InnoDB';

    private string $charset = 'utf8mb4';

    private string $collation = 'utf8mb4_unicode_ci';

    /** Otomatik artan birincil anahtar. */
    public function id(string $name = 'id'): self
    {
        return $this->add($name, 'INT UNSIGNED', 'AUTO_INCREMENT PRIMARY KEY');
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

    /**
     * Bool sütunu. Varsayılan uygulanmaz — çağıran `default()` ile açıkça
     * belirtir. (Eskiden burada örtük `default(0)` vardı ve ardından gelen
     * `default()` çağrısı geçersiz SQL üretiyordu.)
     */
    public function boolean(string $name): self
    {
        return $this->add($name, 'TINYINT(1)');
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

    /* ---------------------------------------------------------------------
     * Değiştiriciler — son eklenen sütuna uygulanır, çağrı sırası önemsizdir
     * ------------------------------------------------------------------ */

    public function nullable(bool $nullable = true): self
    {
        if ($this->columns !== []) {
            $this->columns[count($this->columns) - 1]['nullable'] = $nullable;
        }

        return $this;
    }

    /**
     * Varsayılan değer. Aynı sütunda ikinci kez çağrılırsa öncekini **değiştirir**,
     * yan yana iki DEFAULT yan tümcesi üretmez.
     */
    public function default(mixed $value): self
    {
        if ($this->columns !== []) {
            $index = count($this->columns) - 1;

            $this->columns[$index]['hasDefault'] = true;
            $this->columns[$index]['default']    = $value;
        }

        return $this;
    }

    public function comment(string $text): self
    {
        if ($this->columns !== []) {
            $this->columns[count($this->columns) - 1]['comment'] = $text;
        }

        return $this;
    }

    /* ---------------------------------------------------------------------
     * İndeksler
     * ------------------------------------------------------------------ */

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

    /* ---------------------------------------------------------------------
     * Üretim
     * ------------------------------------------------------------------ */

    /**
     * CREATE TABLE ifadesini üretir.
     */
    public function toSql(string $table): string
    {
        $lines = array_merge($this->columnDefinitions(), $this->indexes);

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
     * Sütun tanımlarını SQL parçası olarak döndürür (ALTER TABLE de kullanır).
     *
     * @return list<string>
     */
    public function columnDefinitions(): array
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $sql = sprintf('`%s` %s', $column['name'], $column['type']);
            $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';

            if ($column['hasDefault']) {
                $sql .= ' DEFAULT ' . self::literal($column['default']);
            }

            if ($column['extra'] !== '') {
                $sql .= ' ' . $column['extra'];
            }

            if ($column['comment'] !== '') {
                $sql .= " COMMENT '" . str_replace("'", "''", $column['comment']) . "'";
            }

            $definitions[] = $sql;
        }

        return $definitions;
    }

    /** @return list<string> */
    public function indexDefinitions(): array
    {
        return $this->indexes;
    }

    /**
     * Tanımlı sütun adları — denetim ve test için.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(static fn(array $column): string => $column['name'], $this->columns);
    }

    /* ---------------------------------------------------------------------
     * İç yardımcılar
     * ------------------------------------------------------------------ */

    private function add(string $name, string $type, string $extra = ''): self
    {
        $this->columns[] = [
            'name'       => self::clean($name),
            'type'       => $type,
            'nullable'   => false,
            'hasDefault' => false,
            'default'    => null,
            'comment'    => '',
            'extra'      => $extra,
        ];

        return $this;
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            $value === null  => 'NULL',
            is_bool($value)  => $value ? '1' : '0',
            is_int($value),
            is_float($value) => (string) $value,
            default          => "'" . str_replace("'", "''", (string) $value) . "'",
        };
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
