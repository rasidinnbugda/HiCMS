<?php

declare(strict_types=1);

namespace HiCMS\Database;

/**
 * Hafif sorgu kurucu.
 *
 * Amaç bir ORM değil, depo (repository) sınıflarında SQL'i okunur tutmak:
 *
 *     $db->builder('content')
 *        ->where('type', 'post')
 *        ->where('status', 'published')
 *        ->orderBy('published_at', 'desc')
 *        ->paginate(10, 2);
 *
 * Sütun ve tablo adları asla dizge birleştirmeyle gelmez: `column()` yalnızca
 * harf, sayı, alt çizgi ve nokta kabul eder, değerler her zaman bağlanır.
 */
final class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<string> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $orders = [];

    /** @var list<string> */
    private array $groups = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private int $bindingIndex = 0;

    private string $alias = '';

    public function __construct(
        private readonly Connection $db,
        private readonly string $table,
    ) {
    }

    public function alias(string $alias): self
    {
        $this->alias = $this->identifier($alias);

        return $this;
    }

    /**
     * @param list<string>|string $columns
     */
    public function select(array|string $columns): self
    {
        $this->columns = is_array($columns) ? $columns : [$columns];

        return $this;
    }

    public function selectRaw(string $expression): self
    {
        $this->columns[] = $expression;

        return $this;
    }

    /**
     * where('status', 'published') veya where('views', '>', 100)
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        [$operator, $bound] = $value === null
            ? ['=', $operatorOrValue]
            : [(string) $operatorOrValue, $value];

        $allowed = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];
        $operator = strtoupper($operator);

        if (!in_array($operator, $allowed, true)) {
            $operator = '=';
        }

        $placeholder = $this->bind($bound);

        $this->wheres[] = sprintf('%s %s :%s', $this->column($column), $operator, $placeholder);

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, bool $negate = false): self
    {
        if ($values === []) {
            $this->wheres[] = $negate ? '1 = 1' : '1 = 0';

            return $this;
        }

        $placeholders = [];

        foreach ($values as $value) {
            $placeholders[] = ':' . $this->bind($value);
        }

        $this->wheres[] = sprintf(
            '%s %s (%s)',
            $this->column($column),
            $negate ? 'NOT IN' : 'IN',
            implode(', ', $placeholders)
        );

        return $this;
    }

    public function whereNull(string $column, bool $negate = false): self
    {
        $this->wheres[] = $this->column($column) . ($negate ? ' IS NOT NULL' : ' IS NULL');

        return $this;
    }

    public function whereLike(string $column, string $value): self
    {
        return $this->where($column, 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $value) . '%');
    }

    /**
     * Birden çok sütunda arama: (a LIKE ? OR b LIKE ?)
     *
     * @param list<string> $columns
     */
    public function whereAnyLike(array $columns, string $value): self
    {
        if ($columns === [] || $value === '') {
            return $this;
        }

        $term  = '%' . str_replace(['%', '_'], ['\%', '\_'], $value) . '%';
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = sprintf('%s LIKE :%s', $this->column($column), $this->bind($term));
        }

        $this->wheres[] = '(' . implode(' OR ', $parts) . ')';

        return $this;
    }

    /**
     * Bağlamalı ham koşul. Sütun adı kod içinde sabit olmalıdır.
     *
     * @param array<string, mixed> $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): self
    {
        $this->wheres[] = '(' . $expression . ')';

        foreach ($bindings as $key => $value) {
            $this->bindings[$key] = $value;
        }

        return $this;
    }

    /**
     * Yayında ve yayın tarihi gelmiş kayıtlar (sık kullanılan kısayol).
     */
    public function whereVisible(): self
    {
        $this->wheres[] = sprintf(
            "(%s = 'published' AND (%s IS NULL OR %s <= :now_visible))",
            $this->column('status'),
            $this->column('published_at'),
            $this->column('published_at')
        );

        $this->bindings['now_visible'] = date('Y-m-d H:i:s');

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper($type) === 'LEFT' ? 'LEFT' : 'INNER';

        $this->joins[] = sprintf(
            '%s JOIN `%s` ON %s %s %s',
            $type,
            $this->db->t($table),
            $this->column($first),
            $operator === '=' ? '=' : '=',
            $this->column($second)
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = $this->column($column) . ' ' . (strtolower($direction) === 'desc' ? 'DESC' : 'ASC');

        return $this;
    }

    /** Sabit ifadeler için (örn. FIELD(status, …)). */
    public function orderByRaw(string $expression): self
    {
        $this->orders[] = $expression;

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groups[] = $this->column($column);

        return $this;
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit !== null && $limit > 0 ? $limit : null;

        return $this;
    }

    public function offset(?int $offset): self
    {
        $this->offset = $offset !== null && $offset > 0 ? $offset : null;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->db->select($this->toSql(), $this->bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->limit(1);

        return $this->db->selectOne($this->toSql(), $this->bindings);
    }

    public function value(string $column): mixed
    {
        $this->columns = [$this->column($column)];
        $this->limit(1);

        return $this->db->scalar($this->toSql(), $this->bindings);
    }

    /**
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $this->columns = [$this->column($column)];

        $values = [];

        foreach ($this->get() as $row) {
            $values[] = reset($row);
        }

        return $values;
    }

    public function count(string $column = '*'): int
    {
        $clone          = clone $this;
        $clone->columns = ['COUNT(' . ($column === '*' ? '*' : $this->column($column)) . ') AS aggregate'];
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = null;

        return (int) $this->db->scalar($clone->toSql(), $clone->bindings);
    }

    public function sum(string $column): float
    {
        $clone          = clone $this;
        $clone->columns = ['COALESCE(SUM(' . $this->column($column) . '), 0) AS aggregate'];
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = null;

        return (float) $this->db->scalar($clone->toSql(), $clone->bindings);
    }

    public function max(string $column): mixed
    {
        $clone          = clone $this;
        $clone->columns = ['MAX(' . $this->column($column) . ') AS aggregate'];
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = null;

        return $this->db->scalar($clone->toSql(), $clone->bindings);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Sayfalanmış sonuç.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function paginate(int $perPage, int $page = 1): array
    {
        $perPage = max(1, $perPage);
        $page    = max(1, $page);
        $total   = $this->count();
        $pages   = max(1, (int) ceil($total / $perPage));

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /**
     * Geçerli koşullara uyan satırları güncellemek için.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($data === [] || $this->wheres === []) {
            return 0;
        }

        $set = [];

        foreach ($data as $column => $value) {
            $key                 = 'upd_' . $column;
            $set[]               = sprintf('`%s` = :%s', $this->identifier($column), $key);
            $this->bindings[$key] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->db->t($this->table),
            implode(', ', $set),
            implode(' AND ', $this->wheres)
        );

        return $this->db->statement($sql, $this->bindings);
    }

    public function delete(): int
    {
        if ($this->wheres === []) {
            return 0;
        }

        $sql = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $this->db->t($this->table),
            implode(' AND ', $this->wheres)
        );

        return $this->db->statement($sql, $this->bindings);
    }

    public function toSql(): string
    {
        $from = sprintf('`%s`', $this->db->t($this->table));

        if ($this->alias !== '') {
            $from .= ' AS `' . $this->alias . '`';
        }

        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $from;

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /** @return array<string, mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function bind(mixed $value): string
    {
        $key = 'b' . (++$this->bindingIndex);

        $this->bindings[$key] = $value;

        return $key;
    }

    /**
     * Sütun adını güvenli biçimde alıntılar. `tablo.sutun` ve `*` desteklenir;
     * tablo adı geçerse önek uygulanır.
     */
    private function column(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        if (!str_contains($column, '.')) {
            return '`' . $this->identifier($column) . '`';
        }

        [$table, $field] = explode('.', $column, 2);

        $table = $this->identifier($table);
        $field = $field === '*' ? '*' : '`' . $this->identifier($field) . '`';

        // Takma ad değilse tablo önekini uygula.
        $quoted = $table === $this->alias ? $table : $this->db->t($table);

        return '`' . $quoted . '`.' . $field;
    }

    private function identifier(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $value);
    }
}
