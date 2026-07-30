<?php

declare(strict_types=1);

namespace HiCMS\Events\Render;

use HiCMS\Events\Event;

/**
 * Bir blok HTML'e çevrildikten sonra tetiklenir.
 *
 * Eklentiler çıktıyı sarmalayabilir veya tamamen değiştirebilir — örneğin kod
 * bloğuna sözdizimi renklendirmesi eklemek ya da görsel bloğuna lightbox
 * bağlamak için.
 */
final class BlockRendering extends Event
{
    /** @param array{type: string, data: array<string, mixed>} $block */
    public function __construct(
        public string $html,
        public readonly array $block,
        public readonly int $index,
    ) {
    }

    public function type(): string
    {
        return $this->block['type'];
    }

    public function wrap(string $before, string $after = ''): void
    {
        $this->html = $before . $this->html . $after;
    }
}
