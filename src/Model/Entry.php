<?php

declare(strict_types=1);

namespace HiCMS\Model;

use HiCMS\Support\Str;

/**
 * Bir içerik kaydı: yazı, sayfa veya eklenti/temanın tanımladığı özel tür.
 *
 * İçerik gövdesi HTML yığını değil, sıralı **blok dizisidir** (`$blocks`).
 * Her blok `['type' => 'paragraph', 'data' => [...]]` biçimindedir; nasıl
 * basılacağına BlockRegistry karar verir. Bu sayede aynı içerik hem HTML'e
 * hem JSON API'ye aynı kaynaktan servis edilebilir.
 */
final class Entry
{
    public int $id = 0;
    public string $type = 'post';
    public string $status = 'draft';
    public string $title = '';
    public string $slug = '';
    public string $excerpt = '';

    /** @var list<array{type: string, data: array<string, mixed>}> */
    public array $blocks = [];

    public int $authorId = 0;
    public int $parentId = 0;
    public string $template = '';
    public bool $featured = false;
    public int $mediaId = 0;
    public ?string $publishedAt = null;
    public string $createdAt = '';
    public string $updatedAt = '';
    public int $views = 0;
    public bool $commentsOpen = true;
    public int $position = 0;

    /** @var array<string, mixed> content_meta tablosundan gelen özel alanlar */
    public array $meta = [];

    /** @var list<Term> */
    public array $terms = [];

    /** Öne çıkan görsel — depo tarafından doldurulur. */
    public ?MediaItem $image = null;

    /** Yazar — depo tarafından doldurulur. */
    public ?User $author = null;

    public int $commentCount = 0;

    /**
     * Veritabanı satırından kurar.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $entry = new self();

        $entry->id           = (int) ($row['id'] ?? 0);
        $entry->type         = (string) ($row['type'] ?? 'post');
        $entry->status       = (string) ($row['status'] ?? 'draft');
        $entry->title        = (string) ($row['title'] ?? '');
        $entry->slug         = (string) ($row['slug'] ?? '');
        $entry->excerpt      = (string) ($row['excerpt'] ?? '');
        $entry->authorId     = (int) ($row['author_id'] ?? 0);
        $entry->parentId     = (int) ($row['parent_id'] ?? 0);
        $entry->template     = (string) ($row['template'] ?? '');
        $entry->featured     = (bool) ($row['featured'] ?? false);
        $entry->mediaId      = (int) ($row['media_id'] ?? 0);
        $entry->publishedAt  = $row['published_at'] ?? null;
        $entry->createdAt    = (string) ($row['created_at'] ?? '');
        $entry->updatedAt    = (string) ($row['updated_at'] ?? '');
        $entry->views        = (int) ($row['views'] ?? 0);
        $entry->commentsOpen = (bool) ($row['comments_open'] ?? true);
        $entry->position     = (int) ($row['position'] ?? 0);

        $blocks = json_decode((string) ($row['blocks'] ?? '[]'), true);
        $entry->blocks = is_array($blocks) ? self::normalizeBlocks($blocks) : [];

        return $entry;
    }

    /**
     * Veritabanına yazılacak satırı üretir.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'type'          => $this->type,
            'status'        => $this->status,
            'title'         => $this->title,
            'slug'          => $this->slug,
            'excerpt'       => $this->excerpt,
            'blocks'        => (string) json_encode($this->blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'author_id'     => $this->authorId,
            'parent_id'     => $this->parentId,
            'template'      => $this->template,
            'featured'      => $this->featured ? 1 : 0,
            'media_id'      => $this->mediaId,
            'published_at'  => $this->publishedAt,
            'views'         => $this->views,
            'comments_open' => $this->commentsOpen ? 1 : 0,
            'position'      => $this->position,
        ];
    }

    /**
     * Blok dizisini beklenen şekle indirger; bozuk girdileri atar.
     *
     * @param array<mixed> $blocks
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function normalizeBlocks(array $blocks): array
    {
        $clean = [];

        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                continue;
            }

            $clean[] = [
                'type' => $block['type'],
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
            ];
        }

        return $clean;
    }

    public function isPublished(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        if ($this->publishedAt === null || $this->publishedAt === '') {
            return true;
        }

        return strtotime($this->publishedAt) <= time();
    }

    /** İleri tarihli yayın bekliyor mu? */
    public function isScheduled(): bool
    {
        return $this->status === 'published'
            && $this->publishedAt !== null
            && strtotime((string) $this->publishedAt) > time();
    }

    /**
     * Özet yoksa bloklardan üretir.
     */
    public function summary(int $length = 180): string
    {
        if (trim($this->excerpt) !== '') {
            return Str::limit($this->excerpt, $length);
        }

        return Str::limit($this->plainText(), $length);
    }

    /**
     * Blokların düz metin karşılığı — özet, arama ve okuma süresi için.
     */
    public function plainText(): string
    {
        $parts = [];

        foreach ($this->blocks as $block) {
            foreach (['text', 'html', 'caption', 'code', 'title', 'quote'] as $key) {
                if (isset($block['data'][$key]) && is_string($block['data'][$key])) {
                    $parts[] = strip_tags($block['data'][$key]);
                }
            }

            if (isset($block['data']['items']) && is_array($block['data']['items'])) {
                foreach ($block['data']['items'] as $item) {
                    if (is_string($item)) {
                        $parts[] = strip_tags($item);
                    }
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    public function readingTime(): int
    {
        return Str::readingTime($this->plainText());
    }

    /** @return mixed */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Belirli bir taksonomiye ait terimleri döndürür.
     *
     * @return list<Term>
     */
    public function termsIn(string $taxonomy): array
    {
        return array_values(array_filter(
            $this->terms,
            static fn(Term $term): bool => $term->taxonomy === $taxonomy
        ));
    }

    public function primaryTerm(string $taxonomy = 'category'): ?Term
    {
        return $this->termsIn($taxonomy)[0] ?? null;
    }
}
