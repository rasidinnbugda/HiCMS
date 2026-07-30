<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Content\ContentType;
use HiCMS\Extension\Settings;
use HiCMS\Http\Response;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * XML site haritası ve robots.txt.
 *
 * TEK DOSYA DEĞİL, DİZİN. `/sitemap.xml` bir `<sitemapindex>` döndürür ve
 * çocukları içerik türüne göre ayrılır:
 *
 *     /sitemap.xml                  → dizin
 *     /sitemap-icerik-post.xml      → yazılar
 *     /sitemap-icerik-post-2.xml    → yazılar, ikinci parça
 *     /sitemap-icerik-page.xml      → sayfalar
 *     /sitemap-terim-category.xml   → kategoriler
 *     /sitemap-yazar.xml            → yazar arşivleri
 *
 * Tek dosyada tutmak iki sorun üretiyordu: 50.000 adres sınırına yaklaşan
 * siteler için geçersiz çıktı, ve her istekte TÜM içeriğin bellekte kurulması.
 * Bölünmüş dizinde her parça `LIMIT/OFFSET` ile çekilir.
 *
 * Kayıtlar `Entry::fromRow()` ile kurulur ama ilişkileri (yazar, görsel,
 * terimler) yüklenMEZ: site haritası yalnızca adres ve tarih istiyor,
 * hidrasyon her parçaya beş sorgu daha eklerdi.
 */
final class Sitemap
{
    /** Adres listelerinde dosya başına en fazla bu kadar kayıt. */
    private const CHUNK_MIN = 50;
    private const CHUNK_MAX = 5000;

