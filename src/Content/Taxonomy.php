<?php

declare(strict_types=1);

namespace HiCMS\Content;

use HiCMS\Support\Str;

/**
 * Taksonomi tanımı: kategori, etiket veya eklentinin eklediği bir gruplama.
 *
 * `single = true` olan taksonomilerde içerik yalnızca bir terime bağlanabilir
 * (kategori gibi); `false` ise çoklu seçim yapılır (etiket gibi).
 */
final class Taxonomy
{
    public function __construct(
        public readonly string $name,
        public readonly string $singular,
        public readonly string $plural,
        public readonly string $route = '',
        public readonly bool $hierarchical = false,
        public readonly bool $hasColor = false,
        public readonly bool $single = false,
        public readonly bool $isPublic = true,
        public readonly string $description = '',
        public readonly string $source = 'core',
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition, string $source = 'core'): self
    {
        $name = Str::slug((string) ($definition['name'] ?? ''), '_');

        if ($name === '') {
            $name = 'terim';
        }

        $labels = (array) ($definition['labels'] ?? []);

        return new self(
            name: $name,
            singular: (string) ($labels['singular'] ?? ucfirst($name)),
            plural: (string) ($labels['plural'] ?? ($labels['singular'] ?? ucfirst($name))),
            route: Str::slug((string) ($definition['route'] ?? $name)),
            hierarchical: (bool) ($definition['hierarchical'] ?? false),
            hasColor: (bool) ($definition['color'] ?? false),
            single: (bool) ($definition['single'] ?? false),
            isPublic: (bool) ($definition['public'] ?? true),
            description: (string) ($definition['description'] ?? ''),
            source: $source,
        );
    }

    public function adminUrl(): string
    {
        return 'terms.php?taksonomi=' . rawurlencode($this->name);
    }
}
