<?php

declare(strict_types=1);

namespace HiCMS\Database;

/**
 * Şema işlemleri. Migration dosyaları yalnızca bu sınıfla konuşur, böylece
 * SQL lehçesi tek yerde kalır.
 */
final class Schema
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Tablo oluşturur. Tablo varsa hiçbir şey yapmaz.
     */
    public function create(string $table, callable $definition): void
    {
        if ($this->hasTable($table)) {
            return;
        }

        $blueprint = new Blueprint();
        $definition($blueprint);

        $this->db->exec($blueprint->toSql($this->db->t($table)));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->db->t($table)));
    }

    public function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && $this->db->columnExists($table, $column);
    }

    /**
     * Var olan tabloya sütun ekler. Zaten varsa atlanır.
     */
    public function addColumns(string $table, callable $definition): void
    {
        if (!$this->hasTable($table)) {
            return;
        }

        $blueprint = new Blueprint();
        $definition($blueprint);

        foreach ($blueprint->columnDefinitions() as $sql) {
            if (preg_match('/^`([^`]+)`/', $sql, $match) !== 1) {
                continue;
            }

            if ($this->db->columnExists($table, $match[1])) {
                continue;
            }

            $this->db->exec(sprintf('ALTER TABLE `%s` ADD COLUMN %s', $this->db->t($table), $sql));
        }

        foreach ($blueprint->indexDefinitions() as $sql) {
            $this->db->exec(sprintf('ALTER TABLE `%s` ADD %s', $this->db->t($table), $sql));
        }
    }

    public function dropColumn(string $table, string $column): void
    {
        if (!$this->hasColumn($table, $column)) {
            return;
        }

        $column = (string) preg_replace('/[^A-Za-z0-9_]/', '', $column);

        $this->db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $this->db->t($table), $column));
    }

    /**
     * @param string|list<string> $columns
     */
    public function addIndex(string $table, array|string $columns, bool $unique = false, ?string $name = null): void
    {
        if (!$this->hasTable($table)) {
            return;
        }

        $columns = is_array($columns) ? $columns : [$columns];
        $clean   = array_map(
            static fn(string $c): string => (string) preg_replace('/[^A-Za-z0-9_]/', '', $c),
            $columns
        );

        $indexName = (string) preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            $name ?? implode('_', $clean) . '_idx'
        );

        $existing = $this->db->select(sprintf('SHOW INDEX FROM `%s`', $this->db->t($table)));

        foreach ($existing as $row) {
            if (($row['Key_name'] ?? '') === $indexName) {
                return;
            }
        }

        $quoted = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $clean));

        $this->db->exec(sprintf(
            'ALTER TABLE `%s` ADD %s `%s` (%s)',
            $this->db->t($table),
            $unique ? 'UNIQUE KEY' : 'KEY',
            $indexName,
            $quoted
        ));
    }

    public function truncate(string $table): void
    {
        if ($this->hasTable($table)) {
            $this->db->exec(sprintf('TRUNCATE TABLE `%s`', $this->db->t($table)));
        }
    }

    public function connection(): Connection
    {
        return $this->db;
    }
}
