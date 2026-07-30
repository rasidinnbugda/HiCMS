<?php

declare(strict_types=1);

namespace HiCMS\Events\Media;

use HiCMS\Events\Event;
use HiCMS\Model\MediaItem;

/**
 * Dosya yüklendikten ve kaydı oluşturulduktan sonra tetiklenir.
 *
 * HiMedia eklentisi tam olarak buraya bağlanır: türev boyutları üretir,
 * WebP/AVIF kopyaları oluşturur ve sonuçları `$item->sizes` içine yazar.
 * Eklenti etkin değilse hiçbir şey olmaz ve tema tek dosyayı kullanır.
 */
final class Uploaded extends Event
{
    public function __construct(
        public MediaItem $item,
        public readonly string $absolutePath,
    ) {
    }
}
