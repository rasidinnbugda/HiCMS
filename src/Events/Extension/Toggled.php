<?php

declare(strict_types=1);

namespace HiCMS\Events\Extension;

use HiCMS\Events\Event;

/**
 * Bir eklenti etkinleştirildiğinde/devre dışı bırakıldığında veya tema
 * değiştirildiğinde tetiklenir.
 *
 * Eklentiler kendi kurulum/temizlik işlerini burada yapar (tablo oluşturma,
 * varsayılan ayar yazma). `$kind` değeri `plugin` veya `theme`'dir.
 */
final class Toggled extends Event
{
    public function __construct(
        public readonly string $kind,
        public readonly string $slug,
        public readonly bool $enabled,
        public readonly string $previous = '',
    ) {
    }

    public function isPlugin(): bool
    {
        return $this->kind === 'plugin';
    }

    public function isTheme(): bool
    {
        return $this->kind === 'theme';
    }

    /** Verilen eklenti bu olayın konusu mu ve etkinleştirildi mi? */
    public function activated(string $slug): bool
    {
        return $this->enabled && $this->slug === $slug;
    }

    public function deactivated(string $slug): bool
    {
        return !$this->enabled && $this->slug === $slug;
    }
}