    public function __construct(
        private readonly Settings $settings,
        private readonly EntrySeo $entries,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Dizin
     * ------------------------------------------------------------------ */

    /**
     * Dizindeki parçalar.
     *
     * Panel de bu listeyi kullanıyor: hangi dosyanın kaç adres taşıdığını
     * göstermek için ikinci bir sayım kodu yazılmadı.
     *
     * @return list<array{key: string, label: string, count: int, lastmod: string}>
     */
    public function parts(): array
    {
        $parts    = [];
        $excluded = $this->excludedTypes();
        $noindex  = $this->entries->noindexIds();
        $chunk    = $this->chunkSize();

        foreach (hi()->types()->publicTypes() as $type) {
            if (in_array($type->name, $excluded, true)) {
                continue;
            }

            $stats = $this->typeStats($type, $noindex);

            if ($stats['count'] === 0) {
                continue;
            }

            $pages = (int) max(1, (int) ceil($stats['count'] / $chunk));

            for ($page = 1; $page <= $pages; $page++) {
                $remaining = $stats['count'] - (($page - 1) * $chunk);

                $parts[] = [
                    'key'   => 'icerik-' . $type->name . ($page > 1 ? '-' . $page : ''),
                    'label' => $type->plural . ($pages > 1 ? ' (' . $page . '/' . $pages . ')' : ''),
                    // İlk parça ana sayfa ve arşiv adresini de taşıyor; panelde
                    // gösterilen sayı dosyadaki gerçek adres sayısı olmalı.
                    'count'   => (int) min($chunk, $remaining) + ($page === 1 ? $this->extraCount($type) : 0),
                    'lastmod' => $stats['lastmod'],
                ];
            }
        }

        if ((bool) $this->settings->get('sitemap_terms', true)) {
            foreach (hi()->types()->taxonomies() as $taxonomy) {
                if (!$taxonomy->isPublic || $taxonomy->route === '') {
                    continue;
                }

                $count = count(hi()->terms()->forTaxonomy($taxonomy->name, true, true));

                if ($count === 0) {
                    continue;
                }

                $parts[] = [
                    'key'     => 'terim-' . $taxonomy->name,
                    'label'   => $taxonomy->plural,
                    'count'   => $count,
                    'lastmod' => '',
                ];
            }
        }

        if ((bool) $this->settings->get('sitemap_authors', false)) {
            $authors = $this->authors();

            if ($authors !== []) {
                $parts[] = [
                    'key'     => 'yazar',
                    'label'   => 'Yazarlar',
                    'count'   => count($authors),
                    'lastmod' => '',
                ];
            }
        }

        return $parts;
    }

    public function index(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($this->parts() as $part) {
            $xml .= '  <sitemap>' . "\n"
                . '    <loc>' . Str::html($this->partUrl($part['key'])) . '</loc>' . "\n"
                . ($part['lastmod'] !== ''
                    ? '    <lastmod>' . Str::html(Dates::iso($part['lastmod'])) . '</lastmod>' . "\n" : '')
                . '  </sitemap>' . "\n";
        }

        return Response::xml($xml . '</sitemapindex>' . "\n");
    }

    public function partUrl(string $key): string
    {
        return hi()->urls()->to('sitemap-' . $key . '.xml');
    }

    /* ---------------------------------------------------------------------
     * Parçalar
     * ------------------------------------------------------------------ */

    public function part(string $key): Response
    {
        $page = 1;

        if (preg_match('/^(.*)-(\d+)$/', $key, $match) === 1) {
            $key  = $match[1];
            $page = max(1, (int) $match[2]);
        }

        if (str_starts_with($key, 'icerik-')) {
            $type = hi()->types()->get(substr($key, 7));

            if ($type === null || !$type->isPublic || in_array($type->name, $this->excludedTypes(), true)) {
                return $this->missing();
            }

            $urls = $this->typeUrls($type, $page);

            /*
             * Var olmayan parça 200 + boş liste DÖNMEZ. `/sitemap-icerik-post-9.xml`
             * gibi menzil dışı bir istek geçerli ama içi boş bir dosya olarak
             * cevaplanırsa arama motoru onu gerçek bir harita sanıp yeniden ister.
             */
            if ($urls === []) {
                return $this->missing();
            }

            return $this->urlset($urls);
        }

        if (str_starts_with($key, 'terim-')) {
            if (!(bool) $this->settings->get('sitemap_terms', true)) {
                return $this->missing();
            }

            $taxonomy = hi()->types()->taxonomy(substr($key, 6));

            if ($taxonomy === null || !$taxonomy->isPublic || $taxonomy->route === '') {
                return $this->missing();
            }

            $urls = [];

            foreach (hi()->terms()->forTaxonomy($taxonomy->name, true, true) as $term) {
                $urls[] = [
                    'loc'        => hi()->links()->forTerm($term),
                    'lastmod'    => '',
                    'changefreq' => 'weekly',
                    'priority'   => '0.5',
                ];
            }

            return $urls === [] ? $this->missing() : $this->urlset($urls);
        }

        if ($key === 'yazar') {
            if (!(bool) $this->settings->get('sitemap_authors', false)) {
                return $this->missing();
            }

            $urls = [];

            foreach ($this->authors() as $author) {
                $urls[] = [
                    'loc'        => hi()->links()->forAuthor($author),
                    'lastmod'    => '',
                    'changefreq' => 'monthly',
                    'priority'   => '0.3',
                ];
            }

            return $urls === [] ? $this->missing() : $this->urlset($urls);
        }

        return $this->missing();
    }

    /**
     * Bir içerik türünün adresleri.
     *
     * @return list<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function typeUrls(ContentType $type, int $page): array
    {
        $chunk = $this->chunkSize();
        $urls  = [];

        // İlk parçada arşiv adresi ve — yazı türünde — ana sayfa da listelenir.
        if ($page === 1) {
            if ($type->name === 'post') {
                $urls[] = [
                    'loc'        => hi()->urls()->to(),
                    'lastmod'    => '',
                    'changefreq' => 'daily',
                    'priority'   => '1.0',
                ];
            }

            if ($type->hasArchive()) {
                $urls[] = [
                    'loc'        => hi()->links()->forArchive($type),
                    'lastmod'    => '',
                    'changefreq' => 'weekly',
                    'priority'   => '0.7',
                ];
            }
        }

        $query = hi()->db()->builder('content')
            ->select(['id', 'type', 'slug', 'published_at', 'updated_at'])
            ->where('type', $type->name)
            ->whereVisible()
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($chunk)
            ->offset(($page - 1) * $chunk);

        $noindex = $this->entries->noindexIds();

        if ($noindex !== []) {
            $query->whereIn('id', $noindex, true);
        }

        foreach ($query->get() as $row) {
            $entry     = Entry::fromRow($row);
            $updatedAt = (string) ($row['updated_at'] ?? '');

            $urls[] = [
                'loc'        => hi()->links()->forEntry($entry),
                'lastmod'    => $updatedAt !== '' ? $updatedAt : (string) ($row['published_at'] ?? ''),
                'changefreq' => $type->name === 'page' ? 'monthly' : 'weekly',
                'priority'   => $type->name === 'page' ? '0.6' : '0.8',
            ];
        }

        return $urls;
    }

    /**
     * @param list<array{loc: string, lastmod: string, changefreq: string, priority: string}> $urls
     */
    private function urlset(array $urls): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            if ($url['loc'] === '') {
                continue;
            }

            $xml .= '  <url>' . "\n"
                . '    <loc>' . Str::html($url['loc']) . '</loc>' . "\n"
                . ($url['lastmod'] !== ''
                    ? '    <lastmod>' . Str::html(Dates::iso($url['lastmod'])) . '</lastmod>' . "\n" : '')
                . '    <changefreq>' . Str::html($url['changefreq']) . '</changefreq>' . "\n"
                . '    <priority>' . Str::html($url['priority']) . '</priority>' . "\n"
                . '  </url>' . "\n";
        }

