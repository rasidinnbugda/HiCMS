<?php

declare(strict_types=1);

namespace HiCMS\Events\System;

use HiCMS\Events\Event;

/**
 * Etkin eklentilerin tamamı yüklendiğinde tetiklenir; tema henüz yüklenmedi.
 *
 * Bir eklenti başka bir eklentinin varlığına bakacaksa burası doğru yerdir.
 */
final class PluginsLoaded extends Event
{
    /** @param list<string> $active Etkin eklenti kısa adları */
    public function __construct(public readonly array $active)
    {
    }

    public function isActive(string $slug): bool
    {
        return in_array($slug, $this->active, true);
    }
}
