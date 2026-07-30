<?php

declare(strict_types=1);

namespace HiCMS\Events\Content;

use HiCMS\Events\Event;
use HiCMS\Model\Entry;

/**
 * İçerik başarıyla kaydedildikten sonra tetiklenir.
 *
 * Önbellek temizleme, sitemap yenileme, bildirim gönderme gibi işler buraya
 * bağlanır. `$statusChangedTo` yalnızca durum değiştiyse doludur — böylece
 * "ilk kez yayınlandı" gibi durumlar ayırt edilebilir.
 */
final class Saved extends Event
{
    public function __construct(
        public readonly Entry $entry,
        public readonly bool $isNew,
        public readonly ?string $statusChangedTo = null,
    ) {
    }

    public function justPublished(): bool
    {
        return $this->statusChangedTo === 'published';
    }
}
