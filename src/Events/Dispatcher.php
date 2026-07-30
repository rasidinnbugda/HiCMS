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
     * Kayıt sahipliği.
     *
     * Her dinleyici, kaydedildiği sırada etkin olan sahibin adıyla işaretlenir.
     * `asOwner()` içinde yapılan tüm kayıtlar o sahibe yazılır; `forgetOwner()`
     * yalnızca o sahibin kayıtlarını söker.
     *
     * NEDEN GEREKİYOR: bir eklenti devre dışı bırakıldığında kancalarının
     * sökülmesi gerekiyor. Tek araç `forget($key)` idi ve dinleyici
     * verilmediğinde `unset($store[$key])` yapıyor — yani o kancaya bağlı
     * BAŞKA eklentilerin ve çekirdeğin dinleyicilerini de siliyordu. Bir
     * eklentiyi kapatmak, `admin.notices` kancasını kullanan diğer her şeyi
     * sessizce susturuyordu.
     *
     * Kapanış (closure) karşılaştırmasıyla tek tek sökmek de işe yaramaz:
     * `$registered === $listener` aynı kapanış nesnesini gerektirir, eklenti
     * `boot()` içinde anonim fonksiyon kaydettiği için o nesneye bir daha
     * erişilemez.
     *
     * @var list<array{owner: string, store: string, key: string, priority: int, index: int}>
     */
    private array $owned = [];

    /** Kayıt sırasında etkin sahip yığını (iç içe boot çağrıları için). */
    private array $ownerStack = [];

    /**
     * Verilen sahip adına kayıt yapar.
     *
     * PluginManager eklentinin `boot()` çağrısını bununla sarar; böylece
     * eklentinin bağladığı her kanca kime ait olduğunu taşır.
     */
    public function asOwner(string $owner, callable $work): mixed
    {
        $this->ownerStack[] = $owner;

        try {
            return $work();
        } finally {
            array_pop($this->ownerStack);
        }
    }

    /** Şu an etkin sahip; yoksa boş dize (çekirdek kaydı). */
    private function owner(): string
    {
        return $this->ownerStack === [] ? '' : (string) end($this->ownerStack);
    }

    private function remember(string $store, string $key, int $priority, int $index): void
    {
        $owner = $this->owner();

        if ($owner === '') {
            return; // çekirdek kayıtları sökülmez
        }

        $this->owned[] = [
            'owner'    => $owner,
            'store'    => $store,
            'key'      => $key,
            'priority' => $priority,
            'index'    => $index,
        ];
    }

    /**
     * Bir sahibin TÜM kayıtlarını söker ve sökülen sayısını döndürür.
     *
     * Diğer sahiplerin aynı kancaya bağlı dinleyicilerine dokunulmaz.
     */
    public function forgetOwner(string $owner): int
    {
        $removed = 0;
        $keep    = [];

        foreach ($this->owned as $record) {
            if ($record['owner'] !== $owner) {
                $keep[] = $record;
                continue;
            }

            $store = $record['store'];

            if (isset($this->{$store}[$record['key']][$record['priority']][$record['index']])) {
                unset($this->{$store}[$record['key']][$record['priority']][$record['index']]);
                $removed++;
            }
        }

        $this->owned = $keep;

        return $removed;
    }

    /**
     * Tipli olaya dinleyici bağlar.
     *
     * @param class-string $eventClass
     */
    public function listen(string $eventClass, callable $listener, int $priority = 10): void
    {
        $this->listeners[$eventClass][$priority][] = $listener;
        $this->remember('listeners', $eventClass, $priority, array_key_last($this->listeners[$eventClass][$priority]));
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
        $this->remember('named', $name, $priority, array_key_last($this->named[$name][$priority]));
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
        $this->remember('named', $name, $priority, array_key_last($this->named[$name][$priority]));
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
     *
     * DİKKAT: `$listener` verilmezse o kancaya bağlı TÜM dinleyiciler silinir —
     * çekirdeğin ve diğer eklentilerin kayıtları dahil. Bir eklentinin kendi
     * kancalarını sökmesi için bu yöntem DEĞİL `forgetOwner()` kullanılır.
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
