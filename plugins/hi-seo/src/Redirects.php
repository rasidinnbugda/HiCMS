<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Extension\Settings;
use HiCMS\Http\Request;
use HiCMS\Http\Response;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Yönlendirme yöneticisi ve 404 yakalayıcı.
 *
 * EŞLEŞTİRME TEK SORGUYLA yapılır. Önce tam eşleşme, sonra joker karakterli
 * kaynaklar aynı sorguda çekilir:
 *
 *     /eski-yazi        → /yazi/yeni-adres        (tam)
 *     /blog/*           → /yazi/*                 (joker, kalan yol taşınır)
 *
 * Ayrı iki sorgu kurmak her ön yüz isteğine bir sorgu daha eklerdi; ziyaretçi
 * isteğinin maliyeti eklentinin sorumluluğu.
 *
 * 404 KAYDI YANIT GÖNDERİLDİKTEN SONRA yazılır. `routing.before` kancasında
 * isteğin 404 ile biteceği henüz bilinmiyor; `theme.head` kancasına bağlamak
 * ise temaya bağımlılık üretirdi (kendi 404 şablonunda `hi_head()` çağırmayan
 * bir tema hiçbir şey kaydetmezdi). Kapatma kancası durum kodunu okuyor:
 * gerçekten 404 dönen her istek, hangi tema kurulu olursa olsun kaydedilir.
 */
final class Redirects
{
    /** @var array<int, string> */
    public const STATUSES = [
        301 => '301 — kalıcı',
        302 => '302 — geçici',
        307 => '307 — geçici (yöntem korunur)',
        308 => '308 — kalıcı (yöntem korunur)',
    ];

    /** Kaydı anlamsız olan uzantılar: eksik varlık isteği SEO sorunu değil. */
    private const IGNORED_EXTENSIONS = [
        'php', 'css', 'js', 'map', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg',
        'webp', 'avif', 'woff', 'woff2', 'ttf', 'eot', 'txt', 'xml', 'json',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /* ---------------------------------------------------------------------
     * İstek anı
     * ------------------------------------------------------------------ */

    public function handle(Request $request): void
    {
        // Yalnızca okuma istekleri yönlendirilir; POST gövdesi kaybolmasın.
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return;
        }

        $path = $this->normalize($request->path);

        if ($path === '/') {
            return;
        }

        $match = $this->find($path);

        if ($match !== null) {
            $this->send($match, $path, (string) ($request->server['QUERY_STRING'] ?? ''));
        }

        $this->watch($path, $request->referer());
    }

