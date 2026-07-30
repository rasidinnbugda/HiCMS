<?php

declare(strict_types=1);

namespace HiCMS;

use Closure;
use RuntimeException;

/**
 * Servis konteyneri.
 *
 * Tembel (lazy) kurulum yapar: bir servis ilk kez istendiğinde üretilir ve
 * varsayılan olarak tekil (singleton) tutulur. Böylece her istekte yalnızca
 * gerçekten kullanılan servisler kurulur — WordPress'in her şeyi baştan
 * yüklemesinden temel farkı budur.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> Her çağrıda yeniden üretilecek servisler */
    private array $transient = [];

    /** @var array<string, true> Döngüsel bağımlılık tespiti için */
    private array $resolving = [];

    /**
     * Tekil servis tanımlar.
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Her çağrıda yeni örnek üreten servis tanımlar.
     */
    public function factory(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        $this->transient[$id]  = true;
        unset($this->instances[$id]);
    }

    /**
     * Hazır bir örneği doğrudan kaydeder.
     */
    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    /**
     * Servisi döndürür.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Konteynerde tanımlı olmayan servis istendi: {$id}");
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException("Döngüsel bağımlılık: {$id}");
        }

        $this->resolving[$id] = true;

        try {
            $instance = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }

        if (!isset($this->transient[$id])) {
            $this->instances[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Tanımlı servis kimliklerini döndürür (tanılama için).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->factories),
            array_keys($this->instances)
        )));
    }
}
