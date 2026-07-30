<?php

declare(strict_types=1);

namespace HiCMS\Events\Render;

use HiCMS\Events\Event;

/**
 * Şablon hiyerarşisi belirlendikten sonra, dosya seçilmeden önce tetiklenir.
 *
 * Eklentiler aday listesine kendi şablonunu ekleyerek belirli bir sayfanın
 * görünümünü devralabilir:
 *
 *     $e->prepend('single-kampanya.php');
 */
final class TemplateResolving extends Event
{
    /** @param list<string> $candidates */
    public function __construct(
        public array $candidates,
        public readonly string $context,
    ) {
    }

    public function prepend(string $template): void
    {
        array_unshift($this->candidates, $template);
    }

    public function append(string $template): void
    {
        $this->candidates[] = $template;
    }
}
