<?php

declare(strict_types=1);

namespace HiCMS\Events\System;

use HiCMS\Events\Event;
use HiCMS\Extension\Manifest;

/**
 * Etkin temanın functions.php dosyası yüklendikten sonra tetiklenir.
 *
 * Menü konumları, bileşen alanları ve içerik türleri bu aşamada kayıtlıdır.
 */
final class ThemeLoaded extends Event
{
    public function __construct(
        public readonly string $slug,
        public readonly Manifest $manifest,
    ) {
    }
}
