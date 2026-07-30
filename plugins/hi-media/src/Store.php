<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Database\Connection;
use HiCMS\Model\MediaItem;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Eklentinin kendi tabloları: üretilen kopyalar, klasörler ve etiketler.
 *
 * Tablolar yoksa (eklenti migration'ı henüz koşmadıysa) hiçbir yöntem hata
 * vermez; boş sonuç döner. Böylece panel yarım kurulumda da açılır.
 */
final class Store
{
    /** @var array<int, list<array<string, mixed>>> İstek içi bellek */
    private array $variantCache = [];

    private ?bool $ready = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function ready(): bool
    {
        return $this->ready ??= $this->db->tableExists('media_variants')
            && $this->db->tableExists('media_folders')
            && $this->db->tableExists('media_meta');
    }

    /* ---------------------------------------------------------------------
     * Üretilen kopyalar
     * ------------------------------------------------------------------ */

    /**
     * Bir medyanın kopya kaydını tamamen değiştirir.
     *
     * @param list<array{size_key: string, format: string, width: int, height: int, file: string, bytes: int}> $variants
     */
    public function replaceVariants(int $mediaId, array $variants): void
    {
        if (!$this->ready() || $mediaId <= 0) {
            return;
        }

        $this->db->builder('media_variants')->where('media_id', $mediaId)->delete();

        foreach ($variants as $variant) {
            $this->db->insert('media_variants', [
                'media_id'   => $mediaId,
                'size_key'   => mb_substr((string) $variant['size_key'], 0, 20),
                'format'     => mb_substr((string) $variant['format'], 0, 8),
                'width'      => (int) $variant['width'],
                'height'     => (int) $variant['height'],
                'file'       => mb_substr((string) $variant['file'], 0, 255),
                'bytes'      => (int) $variant['bytes'],
                'created_at' => Dates::stamp(),
            ]);
        }

        unset($this->variantCache[$mediaId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function variantsFor(int $mediaId): array
    {
        if (!$this->ready() || $mediaId <= 0) {
            return [];
        }

        return $this->variantCache[$mediaId] ??= $this->db->builder('media_variants')
            ->where('media_id', $mediaId)
            ->orderBy('width')
            ->get();
    }

    /**
     * Formata göre kopya sayısı ve toplam boyut — panel ölçüleri için.
     *
     * @return array<string, array{count: int, bytes: int}>
     */
    public function variantTotals(): array
    {
        if (!$this->ready()) {
            return [];
        }

        $rows = $this->db->builder('media_variants')
            ->select(['`format`', 'COUNT(*) AS adet', 'COALESCE(SUM(`bytes`), 0) AS toplam'])
            ->groupBy('format')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row['format']] = [
                'count' => (int) $row['adet'],
                'bytes' => (int) $row['toplam'],
            ];
        }

        return $totals;
    }

    /** Kopyası olan medya sayısı. */
    public function coveredMediaCount(): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return (int) $this->db->scalar(sprintf(
            'SELECT COUNT(DISTINCT media_id) FROM `%s`',
            $this->db->t('media_variants')
        ));
    }

    /**
     * Kaydı silinmiş medyaya ait kopyalar.
     *
     * Çekirdekte "medya silindi" olayı YOK; dosya silme akışı yalnızca
     * `media.sizes` içindekileri temizliyor. Bu yüzden birincil kümenin dışında
     * kalan kopyalar (örneğin AVIF) diskte kalabiliyor. Artıklar burada
     * bulunur, panelden temizlenir.
     *
     * @return list<array<string, mixed>>
     */
    public function orphanVariants(int $limit = 500): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->select(sprintf(
            'SELECT v.* FROM `%s` AS v LEFT JOIN `%s` AS m ON m.id = v.media_id'
            . ' WHERE m.id IS NULL ORDER BY v.id LIMIT %d',
            $this->db->t('media_variants'),
            $this->db->t('media'),
            max(1, $limit)
        ));
    }

    /** @param list<int> $ids */
    public function deleteVariants(array $ids): void
    {
        if (!$this->ready() || $ids === []) {
            return;
        }

        $this->db->builder('media_variants')->whereIn('id', $ids)->delete();
        $this->variantCache = [];
    }

    /** Bir medyanın tüm eklenti kaydını siler (kopyalar + klasör/etiket). */
    public function forgetMedia(int $mediaId): void
    {
        if (!$this->ready() || $mediaId <= 0) {
            return;
        }

        $this->db->builder('media_variants')->where('media_id', $mediaId)->delete();
        $this->db->builder('media_meta')->where('media_id', $mediaId)->delete();

        unset($this->variantCache[$mediaId]);
    }

    /** @return list<array<string, mixed>> */
    public function allVariants(int $limit = 5000): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->builder('media_variants')->orderBy('id')->limit($limit)->get();
    }

    /* ---------------------------------------------------------------------
     * Klasörler
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{id: int, name: string, slug: string, count: int}>
     */
    public function folders(): array
    {
        if (!$this->ready()) {
            return [];
        }

        $counts = [];

        foreach ($this->db->builder('media_meta')
            ->select(['`folder_id`', 'COUNT(*) AS adet'])
            ->where('folder_id', '>', 0)
            ->groupBy('folder_id')
            ->get() as $row) {
            $counts[(int) $row['folder_id']] = (int) $row['adet'];
        }

        $folders = [];

        foreach ($this->db->builder('media_folders')->orderBy('name')->get() as $row) {
            $id = (int) $row['id'];

            $folders[] = [
                'id'    => $id,
                'name'  => (string) $row['name'],
                'slug'  => (string) $row['slug'],
                'count' => $counts[$id] ?? 0,
            ];
        }

        return $folders;
    }

    /**
     * @return array{ok: bool, error: string, id: int}
     */
    public function createFolder(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (!$this->ready()) {
            return ['ok' => false, 'error' => 'Klasör tablosu yok.', 'id' => 0];
        }

        if ($name === '') {
            return ['ok' => false, 'error' => 'Klasör adı boş olamaz.', 'id' => 0];
        }

        $name = mb_substr($name, 0, 120);
        $slug = Str::slug($name);
        $slug = $slug !== '' ? mb_substr($slug, 0, 120) : 'klasor';

        if ($this->db->builder('media_folders')->where('slug', $slug)->exists()) {
            return ['ok' => false, 'error' => Str::format('"%s" klasörü zaten var.', $name), 'id' => 0];
        }

        $id = $this->db->insert('media_folders', [
            'name'       => $name,
            'slug'       => $slug,
            'created_at' => Dates::stamp(),
        ]);

        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    /** Klasörü siler; içindeki dosyalar silinmez, klasörsüz kalır. */
    public function deleteFolder(int $id): void
    {
        if (!$this->ready() || $id <= 0) {
            return;
        }

        $this->db->builder('media_folders')->where('id', $id)->delete();
        $this->db->builder('media_meta')->where('folder_id', $id)->update(['folder_id' => 0]);
    }

    /* ---------------------------------------------------------------------
     * Klasör/etiket ataması
     * ------------------------------------------------------------------ */

    /**
     * @return array{folder_id: int, tags: list<string>}
     */
    public function metaFor(int $mediaId): array
    {
        $all = $this->metaMany([$mediaId]);

        return $all[$mediaId] ?? ['folder_id' => 0, 'tags' => []];
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{folder_id: int, tags: list<string>}>
     */
    public function metaMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$this->ready() || $ids === []) {
            return [];
        }

        $meta = [];

        foreach ($this->db->builder('media_meta')->whereIn('media_id', $ids)->get() as $row) {
            $meta[(int) $row['media_id']] = [
                'folder_id' => (int) $row['folder_id'],
                'tags'      => self::splitTags((string) ($row['tags'] ?? '')),
            ];
        }

        return $meta;
    }

    /**
     * Klasör ve etiketleri yazar. Her ikisi de boşsa satır silinir — boş satır
     * tutmak "klasörsüz" sorgusunu yanlış cevaplar.
     *
     * @param list<string> $tags
     */
    public function assign(int $mediaId, int $folderId, array $tags): void
    {
        if (!$this->ready() || $mediaId <= 0) {
            return;
        }

        $folderId = $folderId > 0 && $this->db->builder('media_folders')->where('id', $folderId)->exists()
            ? $folderId
            : 0;

        $packed = self::packTags($tags);

        if ($folderId === 0 && $packed === '') {
            $this->db->builder('media_meta')->where('media_id', $mediaId)->delete();

            return;
        }

        $exists = $this->db->builder('media_meta')->where('media_id', $mediaId)->exists();

        $row = [
            'folder_id'  => $folderId,
            'tags'       => $packed,
            'updated_at' => Dates::stamp(),
        ];

        if ($exists) {
            $this->db->update('media_meta', $row, ['media_id' => $mediaId]);

            return;
        }

        $this->db->insert('media_meta', $row + ['media_id' => $mediaId]);
    }

    /**
     * Etiket → kullanım sayısı.
     *
     * @return array<string, int>
     */
    public function tagCounts(): array
    {
        if (!$this->ready()) {
            return [];
        }

        $counts = [];

        foreach ($this->db->builder('media_meta')->pluck('tags') as $packed) {
            foreach (self::splitTags((string) $packed) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** @return list<int> */
    public function mediaIdsInFolder(int $folderId): array
    {
        if (!$this->ready()) {
            return [];
        }

        return array_map('intval', $this->db->builder('media_meta')
            ->where('folder_id', max(0, $folderId))
            ->pluck('media_id'));
    }

    /** Klasöre atanmış tüm medya kimlikleri ("klasörsüz" süzgeci için). @return list<int> */
    public function assignedMediaIds(): array
    {
        if (!$this->ready()) {
            return [];
        }

        return array_map('intval', $this->db->builder('media_meta')
            ->where('folder_id', '>', 0)
            ->pluck('media_id'));
    }

    /** @return list<int> */
    public function mediaIdsWithTag(string $tag): array
    {
        $tag = self::normalizeTag($tag);

        if (!$this->ready() || $tag === '') {
            return [];
        }

        return array_map('intval', $this->db->builder('media_meta')
            ->whereLike('tags', '|' . $tag . '|')
            ->pluck('media_id'));
    }

    /* ---------------------------------------------------------------------
     * Etiket biçimi
     * ------------------------------------------------------------------ */

    /**
     * Virgüllü girdiyi etiket listesine çevirir.
     *
     * @return list<string>
     */
    public static function parseTags(string $input): array
    {
        $tags = [];

        foreach (preg_split('/[,\n]/u', $input) ?: [] as $piece) {
            $tag = self::normalizeTag((string) $piece);

            if ($tag !== '') {
                $tags[$tag] = $tag;
            }
        }

        return array_values($tags);
    }

    /** @param list<string> $tags */
    public static function packTags(array $tags): string
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = self::normalizeTag((string) $tag);

            if ($tag !== '') {
                $clean[$tag] = $tag;
            }
        }

        if ($clean === []) {
            return '';
        }

        $packed = '|' . implode('|', $clean) . '|';

        // 255 sınırını aşarsa son etiketler düşer; yarım etiket bırakılmaz.
        while (mb_strlen($packed) > 255 && $clean !== []) {
            array_pop($clean);
            $packed = $clean === [] ? '' : '|' . implode('|', $clean) . '|';
        }

        return $packed;
    }

    /** @return list<string> */
    public static function splitTags(string $packed): array
    {
        $tags = array_filter(array_map('trim', explode('|', $packed)), static fn(string $t): bool => $t !== '');

        return array_values($tags);
    }

    private static function normalizeTag(string $tag): string
    {
        // Ayırıcı karakter etikete giremez, yoksa sınır işareti anlamını yitirir.
        $tag = str_replace('|', ' ', $tag);
        $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');

        return mb_substr(mb_strtolower($tag, 'UTF-8'), 0, 40);
    }

    /* ---------------------------------------------------------------------
     * Listeleme
     * ------------------------------------------------------------------ */

    /**
     * Klasör/etiket süzgeçli medya listesi.
     *
     * Çekirdek deposu klasör bilmediği için süzgeç iki adımda uygulanır:
     * önce eşleşen kimlikler toplanır, sonra medya sorgusu onlarla sınırlanır.
     * JOIN yerine bu yol seçildi çünkü `SELECT *` iki tabloda aynı adlı
     * sütunları (updated_at) çakıştırıyor.
     *
     * @param array{folder?: string, tag?: string, search?: string, page?: int, perPage?: int} $args
     * @return array{items: list<MediaItem>, total: int, page: int, pages: int}
     */
    public function browse(array $args = []): array
    {
        // Gruplama görselle sınırlı değil: belge ve arşiv dosyaları da klasörlenir.
        $query = $this->db->builder('media')->orderBy('id', 'desc');

        $folder = (string) ($args['folder'] ?? '');
        $tag    = (string) ($args['tag'] ?? '');

        if ($folder === 'yok') {
            $query->whereIn('id', $this->assignedMediaIds(), true);
        } elseif ($folder !== '' && ctype_digit($folder)) {
            $query->whereIn('id', $this->mediaIdsInFolder((int) $folder));
        }

        if ($tag !== '') {
            $query->whereIn('id', $this->mediaIdsWithTag($tag));
        }

        if (($args['search'] ?? '') !== '') {
            $query->whereAnyLike(['filename', 'title', 'alt'], (string) $args['search']);
        }

        $result = $query->paginate((int) ($args['perPage'] ?? 25), (int) ($args['page'] ?? 1));

        return [
            'items' => array_map([MediaItem::class, 'fromRow'], $result['items']),
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ];
    }

    /**
     * Alt metni boş görseller.
     *
     * @return array{items: list<MediaItem>, total: int, page: int, pages: int}
     */
    public function withoutAlt(int $page = 1, int $perPage = 25): array
    {
        $result = $this->db->builder('media')
            ->whereLike('mime', 'image/')
            ->whereRaw("(`alt` IS NULL OR TRIM(`alt`) = '')")
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);

        return [
            'items' => array_map([MediaItem::class, 'fromRow'], $result['items']),
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ];
    }

    /** Türevi olmayan görsel sayısı — toplu işlem ekranının "kalan" sayısı. */
    public function pendingCount(): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return (int) $this->db->scalar(
            sprintf(
                'SELECT COUNT(*) FROM `%s` AS m WHERE m.mime LIKE :onek AND m.mime <> :svg'
                . ' AND NOT EXISTS (SELECT 1 FROM `%s` AS v WHERE v.media_id = m.id)',
                $this->db->t('media'),
                $this->db->t('media_variants')
            ),
            ['onek' => 'image/%', 'svg' => 'image/svg+xml']
        );
    }

    /**
     * Sıradaki işlenecek görseller.
     *
     * İMLEÇ neden gerekli: "zorla" modunda kopyası olanlar da işlendiği için
     * kuyruk kısalmıyor; imleç olmadan her parti aynı ilk N dosyayı yeniden
     * üretir ve iş asla bitmez. Normal modda da kalıcı olarak başarısız olan
     * bir dosya (animasyonlu GIF gibi) kuyruğun başını tıkardı.
     *
     * @return list<MediaItem>
     */
    public function pending(int $limit, bool $force = false, int $afterId = 0): array
    {
        $query = $this->db->builder('media')
            ->whereLike('mime', 'image/')
            ->where('mime', '!=', 'image/svg+xml')
            ->orderBy('id')
            ->limit(max(1, $limit));

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        if (!$force && $this->ready()) {
            $query->whereRaw(sprintf(
                'NOT EXISTS (SELECT 1 FROM `%s` AS v WHERE v.media_id = `%s`.`id`)',
                $this->db->t('media_variants'),
                $this->db->t('media')
            ));
        }

        return array_map([MediaItem::class, 'fromRow'], $query->get());
    }

    /** Ölçeklenebilir görsel sayısı (SVG hariç). */
    public function imageCount(): int
    {
        return $this->db->builder('media')
            ->whereLike('mime', 'image/')
            ->where('mime', '!=', 'image/svg+xml')
            ->count();
    }
}
