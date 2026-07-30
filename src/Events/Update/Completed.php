<?php

declare(strict_types=1);

namespace HiCMS\Events\Update;

use HiCMS\Events\Event;

/**
 * Çekirdek, tema veya eklenti güncellemesi tamamlandığında tetiklenir.
 *
 * Eklentiler kendi veri dönüşümlerini (migration sonrası düzeltmeler,
 * önbellek temizliği) buraya bağlar.
 */
final class Completed extends Event
{
    public function __construct(
        public readonly string $kind,
        public readonly string $slug,
        public readonly string $fromVersion,
        public readonly string $toVersion,
    ) {
    }

    public function isCore(): bool
    {
        return $this->kind === 'core';
    }
}
