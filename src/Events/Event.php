<?php

declare(strict_types=1);

namespace HiCMS\Events;

/**
 * Tüm tipli olayların temeli.
 *
 * Olaylar veri taşıyıcıdır: dinleyiciler olayın genel (public) alanlarını
 * okuyup değiştirebilir. Bir dinleyici zincirin devamını durdurmak isterse
 * `stop()` çağırır.
 */
abstract class Event
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Olayın adı — günlükleme ve tanılama için.
     */
    public function name(): string
    {
        return static::class;
    }
}
