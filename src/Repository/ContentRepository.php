<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Content\BlockRegistry;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Connection;
use HiCMS\Events\Content\Deleted;
use HiCMS\Events\Content\Saved;
use HiCMS\Events\Content\Saving;
use HiCMS\Events\Dispatcher;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * İçerik deposu — çekirdeğin en çok kullanılan sınıfı.
 *
 * Tek `content` tablosu tüm türleri taşır. İlişkili veriler (yazar, görsel,
 * terimler, yorum sayısı) toplu sorgularla doldurulur; liste sayfalarında
 * N+1 sorgu oluşmaz.
 */
final class ContentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly TermRepository $terms,
        private readonly UserRepository $users,
        private readonly MediaRepository $media,
        private readonly Dispatcher $events,
        private readonly TypeRegistry $types,
        /*
         * Blok kayıt defteri, blok ağacını KAYIT ANINDA temizlemek için
         * gerekiyor. Model (Entry) buna erişemez — statik bir yöntemden
         * kayıt defterine ulaşmak modeli çekirdeğe bağlardı. Temizleme bu
         * yüzden depoda, yani verinin veritabanına girdiği sınırda yapılıyor.
         */
        private readonly ?BlockRegistry $blocks = null,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Okuma
     * ------------------------------------------------------------------ */

    public function find(int $id, bool $withRelations = true): ?Entry
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->builder('content')->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $entry = Entry::fromRow($row);

        if ($withRelations) {
            $this->hydrate([$entry]);
        }

        return $entry;
    }

    public function findBySlug(string $type, string $slug, bool $visibleOnly = false): ?Entry
    {
        $query = $this->db->builder('content')->where('type', $type)->where('slug', $slug);

        if ($visibleOnly) {
            $query->whereVisible();
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $entry = Entry::fromRow($row);
        $this->hydrate([$entry]);

        return $entry;
    }

    /**
     * Filtreli, sayfalanmış sorgu.
     *
     * @param array{
     *     type?: string|list<string>, status?: string, search?: string, author?: int,
     *     taxonomy?: string, term?: string, termId?: int, parent?: int|null,
     *     featured?: bool|null, exclude?: list<int>, include?: list<int>,
     *     orderBy?: string, orderDir?: string, page?: int, perPage?: int,
     *     visibleOnly?: bool, withRelations?: bool
     * } $args
     * @return array{items: list<Entry>, total: int, page: int, pages: int, perPage: int}
     */
    public function query(array $args = []): array
    {
        $query = $this->db->builder('content')->alias('c')->select(['c.*']);

        $type = $args['type'] ?? null;

        if (is_array($type) && $type !== []) {
            $query->whereIn('c.type', $type);
        } elseif (is_string($type) && $type !== '' && $type !== 'all') {
            $query->where('c.type', $type);
        }

        if (!empty($args['visibleOnly'])) {
            $query->whereRaw(
                "c.status = 'published' AND (c.published_at IS NULL OR c.published_at <= :vis_now)",
                ['vis_now' => Dates::stamp()]
            );
        } elseif (($args['status'] ?? 'all') !== 'all' && ($args['status'] ?? '') !== '') {
            $query->where('c.status', (string) $args['status']);
        }

        if (($args['search'] ?? '') !== '') {
            $query->whereAnyLike(['c.title', 'c.excerpt', 'c.blocks'], (string) $args['search']);
        }

        if ((int) ($args['author'] ?? 0) > 0) {
            $query->where('c.author_id', (int) $args['author']);
        }

        if (array_key_exists('parent', $args) && $args['parent'] !== null) {
            $query->where('c.parent_id', (int) $args['parent']);
        }

        if (array_key_exists('featured', $args) && $args['featured'] !== null) {
            $query->where('c.featured', $args['featured'] ? 1 : 0);
        }

        if (!empty($args['exclude'])) {
            $query->whereIn('c.id', array_map('intval', $args['exclude']), true);
        }

        if (!empty($args['include'])) {
            $query->whereIn('c.id', array_map('intval', $args['include']));
        }

        // Taksonomi süzgeci
        $termId = (int) ($args['termId'] ?? 0);

        if ($termId > 0 || (($args['term'] ?? '') !== '')) {
            $query->join('term_entry', 'term_entry.entry_id', '=', 'c.id');
            $query->join('terms', 'terms.id', '=', 'term_entry.term_id');

            if ($termId > 0) {
                $query->where('terms.id', $termId);
            } else {
                $query->where('terms.slug', (string) $args['term']);

                if (($args['taxonomy'] ?? '') !== '') {
                    $query->where('terms.taxonomy', (string) $args['taxonomy']);
                }
            }

            $query->groupBy('c.id');
        }

        // Sıralama — yalnızca izin verilen sütunlar.
        $orderBy = (string) ($args['orderBy'] ?? 'published_at');
        $allowed = ['published_at', 'created_at', 'updated_at', 'title', 'views', 'position', 'id'];

        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'published_at';
        }

        $direction = strtolower((string) ($args['orderDir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        // Öne çıkanlar listede önce gelsin (yalnızca tarih sıralamasında).
        if ($orderBy === 'published_at' && !empty($args['featuredFirst'])) {
            $query->orderBy('c.featured', 'desc');
        }

        $query->orderBy('c.' . $orderBy, $direction);

        if ($orderBy !== 'id') {
            $query->orderBy('c.id', 'desc');
        }

        $perPage = (int) ($args['perPage'] ?? 10);
        $page    = max(1, (int) ($args['page'] ?? 1));

        if ($perPage <= 0) {
            $rows  = $query->get();
            $total = count($rows);
            $pages = 1;
        } else {
            $result = $query->paginate($perPage, $page);
            $rows   = $result['items'];
            $total  = $result['total'];
            $pages  = $result['pages'];
        }

        $entries = array_map([Entry::class, 'fromRow'], $rows);

        if ($args['withRelations'] ?? true) {
            $this->hydrate($entries);
        }

        return [
            'items'   => $entries,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => $perPage,
        ];
    }

    /**
     * Kısayol: yalnızca kayıtları döndürür.
     *
     * @param array<string, mixed> $args
     * @return list<Entry>
     */
    public function get(array $args = []): array
    {
        return $this->query($args)['items'];
    }

    /**
     * İlişkili verileri toplu doldurur.
     *
     * @param list<Entry> $entries
     */
    public function hydrate(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $ids       = array_map(static fn(Entry $e): int => $e->id, $entries);
        $authorIds = array_values(array_filter(array_map(static fn(Entry $e): int => $e->authorId, $entries)));
        $mediaIds  = array_values(array_filter(array_map(static fn(Entry $e): int => $e->mediaId, $entries)));

        $authors  = $this->users->findMany($authorIds);
        $images   = $this->media->findMany($mediaIds);
        $terms    = $this->terms->forEntries($ids);
        $comments = $this->commentCounts($ids);
        $meta     = $this->metaFor($ids);

        foreach ($entries as $entry) {
            $entry->author       = $authors[$entry->authorId] ?? null;
            $entry->image        = $images[$entry->mediaId] ?? null;
            $entry->terms        = $terms[$entry->id] ?? [];
            $entry->commentCount = $comments[$entry->id] ?? 0;
            $entry->meta         = $meta[$entry->id] ?? [];
        }
    }

    /**
     * Önceki/sonraki kayıt.
     */
    public function adjacent(Entry $entry, string $direction = 'next'): ?Entry
    {
        $isNext   = $direction === 'next';
        $operator = $isNext ? '>' : '<';
        $order    = $isNext ? 'asc' : 'desc';
        $anchor   = $entry->publishedAt ?? $entry->createdAt;

        $row = $this->db->builder('content')
            ->where('type', $entry->type)
            ->whereVisible()
            ->whereRaw(
                sprintf('(COALESCE(published_at, created_at) %s :anchor)', $operator),
                ['anchor' => $anchor]
            )
            ->orderByRaw('COALESCE(published_at, created_at) ' . strtoupper($order))
            ->first();

        if ($row === null) {
            return null;
        }

        $adjacent = Entry::fromRow($row);
        $this->hydrate([$adjacent]);

        return $adjacent;
    }

    /**
     * Benzer içerikler: aynı terimleri paylaşanlar önce, eksik kalırsa en yeniler.
     *
     * @return list<Entry>
     */
    public function related(Entry $entry, int $limit = 3): array
    {
        $termIds = array_map(static fn(object $t): int => $t->id, $entry->terms);
        $related = [];

        if ($termIds !== []) {
            $related = $this->get([
                'type'        => $entry->type,
                'visibleOnly' => true,
                'exclude'     => [$entry->id],
                'perPage'     => $limit,
                'termId'      => $termIds[0],
            ]);
        }

        if (count($related) < $limit) {
            $exclude = array_merge([$entry->id], array_map(static fn(Entry $e): int => $e->id, $related));

            $related = array_merge($related, $this->get([
                'type'        => $entry->type,
                'visibleOnly' => true,
                'exclude'     => $exclude,
                'perPage'     => $limit - count($related),
            ]));
        }

        return $related;
    }

    /**
     * Durum sayıları — panel sekmelerinde gösterilir.
     *
     * @return array<string, int>
     */
    public function statusCounts(string $type): array
    {
        $rows = $this->db->builder('content')
            ->select(['status'])
            ->selectRaw('COUNT(*) AS total')
            ->where('type', $type)
            ->groupBy('status')
            ->get();

        $counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'pending' => 0, 'private' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = (int) $row['total'];
            $counts['all']  += (int) $row['total'];
        }

        return $counts;
    }

    public function countOfType(string $type, ?string $status = null): int
    {
        $query = $this->db->builder('content')->where('type', $type);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->count();
    }

    public function totalViews(): int
    {
        return (int) $this->db->builder('content')->sum('views');
    }

    /* ---------------------------------------------------------------------
     * Yazma
     * ------------------------------------------------------------------ */

    /**
     * İçeriği kaydeder (yeni ya da güncelleme).
     *
     * @param array<string, mixed> $meta           Özel alan değerleri
     * @param array<string, list<int>>|null $terms taksonomi → terim kimlikleri
     * @return array{ok: bool, id: int, error: string}
     */
    public function save(Entry $entry, array $meta = [], ?array $terms = null): array
    {
        $isNew = $entry->id === 0;

        $entry->title = trim($entry->title);

        if ($entry->title === '') {
            $entry->title = 'Başlıksız';
        }

        $entry->slug = $this->uniqueSlug(
            $entry->type,
            $entry->slug !== '' ? Str::slug($entry->slug) : Str::slug($entry->title),
            $entry->id
        );

        /*
         * Yapı normalize edilir, ardından İÇERİK temizlenir.
         *
         * İkinci adım 0.3.0'da eklendi: 0.2.0 temizlemeyi yalnızca render
         * anında yapıyordu, veritabanına ham HTML yazıyordu. Ön yüz güvenliydi
         * ama blok metnini render yolundan geçmeden okuyan her tüketici (JSON
         * çıktısı, dışa aktarma, arama, denetim günlüğü, eklentiler, panelin
         * kendisi) ham veriyi görüyordu.
         */
        $entry->blocks = Entry::normalizeBlocks($entry->blocks);

        if ($this->blocks !== null) {
            $entry->blocks = $this->blocks->sanitizeTree($entry->blocks);
        }

        // Yayınlanıyorsa ve tarih yoksa şimdi olarak damgala.
        if ($entry->status === 'published' && ($entry->publishedAt === null || $entry->publishedAt === '')) {
            $entry->publishedAt = Dates::stamp();
        }

        $previousStatus = null;

        if (!$isNew) {
            $previousStatus = (string) ($this->db->builder('content')
                ->where('id', $entry->id)->value('status') ?? '');
        }

        $event = $this->events->dispatch(new Saving($entry, $isNew));

        if ($event->isCancelled()) {
            return ['ok' => false, 'id' => $entry->id, 'error' => $event->reason() ?: 'Kayıt iptal edildi.'];
        }

        $entry = $event->entry;
        $row   = $entry->toRow();

        $row['updated_at'] = Dates::stamp();

        if ($isNew) {
            $row['created_at'] = Dates::stamp();
            $entry->id         = $this->db->insert('content', $row);
        } else {
            $this->db->update('content', $row, ['id' => $entry->id]);
        }

        if ($meta !== []) {
            $this->saveMeta($entry->id, $meta);
        }

        if ($terms !== null) {
            $this->terms->syncEntry($entry->id, $terms);
        }

        $statusChanged = $previousStatus !== null && $previousStatus !== $entry->status
            ? $entry->status
            : ($isNew ? $entry->status : null);

        $this->events->dispatch(new Saved($entry, $isNew, $statusChanged));

        return ['ok' => true, 'id' => $entry->id, 'error' => ''];
    }

    public function delete(int $id): bool
    {
        $entry = $this->find($id, false);

        if ($entry === null) {
            return false;
        }

        $this->db->transaction(function () use ($id): void {
            $this->db->delete('content_meta', ['entry_id' => $id]);
            $this->db->delete('term_entry', ['entry_id' => $id]);
            $this->db->delete('comments', ['entry_id' => $id]);
            $this->db->delete('content', ['id' => $id]);

            // Alt sayfaları köke taşı.
            $this->db->builder('content')->where('parent_id', $id)->update(['parent_id' => 0]);
        });

        $this->events->dispatch(new Deleted($id, $entry->type, $entry->slug));

        return true;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === [] || !in_array($status, ['draft', 'pending', 'published', 'private'], true)) {
            return 0;
        }

        $data = ['status' => $status, 'updated_at' => Dates::stamp()];

        $changed = $this->db->builder('content')->whereIn('id', $ids)->update($data);

        if ($status === 'published') {
            // Yayın tarihi olmayanları şimdi damgala.
            $this->db->builder('content')
                ->whereIn('id', $ids)
                ->whereNull('published_at')
                ->update(['published_at' => Dates::stamp()]);
        }

        foreach ($ids as $id) {
            $entry = $this->find($id, false);

            if ($entry !== null) {
                $this->events->dispatch(new Saved($entry, false, $status));
            }
        }

        return $changed;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkDelete(array $ids): int
    {
        $deleted = 0;

        foreach (array_filter(array_map('intval', $ids)) as $id) {
            if ($this->delete($id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function incrementViews(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->db->statement(
            sprintf('UPDATE `%s` SET views = views + 1 WHERE id = :id', $this->db->t('content')),
            ['id' => $id]
        );
    }

    /**
     * Zamanı gelmiş planlı yayınları görünür kılar — Scheduler çağırır.
     *
     * @return int Yayınlanan kayıt sayısı
     */
    public function publishDue(): int
    {
        // Durumu 'published' ama tarihi gelecekte olan kayıtlar zaten
        // `whereVisible()` ile gizlenir; burada yalnızca olay tetiklenir.
        $rows = $this->db->builder('content')
            ->where('status', 'published')
            ->whereRaw('published_at IS NOT NULL AND published_at <= :now AND updated_at < published_at',
                ['now' => Dates::stamp()])
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            $entry = Entry::fromRow($row);

            $this->db->update('content', ['updated_at' => Dates::stamp()], ['id' => $entry->id]);
            $this->events->dispatch(new Saved($entry, false, 'published'));
        }

        return count($rows);
    }

    /* ---------------------------------------------------------------------
     * Özel alanlar
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $meta
     */
    public function saveMeta(int $entryId, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $key = Str::slug((string) $key, '_');

            if ($key === '') {
                continue;
            }

            $encoded = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $exists = $this->db->builder('content_meta')
                ->where('entry_id', $entryId)
                ->where('meta_key', $key)
                ->exists();

            if ($exists) {
                $this->db->builder('content_meta')
                    ->where('entry_id', $entryId)
                    ->where('meta_key', $key)
                    ->update(['meta_value' => $encoded]);
            } else {
                $this->db->insert('content_meta', [
                    'entry_id'   => $entryId,
                    'meta_key'   => $key,
                    'meta_value' => $encoded,
                ]);
            }
        }
    }

    public function meta(int $entryId, string $key, mixed $default = null): mixed
    {
        $raw = $this->db->builder('content_meta')
            ->where('entry_id', $entryId)
            ->where('meta_key', $key)
            ->value('meta_value');

        if ($raw === null) {
            return $default;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $raw;
    }

    public function deleteMeta(int $entryId, string $key): void
    {
        $this->db->builder('content_meta')
            ->where('entry_id', $entryId)
            ->where('meta_key', $key)
            ->delete();
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, array<string, mixed>>
     */
    private function metaFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows   = $this->db->builder('content_meta')->whereIn('entry_id', $entryIds)->get();
        $result = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['meta_value'], true);

            $result[(int) $row['entry_id']][(string) $row['meta_key']] =
                json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $row['meta_value'];
        }

        return $result;
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, int>
     */
    private function commentCounts(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->db->builder('comments')
            ->select(['entry_id'])
            ->selectRaw('COUNT(*) AS total')
            ->whereIn('entry_id', $entryIds)
            ->where('status', 'approved')
            ->groupBy('entry_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['entry_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /* ---------------------------------------------------------------------
     * Yardımcılar
     * ------------------------------------------------------------------ */

    public function uniqueSlug(string $type, string $slug, int $ignoreId = 0): string
    {
        $slug = $slug !== '' ? $slug : 'icerik';
        $base = $slug;
        $n    = 1;

        // Sayfalar kök yolda oturduğu için ayrılmış adlarla çakışmamalı.
        $reserved = ['admin', 'install', 'content', 'themes', 'plugins', 'src', 'arama', 'sayfa'];

        if ($type === 'page' && in_array($slug, $reserved, true)) {
            $slug = $base = $slug . '-sayfa';
        }

        while (true) {
            $query = $this->db->builder('content')->where('type', $type)->where('slug', $slug);

            if ($ignoreId > 0) {
                $query->where('id', '!=', $ignoreId);
            }

            if (!$query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . (++$n);
        }
    }

    /**
     * Ana sayfa/menü seçicileri için sade liste.
     *
     * @return array<int, string> id → başlık
     */
    public function options(string $type, int $limit = 200): array
    {
        $rows    = $this->db->builder('content')
            ->select(['id', 'title'])
            ->where('type', $type)
            ->orderBy('title')
            ->limit($limit)
            ->get();
        $options = [];

        foreach ($rows as $row) {
            $options[(int) $row['id']] = (string) $row['title'];
        }

        return $options;
    }

    /**
     * Hiyerarşik türler için ebeveyn seçenekleri (kendini ve alt ağacını dışlar).
     *
     * @return array<int, string>
     */
    public function parentOptions(string $type, int $excludeId = 0): array
    {
        $options = $this->options($type);

        unset($options[$excludeId]);

        return $options;
    }

    public function typeRegistry(): TypeRegistry
    {
        return $this->types;
    }
}
