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
            /*
             * ARAMA `blocks` SÜTUNUNU TARAMIYOR.
             *
             * 0.2.0'da `LIKE '%terim%'` blocks LONGTEXT'i de kapsıyordu. İki
             * sorun vardı:
             *
             *   1. Ham JSON metni arandığı için "type", "data", "paragraph",
             *      "text", "level" gibi terimler HER kaydın blok anahtarlarıyla
             *      eşleşiyordu — kullanıcı "type" arayınca tüm site dönüyordu.
             *   2. Baştan joker olduğu için indeks kullanılamıyor ve LONGTEXT
             *      taraması sayfalama COUNT(*)'ı yüzünden İKİ kez yapılıyordu.
             *
             * Gövde içinde arama gerçekten gerekiyorsa doğru araç FULLTEXT
             * indeks; Blueprint::fullText() var ama henüz kullanılmıyor. Şu an
             * başlık ve özet aranıyor, bu da kullanıcının beklediği davranışa
             * yanlış eşleşmelerden çok daha yakın.
             */
            $query->whereAnyLike(['c.title', 'c.excerpt'], (string) $args['search']);
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
            /*
             * Toplam sayım yalnızca gerektiğinde yapılır.
             *
             * Sayfalama bağlantısı basmayacak çağrılar (ana sayfa manşeti,
             * besleme, "popüler yazılar", 404 önerileri) `withTotal => false`
             * geçebilir; o zaman filtrelenmiş kümenin tamamı sayılmaz.
             * Varsayılan true — mevcut çağıranların davranışı değişmiyor.
             */
            $result = $query->paginate($perPage, $page, (bool) ($args['withTotal'] ?? true));
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
     * @param int|null $expectedRevision           İyimser kilit tabanı. Verilirse
     *        içeriğin sayacı bununla eşleşmezse kayıt REDDEDİLİR ve dönüşte
     *        `conflict => true` olur. null verilirse denetim yapılmaz.
     * @return array{ok: bool, id: int, error: string, conflict?: bool, revision?: int}
     */
    public function save(
        Entry $entry,
        array $meta = [],
        ?array $terms = null,
        ?int $expectedRevision = null,
    ): array {
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
            $row['created_at']  = Dates::stamp();
            $row['revision_no'] = 1;
            $entry->id          = $this->db->insert('content', $row);
        } else {
            /*
             * İYİMSER KİLİT
             *
             * Sayaç VERİTABANI düzeyinde artırılır ve güncelleme yalnızca
             * beklenen sayaçla eşleşirse geçer. Araya başka bir yazma girdiyse
             * etkilenen satır sayısı 0 olur ve çakışma bildirilir.
             *
             * Sayacın SQL içinde artması şart: 0.2.0'da bulkStatus() tek
             * UPDATE ile yazıp save()'i hiç çağırmıyordu, yani PHP tarafında
             * artırılan bir sayaç o yolu göremez ve kilit sessizce kör kalırdı.
             *
             * $expectedRevision null ise denetim yapılmaz — API ve göç gibi
             * çağrılar için. Panelden gelen istekte bu değerin BULUNMAMASI
             * hata sayılır; kararı çağıran veriyor, depo varsayım yapmıyor.
             */
            if ($expectedRevision !== null) {
                $affected = $this->db->statement(
                    sprintf(
                        'UPDATE `%s` SET %s, `revision_no` = `revision_no` + 1'
                        . ' WHERE `id` = :lock_id AND `revision_no` = :lock_rev',
                        $this->db->t('content'),
                        implode(', ', array_map(
                            static fn(string $column): string => sprintf('`%s` = :set_%s', $column, $column),
                            array_keys($row)
                        ))
                    ),
                    array_merge(
                        array_combine(
                            array_map(static fn(string $c): string => 'set_' . $c, array_keys($row)),
                            array_values($row)
                        ),
                        ['lock_id' => $entry->id, 'lock_rev' => $expectedRevision]
                    )
                );

                if ($affected === 0) {
                    $current = (int) ($this->db->builder('content')
                        ->where('id', $entry->id)->value('revision_no') ?? 0);

                    return [
                        'ok'       => false,
                        'id'       => $entry->id,
                        'error'    => 'Bu içerik siz düzenlerken başkası tarafından kaydedildi.',
                        'conflict' => true,
                        'revision' => $current,
                    ];
                }
            } else {
                $this->db->statement(
                    sprintf(
                        'UPDATE `%s` SET %s, `revision_no` = `revision_no` + 1 WHERE `id` = :lock_id',
                        $this->db->t('content'),
                        implode(', ', array_map(
                            static fn(string $column): string => sprintf('`%s` = :set_%s', $column, $column),
                            array_keys($row)
                        ))
                    ),
                    array_merge(
                        array_combine(
                            array_map(static fn(string $c): string => 'set_' . $c, array_keys($row)),
                            array_values($row)
                        ),
                        ['lock_id' => $entry->id]
                    )
                );
            }
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

    /* ---------------------------------------------------------------------
     * Sürüm geçmişi ve otomatik kayıt
     * ------------------------------------------------------------------ */

    /**
     * İçeriğin o anki hâlini sürüm olarak saklar.
     *
     * `kind` 'save' ya da 'restore' olduğunda yeni bir satır eklenir.
     * 'autosave' olduğunda KULLANICI BAŞINA tek slot kullanılır: aynı
     * kullanıcının önceki otomatik kaydı silinip yenisi yazılır.
     *
     * Slotun kullanıcı başına olması kritik. Tek slot olsaydı iki kişi aynı
     * içeriği açtığında birinin otomatik kaydı diğerinin üzerine yazardı — yani
     * tam olarak çakışmanın gerçekleştiği senaryoda veri kaybı. Oysa otomatik
     * kaydın varlık nedeni o senaryoda veriyi kurtarmak.
     */
    public function snapshot(Entry $entry, int $userId, string $kind = 'save'): int
    {
        $kind = in_array($kind, ['save', 'autosave', 'restore'], true) ? $kind : 'save';

        if ($kind === 'autosave') {
            $this->db->builder('revisions')
                ->where('entry_id', $entry->id)
                ->where('user_id', $userId)
                ->where('kind', 'autosave')
                ->delete();
        }

        return $this->db->insert('revisions', [
            'entry_id'    => $entry->id,
            'user_id'     => $userId,
            'kind'        => $kind,
            'title'       => $entry->title,
            'excerpt'     => $entry->excerpt,
            'blocks'      => json_encode($entry->blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => $entry->status,
            'revision_no' => $this->revisionOf($entry->id),
            'created_at'  => Dates::stamp(),
        ]);
    }

    /** İçeriğin o anki kilit sayacı. */
    public function revisionOf(int $entryId): int
    {
        return (int) ($this->db->builder('content')->where('id', $entryId)->value('revision_no') ?? 0);
    }

    /**
     * Sürüm listesi — en yeni önce. Blok gövdesi TAŞINMAZ; liste ekranında
     * gereksiz megabaytlar okunmasın.
     *
     * @return list<array<string, mixed>>
     */
    public function revisions(int $entryId, int $limit = 30): array
    {
        return $this->db->select(
            sprintf(
                'SELECT r.id, r.kind, r.title, r.status, r.revision_no, r.created_at,
                        r.user_id, u.display_name AS user_name
                 FROM `%s` r
                 LEFT JOIN `%s` u ON u.id = r.user_id
                 WHERE r.entry_id = :entry
                 ORDER BY r.id DESC
                 LIMIT %d',
                $this->db->t('revisions'),
                $this->db->t('users'),
                max(1, min(200, $limit))
            ),
            ['entry' => $entryId]
        );
    }

    /** @return array<string, mixed>|null */
    public function revision(int $id): ?array
    {
        return $this->db->builder('revisions')->where('id', $id)->first();
    }

    /**
     * Bir sürümü içeriğe geri yükler.
     *
     * Geri yükleme ÜZERİNE YAZMADAN ÖNCE mevcut hâli de sürüm olarak saklar;
     * yanlış sürümü geri yükleyen kullanıcı geri dönebilsin.
     *
     * @return array{ok: bool, error: string}
     */
    public function restoreRevision(int $revisionId, int $userId): array
    {
        $revision = $this->revision($revisionId);

        if ($revision === null) {
            return ['ok' => false, 'error' => 'Sürüm bulunamadı.'];
        }

        $entry = $this->find((int) $revision['entry_id'], false);

        if ($entry === null) {
            return ['ok' => false, 'error' => 'Sürümün ait olduğu içerik yok.'];
        }

        // Geri yüklemeden önceki hâl kaybolmasın.
        $this->snapshot($entry, $userId, 'restore');

        $blocks = json_decode((string) $revision['blocks'], true);

        $entry->title   = (string) $revision['title'];
        $entry->excerpt = (string) ($revision['excerpt'] ?? '');
        $entry->blocks  = is_array($blocks) ? $blocks : $entry->blocks;

        $result = $this->save($entry);

        return ['ok' => (bool) $result['ok'], 'error' => (string) $result['error']];
    }

    /**
     * Kayıt başına tutulan sürüm sayısını sınırlar.
     *
     * Otomatik kayıt slotları sayılmaz — onlar zaten kullanıcı başına tek.
     */
    public function pruneRevisions(int $keep = 20): int
    {
        $keep = max(1, $keep);

        $entries = $this->db->select(
            sprintf(
                'SELECT entry_id, COUNT(*) AS total FROM `%s`
                 WHERE kind <> \'autosave\'
                 GROUP BY entry_id HAVING total > %d',
                $this->db->t('revisions'),
                $keep
            )
        );

        $removed = 0;

        foreach ($entries as $row) {
            $keepIds = array_map(
                'intval',
                $this->db->builder('revisions')
                    ->where('entry_id', (int) $row['entry_id'])
                    ->where('kind', 'save')
                    ->orderBy('id', 'desc')
                    ->limit($keep)
                    ->pluck('id')
            );

            if ($keepIds === []) {
                continue;
            }

            $removed += $this->db->statement(
                sprintf(
                    'DELETE FROM `%s` WHERE entry_id = :entry AND kind <> \'autosave\'
                     AND id NOT IN (%s)',
                    $this->db->t('revisions'),
                    implode(',', $keepIds)
                ),
                ['entry' => (int) $row['entry_id']]
            );
        }

        return $removed;
    }

    /* ---------------------------------------------------------------------
     * Çöp kutusu
     * ------------------------------------------------------------------ */

    /**
     * İçeriği çöp kutusuna taşır. Geri getirilebilir.
     *
     * 0.2.0'da silme tek adımlıydı ve geri dönüşü yoktu; yanlışlıkla silinen
     * bir yazı yalnızca veritabanı yedeğinden kurtarılabiliyordu.
     */
    public function trash(int $id): bool
    {
        $affected = $this->db->builder('content')
            ->where('id', $id)
            ->update([
                'status'     => 'trash',
                'trashed_at' => Dates::stamp(),
                'updated_at' => Dates::stamp(),
            ]);

        return $affected > 0;
    }

    /**
     * Çöp kutusundan geri getirir.
     *
     * Durum 'draft' olur, 'published' DEĞİL: bir içeriği kazara yeniden
     * yayına almak, kazara silmekten daha kötü sonuç doğurabilir.
     */
    public function untrash(int $id): bool
    {
        $affected = $this->db->builder('content')
            ->where('id', $id)
            ->update([
                'status'     => 'draft',
                'trashed_at' => null,
                'updated_at' => Dates::stamp(),
            ]);

        return $affected > 0;
    }

    /** Çöp kutusunda belirtilen günden eski kayıtları kalıcı siler. */
    public function purgeTrash(int $days = 30): int
    {
        $limit = Dates::stamp('-' . max(1, $days) . ' days');

        $ids = array_map(
            'intval',
            $this->db->builder('content')
                ->where('status', 'trash')
                ->whereRaw('trashed_at IS NOT NULL AND trashed_at < :limit', ['limit' => $limit])
                ->pluck('id')
        );

        foreach ($ids as $id) {
            $this->delete($id);
        }

        return count($ids);
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
            // Sürümler de gitmeli; yoksa silinen içeriğin geçmişi yetim kalır
            // ve tablo hiç küçülmez.
            $this->db->delete('revisions', ['entry_id' => $id]);
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

        /*
         * Sayaç BURADA da artırılmalı.
         *
         * Bu yol save()'i hiç çağırmıyor; tek UPDATE ile yazıyor. Sayaç
         * artırılmazsa toplu bir durum değişikliği içeriği değiştirdiği hâlde
         * iyimser kilit bunu görmez: kullanıcı eski tabana göre kaydeder ve
         * toplu işlemin sonucunu sessizce ezer.
         */
        $changed = $this->db->statement(
            sprintf(
                'UPDATE `%s` SET `status` = :status, `updated_at` = :stamp,
                        `revision_no` = `revision_no` + 1
                 WHERE `id` IN (%s)',
                $this->db->t('content'),
                implode(',', $ids)
            ),
            ['status' => $status, 'stamp' => Dates::stamp()]
        );

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
