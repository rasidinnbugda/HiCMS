<?php

declare(strict_types=1);

namespace HiCMS\Model;

/**
 * Taksonomi terimi: kategori, etiket veya eklentinin tanımladığı bir taksonomi.
 */
final class Term
{
    public int $id = 0;
    public string $taxonomy = 'category';
    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public string $color = '';
    public int $parentId = 0;
    public int $position = 0;
    public int $count = 0;

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $term = new self();

        $term->id          = (int) ($row['id'] ?? 0);
        $term->taxonomy    = (string) ($row['taxonomy'] ?? 'category');
        $term->name        = (string) ($row['name'] ?? '');
        $term->slug        = (string) ($row['slug'] ?? '');
        $term->description = (string) ($row['description'] ?? '');
        $term->color       = (string) ($row['color'] ?? '');
        $term->parentId    = (int) ($row['parent_id'] ?? 0);
        $term->position    = (int) ($row['position'] ?? 0);
        $term->count       = (int) ($row['entry_count'] ?? $row['count'] ?? 0);

        return $term;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'taxonomy'    => $this->taxonomy,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'color'       => $this->color,
            'parent_id'   => $this->parentId,
            'position'    => $this->position,
        ];
    }
}
