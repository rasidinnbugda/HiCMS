<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;
use HiCMS\Model\MediaItem;
use HiCMS\Support\Dates;

/**
 * Medya kitaplığı deposu.
 */
final class MediaRepository
{
    /** @var array<int, MediaItem|null> */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function find(int $id): ?MediaItem
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        $row = $this->db->builder('media')->where('id', $id)->first();

        return $this->cache[$id] = $row !== null ? MediaItem::fromRow($row) : null;
    }

    /**
     * Birden çok kaydı tek sorguda getirir (N+1 önlemek için).
     *
     * @param list<int> $ids
     * @return array<int, MediaItem>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $items = [];

        foreach ($this->db->builder('media')->whereIn('id', $ids)->get() as $row) {
            $item                     = MediaItem::fromRow($row);
            $items[$item->id]         = $item;
            $this->cache[$item->id]   = $item;
        }

        return $items;
    }

    /**
     * Filtreli listeleme.
     *
     * @param array{type?: string, search?: string, author?: int, page?: int, perPage?: int} $args
     * @return array{items: list<MediaItem>, total: int, page: int, pages: int}
     */
    public function paginate(array $args = []): array
    {
        $query = $this->db->builder('media')->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if (($args['type'] ?? '') !== '') {
            $query->whereLike('mime', (string) $args['type']);
        }

        if (($args['search'] ?? '') !== '') {
            $query->whereAnyLike(['filename', 'title', 'alt'], (string) $args['search']);
        }

        if ((int) ($args['author'] ?? 0) > 0) {
            $query->where('author_id', (int) $args['author']);
        }

        $result = $query->paginate((int) ($args['perPage'] ?? 24), (int) ($args['page'] ?? 1));

        return [
            'items' => array_map([MediaItem::class, 'fromRow'], $result['items']),
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ];
    }

    /**
     * @return list<MediaItem>
     */
    public function recent(int $limit = 24, string $mimePrefix = ''): array
    {
        $query = $this->db->builder('media')->orderBy('id', 'desc')->limit($limit);

        if ($mimePrefix !== '') {
            $query->whereLike('mime', $mimePrefix);
        }

        return array_map([MediaItem::class, 'fromRow'], $query->get());
    }

    public function create(MediaItem $item): int
    {
        $row               = $item->toRow();
        $row['created_at'] = Dates::stamp();
        $row['updated_at'] = Dates::stamp();

        $id       = $this->db->insert('media', $row);
        $item->id = $id;

        $this->cache[$id] = $item;

        return $id;
    }

    public function update(MediaItem $item): bool
    {
        if ($item->id <= 0) {
            return false;
        }

        $row               = $item->toRow();
        $row['updated_at'] = Dates::stamp();

        $this->db->update('media', $row, ['id' => $item->id]);
        $this->cache[$item->id] = $item;

        return true;
    }

    /**
     * Yalnızca türev boyutları günceller — HiMedia eklentisi bunu kullanır.
     *
     * @param array<string, array{file: string, width: int, height: int}> $sizes
     */
    public function updateSizes(int $id, array $sizes): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->db->update('media', [
            'sizes'      => (string) json_encode($sizes, JSON_UNESCAPED_UNICODE),
            'updated_at' => Dates::stamp(),
        ], ['id' => $id]);

        unset($this->cache[$id]);

        return true;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->db->delete('media', ['id' => $id]);
        unset($this->cache[$id]);

        // Bu görsele bağlı içeriklerin öne çıkan görselini boşalt.
        $this->db->builder('content')->where('media_id', $id)->update(['media_id' => 0]);

        return true;
    }

    /**
     * @return array{count: int, bytes: int, images: int, documents: int}
     */
    public function stats(): array
    {
        $count  = $this->db->builder('media')->count();
        $bytes  = (int) $this->db->builder('media')->sum('size');
        $images = $this->db->builder('media')->whereLike('mime', 'image/')->count();

        return [
            'count'     => $count,
            'bytes'     => $bytes,
            'images'    => $images,
            'documents' => $count - $images,
        ];
    }

    /**
     * Belirli bir boyutun üzerindeki görselleri döndürür (panelde uyarı için).
     *
     * @return list<MediaItem>
     */
    public function oversized(int $bytes = 512000, int $limit = 20): array
    {
        $rows = $this->db->builder('media')
            ->whereLike('mime', 'image/')
            ->where('size', '>', $bytes)
            ->orderBy('size', 'desc')
            ->limit($limit)
            ->get();

        return array_map([MediaItem::class, 'fromRow'], $rows);
    }
}
