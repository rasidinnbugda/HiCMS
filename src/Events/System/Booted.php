<?php

declare(strict_types=1);

namespace HiCMS\Events\System;

use HiCMS\Events\Event;
use HiCMS\Kernel;

/**
 * Çekirdek tamamen hazır olduğunda tetiklenir: yapılandırma okundu,
 * veritabanı bağlandı, eklentiler ve tema yüklendi.
 *
 * Eklentiler burada rota, panel sayfası veya planlı görev kaydeder.
 */
final class Booted extends Event
{
    public function __construct(public readonly Kernel $kernel)
    {
    }
}