    /**
     * Yanıt 404 ile biterse yolu kaydeder.
     */
    private function watch(string $path, string $referrer): void
    {
        if (!(bool) $this->settings->get('log_404', true) || $this->ignored($path)) {
            return;
        }

        register_shutdown_function(function () use ($path, $referrer): void {
            if (http_response_code() !== 404) {
                return;
            }

            try {
                $this->log($path, $referrer);
            } catch (\Throwable) {
                // Günlük tutmak isteği bozmaz; yanıt zaten gönderildi.
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function send(array $row, string $path, string $queryString): void
    {
        $target = trim((string) $row['target']);
        $rest   = (string) ($row['_rest'] ?? '');

        if ($rest !== '' && str_contains($target, '*')) {
            $target = str_replace('*', ltrim($rest, '/'), $target);
        }

        $target = str_replace('*', '', $target);

        if (preg_match('#^https?://#i', $target) !== 1) {
            $target = hi()->urls()->to(ltrim($target, '/'));
        }

        // Döngü koruması: hedef aynı yola çıkıyorsa yönlendirme yapılmaz.
        if ($this->normalize((string) parse_url($target, PHP_URL_PATH)) === $path) {
            return;
        }

        if ($queryString !== ''
            && (bool) $this->settings->get('keep_query', false)
            && !str_contains($target, '?')
        ) {
            $target .= '?' . $queryString;
        }

        $status = (int) $row['status'];
        $status = array_key_exists($status, self::STATUSES) ? $status : 301;

        try {
            hi()->db()->update('seo_redirects', [
                'hits'        => (int) $row['hits'] + 1,
                'last_hit_at' => Dates::stamp(),
            ], ['id' => (int) $row['id']]);
        } catch (\Throwable) {
            // Sayaç yazılamasa da yönlendirme yapılmalı.
        }

        Response::redirect($target, $status)->send();
        exit;
    }

    /**
     * Yola uyan kural: tam eşleşme, yoksa en uzun joker öneki.
     *
     * @return array<string, mixed>|null
     */
    private function find(string $path): ?array
    {
        $db = hi()->db();

        if (!$db->tableExists('seo_redirects')) {
            return null;
        }

        $rows = $db->builder('seo_redirects')
            ->whereRaw('(source = :hedef OR source LIKE :joker)', ['hedef' => $path, 'joker' => '%*'])
            ->limit(200)
            ->get();

        $wildcards = [];

        foreach ($rows as $row) {
            if ((string) $row['source'] === $path) {
                return $row;
            }

            $wildcards[] = $row;
        }

        // Uzun önek daha özeldir: /blog/2019/* , /blog/* sırasıyla denenir.
        usort(
            $wildcards,
            static fn(array $a, array $b): int => mb_strlen((string) $b['source']) <=> mb_strlen((string) $a['source'])
        );

        foreach ($wildcards as $row) {
            $prefix = rtrim(rtrim((string) $row['source'], '*'), '/');

            if ($prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $row['_rest'] = substr($path, mb_strlen($prefix));

                return $row;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * 404 günlüğü
     * ------------------------------------------------------------------ */

    public function log(string $path, string $referrer = ''): void
    {
        $db = hi()->db();

        if (!$db->tableExists('seo_notfound')) {
            return;
        }

        $path = mb_substr($path, 0, 191);
        $row  = $db->builder('seo_notfound')->where('path', $path)->first();

        if ($row !== null) {
            $db->update('seo_notfound', [
                'hits'        => (int) $row['hits'] + 1,
                'last_hit_at' => Dates::stamp(),
                'updated_at'  => Dates::stamp(),
            ], ['id' => (int) $row['id']]);

            return;
        }

        $db->insert('seo_notfound', [
            'path'        => $path,
            'referrer'    => mb_substr($referrer, 0, 255),
            'hits'        => 1,
            'last_hit_at' => Dates::stamp(),
            'created_at'  => Dates::stamp(),
            'updated_at'  => Dates::stamp(),
        ]);

        $this->prune();
    }

    /** Kayıt sınırını aşan satırları (en az isabetli, en eski) siler. */
    public function prune(): int
    {
        $db    = hi()->db();
        $max   = max(20, min(5000, (int) $this->settings->get('log_404_max', 300)));
        $total = $db->builder('seo_notfound')->count();

        if ($total <= $max) {
            return 0;
        }

        $ids = $db->builder('seo_notfound')
            ->orderBy('hits', 'asc')
            ->orderBy('last_hit_at', 'asc')
            ->limit($total - $max)
            ->pluck('id');

        foreach ($ids as $id) {
            $db->delete('seo_notfound', ['id' => (int) $id]);
        }

        return count($ids);
    }

    /* ---------------------------------------------------------------------
     * Panel: okuma
     * ------------------------------------------------------------------ */

    public function hasTable(string $table = 'seo_redirects'): bool
    {
        return hi()->db()->tableExists($table);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function page(int $page, int $perPage = 25): array
    {
        if (!$this->hasTable()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => $perPage];
        }

        $result = hi()->db()->builder('seo_redirects')
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);

        return [
            'items'   => $result['items'],
            'total'   => $result['total'],
            'page'    => $result['page'] ?? $page,
            'pages'   => $result['pages'],
            'perPage' => $perPage,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function missingPage(int $page, int $perPage = 25): array
    {
        if (!$this->hasTable('seo_notfound')) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => $perPage];
        }

        $result = hi()->db()->builder('seo_notfound')
            ->orderBy('hits', 'desc')
            ->orderBy('last_hit_at', 'desc')
            ->paginate($perPage, $page);

        return [
            'items'   => $result['items'],
            'total'   => $result['total'],
            'page'    => $result['page'] ?? $page,
            'pages'   => $result['pages'],
            'perPage' => $perPage,
        ];
    }

    public function redirectCount(): int
    {
        return $this->hasTable() ? hi()->db()->builder('seo_redirects')->count() : 0;
    }

    public function missingCount(): int
    {
        return $this->hasTable('seo_notfound') ? hi()->db()->builder('seo_notfound')->count() : 0;
    }

    public function missing(int $id): ?array
    {
        if (!$this->hasTable('seo_notfound')) {
            return null;
        }

        return hi()->db()->builder('seo_notfound')->where('id', $id)->first();
    }

    /**
     * Kırılan yollar için en yakın içerik önerisi.
     *
     * Aday listesi TEK SORGUYLA çekilir ve tüm yollar için yeniden kullanılır;
     * satır başına sorgu atmak 25 satırlık bir listede 25 sorgu demekti.
     *
     * @param list<string> $paths
     * @return array<string, array{path: string, title: string, score: int}>
     */
    public function suggestions(array $paths): array
    {
        if ($paths === [] || !(bool) $this->settings->get('suggest', true)) {
            return [];
        }

        $rows = hi()->db()->builder('content')
            ->select(['id', 'type', 'slug', 'title', 'published_at'])
            ->whereVisible()
            ->orderBy('published_at', 'desc')
            ->limit(1000)
            ->get();

        if ($rows === []) {
            return [];
        }

        $suggestions = [];

        foreach ($paths as $path) {
            $needle = $this->tail($path);

            if ($needle === '') {
                continue;
            }

            $bestScore = 0.0;
            $bestRow   = null;

            foreach ($rows as $row) {
                $slug = (string) $row['slug'];

                similar_text($needle, $slug, $percent);

                if ($percent > $bestScore) {
                    $bestScore = (float) $percent;
                    $bestRow   = $row;
                }
            }

            // %55 altındaki benzerlik öneri değil gürültüdür.
            if ($bestRow === null || $bestScore < 55.0) {
                continue;
            }

            $entry = Entry::fromRow($bestRow);

            $suggestions[$path] = [
                'path'  => '/' . ltrim(hi()->links()->pathForEntry($entry), '/'),
                'title' => (string) $bestRow['title'],
                'score' => (int) round($bestScore),
            ];
        }

        return $suggestions;
    }

    /* ---------------------------------------------------------------------
     * Panel: yazma
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok: bool, error: string}
     */
    public function add(string $source, string $target, int $status): array
    {
        if (!$this->hasTable()) {
            return ['ok' => false, 'error' => 'Yönlendirme tablosu yok. Sistem → Güncellemeler bölümünden '
                . 'veritabanı güncellemesini çalıştırın.'];
        }

        $source = $this->normalizeSource($source);
        $target = trim($target);

        if ($source === '/' || $source === '') {
            return ['ok' => false, 'error' => 'Kaynak yol zorunludur ve site kökü olamaz.'];
        }

        if ($target === '') {
            return ['ok' => false, 'error' => 'Hedef zorunludur.'];
        }

        if ($source === $target) {
            return ['ok' => false, 'error' => 'Kaynak ve hedef aynı; bu yönlendirme sonsuz döngü olur.'];
        }

        if (!array_key_exists($status, self::STATUSES)) {
            $status = 301;
        }

        $db = hi()->db();

        if ($db->builder('seo_redirects')->where('source', $source)->exists()) {
            return ['ok' => false, 'error' => 'Bu kaynak yol için zaten bir yönlendirme var: ' . $source];
        }

        $db->insert('seo_redirects', [
            'source'     => $source,
            'target'     => $target,
            'status'     => $status,
            'hits'       => 0,
            'created_at' => Dates::stamp(),
            'updated_at' => Dates::stamp(),
        ]);

        // Aynı yol 404 günlüğündeyse artık kırık değil.
        if ($this->hasTable('seo_notfound')) {
            $db->delete('seo_notfound', ['path' => $source]);
        }

        return ['ok' => true, 'error' => ''];
    }

    public function delete(int $id): void
    {
        if ($this->hasTable()) {
            hi()->db()->delete('seo_redirects', ['id' => $id]);
        }
    }

    public function deleteMissing(int $id): void
    {
        if ($this->hasTable('seo_notfound')) {
            hi()->db()->delete('seo_notfound', ['id' => $id]);
        }
    }

    public function clearMissing(): int
    {
        if (!$this->hasTable('seo_notfound')) {
            return 0;
        }

        $total = $this->missingCount();

        hi()->db()->statement('DELETE FROM `' . hi()->db()->t('seo_notfound') . '`');

        return $total;
    }

    /* ---------------------------------------------------------------------
     * Yardımcılar
     * ------------------------------------------------------------------ */

    public function normalizeSource(string $source): string
    {
        $source = trim($source);

        // Tam adres verilmişse yalnızca yol kısmı alınır.
        if (preg_match('#^https?://#i', $source) === 1) {
            $source = (string) parse_url($source, PHP_URL_PATH);
        }

        $source = (string) preg_replace('/\s+/', '', $source);
        $wild   = str_ends_with($source, '*');
        $source = '/' . trim($source, "/* \t");

        return $wild ? rtrim($source, '/') . '/*' : $source;
    }

    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }

    /** Yolun son parçası — öneri karşılaştırması kısa adla yapılır. */
    private function tail(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if ($segments === []) {
            return '';
        }

        $last = (string) end($segments);
        $last = (string) preg_replace('/\.(html?|php|aspx?)$/i', '', $last);

        return Str::slug($last);
    }

    private function ignored(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::IGNORED_EXTENSIONS, true);
    }
}
