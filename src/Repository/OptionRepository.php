<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;

/**
 * Site ayarları.
 *
 * `autoload` işaretli ayarlar ilk erişimde tek sorguyla belleğe alınır; geri
 * kalanlar istendiğinde okunur. Değerler JSON olarak saklanır, böylece dizi ve
 * bool değerler tür kaybetmeden geri gelir.
 */
final class OptionRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @var array<string, mixed> Autoload olmayan, tek tek okunmuş ayarlar */
    private array $lazy = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Autoload ayarlarını belleğe alır.
     */
    private function warm(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = [];

        if (!$this->db->tableExists('options')) {
            return;
        }

        foreach ($this->db->builder('options')->where('autoload', 1)->get() as $row) {
            $this->cache[(string) $row['name']] = self::decode($row['value']);
        }
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $this->warm();

        if (array_key_exists($name, (array) $this->cache)) {
            return $this->cache[$name];
        }

        if (array_key_exists($name, $this->lazy)) {
            return $this->lazy[$name];
        }

        if (!$this->db->tableExists('options')) {
            return $default;
        }

        $row = $this->db->builder('options')->where('name', $name)->first();

        if ($row === null) {
            return $this->lazy[$name] = $default;
        }

        return $this->lazy[$name] = self::decode($row['value']);
    }

    public function has(string $name): bool
    {
        return $this->get($name, '__missing__') !== '__missing__';
    }

    public function set(string $name, mixed $value, bool $autoload = true): bool
    {
        $encoded = self::encode($value);

        $exists = $this->db->builder('options')->where('name', $name)->exists();

        if ($exists) {
            $this->db->update('options', ['value' => $encoded, 'autoload' => $autoload ? 1 : 0], ['name' => $name]);
        } else {
            $this->db->insert('options', [
                'name'     => $name,
                'value'    => $encoded,
                'autoload' => $autoload ? 1 : 0,
            ]);
        }

        if ($autoload) {
            $this->warm();
            $this->cache[$name] = $value;
            unset($this->lazy[$name]);
        } else {
            $this->lazy[$name] = $value;
            if ($this->cache !== null) {
                unset($this->cache[$name]);
            }
        }

        return true;
    }

    /**
     * Birden çok ayarı tek seferde yazar.
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, bool $autoload = true): void
    {
        $this->db->transaction(function () use ($values, $autoload): void {
            foreach ($values as $name => $value) {
                $this->set($name, $value, $autoload);
            }
        });
    }

    public function delete(string $name): bool
    {
        $this->db->delete('options', ['name' => $name]);

        if ($this->cache !== null) {
            unset($this->cache[$name]);
        }

        unset($this->lazy[$name]);

        return true;
    }

    /**
     * Tüm ayarları döndürür (yedekleme ve tanılama için).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach ($this->db->builder('options')->orderBy('name')->get() as $row) {
            $values[(string) $row['name']] = self::decode($row['value']);
        }

        return $values;
    }

    public function flush(): void
    {
        $this->cache = null;
        $this->lazy  = [];
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        // Eski/elle girilmiş düz metin değerleri de çalışsın.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $raw;
    }
}
