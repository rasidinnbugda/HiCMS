<?php

declare(strict_types=1);

namespace HiCMS\Model;

/**
 * Medya kitaplığı kaydı.
 *
 * `sizes` alanı, üretilmiş türevleri tutar:
 *   ['medium' => ['file' => '…-800.webp', 'width' => 800, 'height' => 450], …]
 *
 * Çekirdek yalnızca kaydı ve srcset basmayı bilir; türevleri üretmek HiMedia
 * eklentisinin işidir. Eklenti yoksa `sizes` boş kalır ve tema tek dosyayı
 * kullanır — tema kodu her iki durumda da aynıdır.
 */
final class MediaItem
{
    public int $id = 0;
    public string $filename = '';
    public string $path = '';
    public string $mime = '';
    public int $size = 0;
    public int $width = 0;
    public int $height = 0;
    public string $alt = '';
    public string $title = '';
    public int $authorId = 0;
    public string $createdAt = '';

    /** @var array<string, array{file: string, width: int, height: int}> */
    public array $sizes = [];

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $item = new self();

        $item->id        = (int) ($row['id'] ?? 0);
        $item->filename  = (string) ($row['filename'] ?? '');
        $item->path      = (string) ($row['path'] ?? '');
        $item->mime      = (string) ($row['mime'] ?? '');
        $item->size      = (int) ($row['size'] ?? 0);
        $item->width     = (int) ($row['width'] ?? 0);
        $item->height    = (int) ($row['height'] ?? 0);
        $item->alt       = (string) ($row['alt'] ?? '');
        $item->title     = (string) ($row['title'] ?? '');
        $item->authorId  = (int) ($row['author_id'] ?? 0);
        $item->createdAt = (string) ($row['created_at'] ?? '');

        $sizes = json_decode((string) ($row['sizes'] ?? '{}'), true);
        $item->sizes = is_array($sizes) ? $sizes : [];

        return $item;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'filename'  => $this->filename,
            'path'      => $this->path,
            'mime'      => $this->mime,
            'size'      => $this->size,
            'width'     => $this->width,
            'height'    => $this->height,
            'alt'       => $this->alt,
            'title'     => $this->title,
            'author_id' => $this->authorId,
            'sizes'     => (string) json_encode($this->sizes, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    public function aspectRatio(): float
    {
        return $this->height > 0 ? $this->width / $this->height : 1.0;
    }
}
