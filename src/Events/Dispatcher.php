<?php

declare(strict_types=1);

namespace HiCMS\Events;

/**
 * Olay dağıtıcısı.
 *
 * İki mekanizmayı birlikte sunar:
 *
 *  1. **Tipli olaylar** — asıl genişletme yolu. Dinleyici olay sınıfına
 *     bağlanır, IDE otomatik tamamlama çalışır, yazım hatası anında yakalanır:
 *
 *         $events->listen(Content\Saved::class, function (Content\Saved $e) {
 *             $e->entry->title;
 *         });
 *
 *  2. **Adlandırılmış kancalar** — arayüze içerik enjekte etmek gibi ince
 *     taneli noktalar için. Şablonlarda `hi_action()` / `hi_filter()` olarak
 *     görünür:
 *
 *         $events->on('admin.notices', fn() => print '<div>…</div>');
 *         $events->addFilter('content.title', fn(string $t) => strtoupper($t));
 *
 * Aynı öncelikte kaydedilen dinleyiciler kayıt sırasına göre çalışır.
 */
final class Dispatcher
{
    /** @var array<class-string, array<int, list<callable>>> */
    private array $listeners = [];

    /** @var array<string, array<int, list<callable>>> */
    private array $named = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * Tipli olaya dinleyici bağlar.
     *
     * @param class-string $eventClass
     */
    public function listen(string $eventClass, callable $listener, int $priority = 10): void
    {
        $this->listeners[$eventClass][$priority][] = $listener;
    }

    /**
     * Tipli olayı dağıtır ve olayın kendisini geri döndürür.
     *
     * @template T of Event
     * @param T $event
     * @return T
     */
    public function dispatch(Event $event): Event
    {
        $class = $event::class;
        $this->counts[$class] = ($this->counts[$class] ?? 0) + 1;

        foreach ($this->sorted($this->listeners[$class] ?? []) as $listener) {
            if ($event->isStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * Adlandırılmış kancaya dinleyici bağlar (eylem).
     */
    public function on(string $name, callable $listener, int $priority = 10): void
    {
        $this->named[$name][$priority][] = $listener;
    }

    /**
     * Adlandırılmış kancayı tetikler. Dönüş değeri yoktur.
     */
    public function emit(string $name, mixed ...$args): void
    {
        $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;

        foreach ($this->sorted($this->named[$name] ?? []) as $listener) {
            $listener(...$args);
        }
    }

    /**
     * Filtre bağlar. `on()` ile aynı depoyu kullanır; ayrım kullanımdadır.
     */
    public function addFilter(string $name, callable $listener, int $priority = 10): void
    {
        $this->named[$name][$priority][] = $listener;
    }

    /**
     * Değeri filtrelerden geçirir ve sonucu döndürür.
     */
    public function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($this->named[$name] ?? []) as $listener) {
            $value = $listener($value, ...$args);
        }

        return $value;
    }

    /**
     * Dinleyiciyi kaldırır.
     */
    public function forget(string $key, ?callable $listener = null, int $priority = 10): void
    {
        foreach (['listeners', 'named'] as $store) {
            if (!isset($this->{$store}[$key])) {
                continue;
            }

            if ($listener === null) {
                unset($this->{$store}[$key]);
                continue;
            }

            foreach ($this->{$store}[$key][$priority] ?? [] as $index => $registered) {
                if ($registered === $listener) {
                    unset($this->{$store}[$key][$priority][$index]);
                }
            }
        }
    }

    public function hasListeners(string $key): bool
    {
        foreach ($this->listeners[$key] ?? $this->named[$key] ?? [] as $group) {
            if ($group !== []) {
                return true;
            }
        }

        return false;
    }

    public function countOf(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /**
     * Kayıtlı tüm kanca adlarını döndürür (panelde tanılama için).
     *
     * @return array{events: list<string>, hooks: list<string>}
     */
    public function registry(): array
    {
        return [
            'events' => array_keys($this->listeners),
            'hooks'  => array_keys($this->named),
        ];
    }

    /**
     * @param array<int, list<callable>> $groups
     * @return list<callable>
     */
    private function sorted(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        ksort($groups, SORT_NUMERIC);

        return array_merge(...array_values($groups));
    }
}
