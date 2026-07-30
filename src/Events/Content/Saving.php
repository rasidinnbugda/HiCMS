<?php

declare(strict_types=1);

namespace HiCMS\Events\Content;

use HiCMS\Events\Event;
use HiCMS\Model\Entry;

/**
 * İçerik veritabanına yazılmadan hemen önce tetiklenir.
 *
 * Dinleyiciler `$entry` üzerinde değişiklik yapabilir (kısa ad üretmek, alan
 * doğrulamak, blokları temizlemek). Kaydı iptal etmek için `cancel()` çağırın.
 */
final class Saving extends Event
{
    private bool $cancelled = false;

    private string $reason = '';

    public function __construct(
        public Entry $entry,
        public readonly bool $isNew,
    ) {
    }

    public function cancel(string $reason = ''): void
    {
        $this->cancelled = true;
        $this->reason    = $reason;
        $this->stop();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
