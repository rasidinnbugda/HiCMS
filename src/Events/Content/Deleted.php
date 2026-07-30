<?php

declare(strict_types=1);

namespace HiCMS\Events\Content;

use HiCMS\Events\Event;

/**
 * İçerik kalıcı olarak silindikten sonra tetiklenir.
 *
 * İlişkili veriler (özel alanlar, yorumlar, terim bağları) çekirdek tarafından
 * zaten temizlenmiştir; eklentiler kendi tablolarını burada temizler.
 */
final class Deleted extends Event
{
    public function __construct(
        public readonly int $entryId,
        public readonly string $type,
        public readonly string $slug,
    ) {
    }
}