        return Response::xml($xml . '</urlset>' . "\n");
    }

    private function missing(): Response
    {
        return Response::xml(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . "\n",
            404
        );
    }

    /* ---------------------------------------------------------------------
     * robots.txt
     * ------------------------------------------------------------------ */

    public function robots(): Response
    {
        $body = "User-agent: *\n";

        if (!(bool) hi_option('search_engine_index', true)) {
            /*
             * Site ayarı "arama motorlarına kapalı" ise robots.txt de kapatır.
             * İki yerin çelişmesi en sık rastlanan SEO hatası: panelde kapalı
             * görünen site tarayıcıya açık cevap veriyor.
             */
            $body .= "Disallow: /\n";

            return Response::text($body);
        }

        $disallow = ['/admin/', '/install.php', '/content/backups/', '/content/tmp/', '/arama'];

        foreach ((array) $this->settings->get('robots_disallow', []) as $line) {
            $line = trim((string) $line);

            if ($line !== '' && !in_array($line, $disallow, true)) {
                $disallow[] = str_starts_with($line, '/') ? $line : '/' . $line;
            }
        }

        $body .= "Allow: /\n";

        foreach ($disallow as $line) {
            $body .= 'Disallow: ' . $line . "\n";
        }

        $delay = (int) $this->settings->get('crawl_delay', 0);

        if ($delay > 0) {
            $body .= 'Crawl-delay: ' . min(120, $delay) . "\n";
        }

        $extra = trim((string) $this->settings->get('robots_extra', ''));

        if ($extra !== '') {
            $body .= "\n" . $extra . "\n";
        }

        if ((bool) $this->settings->get('robots_sitemap', true) && (bool) $this->settings->get('sitemap', true)) {
            $body .= "\nSitemap: " . hi()->urls()->to('sitemap.xml') . "\n";
        }

        return Response::text($body);
    }

    /* ---------------------------------------------------------------------
     * Yardımcılar
     * ------------------------------------------------------------------ */

    /** İlk parçaya eklenen içerik dışı adresler (ana sayfa, arşiv). */
    private function extraCount(ContentType $type): int
    {
        return ($type->name === 'post' ? 1 : 0) + ($type->hasArchive() ? 1 : 0);
    }

    public function chunkSize(): int
    {
        $chunk = (int) $this->settings->get('sitemap_chunk', 500);

        return max(self::CHUNK_MIN, min(self::CHUNK_MAX, $chunk));
    }

    /** @return list<string> */
    public function excludedTypes(): array
    {
        $names = [];

        foreach ((array) $this->settings->get('sitemap_exclude', []) as $name) {
            $name = Str::slug((string) $name, '_');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<int> $noindex
     * @return array{count: int, lastmod: string}
     */
    private function typeStats(ContentType $type, array $noindex): array
    {
        $query = hi()->db()->builder('content')->where('type', $type->name)->whereVisible();

        if ($noindex !== []) {
            $query->whereIn('id', $noindex, true);
        }

        $count = $query->count();

        if ($count === 0) {
            return ['count' => 0, 'lastmod' => ''];
        }

        // count() sorgusu bağlamayı tükettiği için taze bir sorgu kurulur.
        $latest = hi()->db()->builder('content')
            ->where('type', $type->name)
            ->whereVisible()
            ->max('updated_at');

        return ['count' => $count, 'lastmod' => (string) ($latest ?? '')];
    }

    /** @return list<\HiCMS\Model\User> */
    private function authors(): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            hi()->db()->builder('content')->whereVisible()->groupBy('author_id')->pluck('author_id')
        )));

        $authors = [];

        foreach (hi()->users()->findMany($ids) as $user) {
            $authors[] = $user;
        }

        return $authors;
    }
}
