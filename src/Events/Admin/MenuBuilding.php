<?php

declare(strict_types=1);

namespace HiCMS\Events\Admin;

use HiCMS\Events\Event;

/**
 * Panel kenar menüsü kurulurken tetiklenir.
 *
 * Eklentiler kendi sayfalarını buraya ekler:
 *
 *     $e->add('content', [
 *         'slug'  => 'forms',
 *         'label' => 'Formlar',
 *         'icon'  => 'inbox',
 *         'url'   => 'plugin.php?eklenti=hi-forms',
 *     ]);
 */
final class MenuBuilding extends Event
{
    /** @param array<string, array{label: string, items: list<array<string, mixed>>}> $groups */
    public function __construct(public array $groups)
    {
    }

    /**
     * Bir gruba menü öğesi ekler. Grup yoksa oluşturulur.
     *
     * @param array<string, mixed> $item
     */
    public function add(string $group, array $item, ?string $groupLabel = null): void
    {
        if (!isset($this->groups[$group])) {
            $this->groups[$group] = ['label' => $groupLabel ?? ucfirst($group), 'items' => []];
        }

        $this->groups[$group]['items'][] = $item;
    }

    /**
     * Bir menü öğesini kaldırır.
     */
    public function remove(string $group, string $slug): void
    {
        if (!isset($this->groups[$group]['items'])) {
            return;
        }

        $this->groups[$group]['items'] = array_values(array_filter(
            $this->groups[$group]['items'],
            static fn(array $item): bool => ($item['slug'] ?? '') !== $slug
        ));
    }
}
