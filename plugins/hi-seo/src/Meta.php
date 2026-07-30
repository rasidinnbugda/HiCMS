<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Extension\Settings;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;
use HiCMS\Theme\ViewContext;

/**
 * `<head>` çıktısı: başlık, açıklama, canonical, robots, og:*, twitter:* ve
 * JSON-LD.
 *
 * BAŞLIK VE AÇIKLAMA FİLTREYLE DEĞİŞTİRİLİR, tekrar basılmaz. Çekirdek
 * `hi_document_title()` ve `hi_meta_description()` değerlerini
 * `theme.document_title` / `theme.meta_description` filtrelerinden geçiriyor;
 * bu yüzden temanın `<title>` etiketi ve kendi og:title'ı da otomatik olarak
 * SEO ayarlarına uyar. İkinci bir `<title>` basmak geçersiz HTML üretirdi.
 *
 * PAYLAŞIM ETİKETLERİ İKİ KEZ BASILMAZ. Varsayılan tema (hiblog) og:* ve
 * twitter:card etiketlerini kendisi yazıyor. `og_mode = auto` ayarında etkin
 * temanın şablonları bir kez taranır ve YALNIZCA temanın basmadığı etiketler
 * eklenir — örneğin hiblog `article:published_time` basmadığı için onu eklenti
 * tamamlar. "Her zaman bas" seçeneği og basmayan temalar için.
 */
final class Meta
{
    /**
     * Basılabilecek paylaşım etiketleri. Otomatik modda temanın hangilerini
     * bastığı bu listeye göre denetlenir.
     *
     * @var list<string>
     */
    private const SOCIAL_TAGS = [
        'og:site_name', 'og:locale', 'og:type', 'og:title', 'og:description', 'og:url', 'og:image',
        'article:published_time', 'article:modified_time', 'article:author',
        'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:site',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly EntrySeo $entries,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Başlık ve açıklama filtreleri
     * ------------------------------------------------------------------ */

    public function title(mixed $title): string
    {
        $title = is_string($title) ? $title : '';
        $view  = hi_view();

        if ($view->isSingular() && $view->entry !== null) {
            $custom = $this->entries->value($view->entry, 'seo_title');

            if ($custom !== '') {
                return $this->withPage($custom, $view);
            }
        }

        if ($view->kind === ViewContext::HOME) {
            $home = trim((string) $this->settings->get('home_title', ''));

            return $home !== '' ? $this->withPage($home, $view) : $title;
        }

        $pattern = trim((string) $this->settings->get('title_pattern', ''));
        $subject = $this->subject($view);

        if ($pattern === '' || $subject === '') {
            return $title;
        }

        return $this->withPage(
            str_replace(
                ['%baslik%', '%site%', '%slogan%'],
                [$subject, hi_site_name(), hi_site_tagline()],
                $pattern
            ),
            $view
        );
    }

    public function description(mixed $description): string
    {
        $description = is_string($description) ? trim($description) : '';
        $view        = hi_view();

        if ($view->isSingular() && $view->entry !== null) {
            $custom = $this->entries->value($view->entry, 'seo_description');

            if ($custom !== '') {
                return Str::limit($custom, 320, '');
            }
        }

        if ($description === '') {
            $description = trim((string) $this->settings->get('default_description', ''));
        }

        return $description;
    }

    /** Kalıbın konu başlığı — görünüm türüne göre. */
    private function subject(ViewContext $view): string
    {
        return match ($view->kind) {
            ViewContext::SINGLE, ViewContext::PAGE => $view->entry?->title ?? '',
            ViewContext::TAXONOMY => $view->term?->name ?? '',
            ViewContext::ARCHIVE  => $view->contentType?->plural ?? '',
            ViewContext::AUTHOR   => $view->author?->displayName ?? '',
            ViewContext::SEARCH   => Str::format('"%s" için arama sonuçları', $view->searchTerm),
            ViewContext::NOTFOUND => 'Sayfa bulunamadı',
            default               => hi_site_name(),
        };
    }

    /**
     * Sayfa numarasını yerleştirir.
     *
     * Kalıpta `%sayfa%` varsa oraya yazılır; yoksa sayfalanmış adreslerde sona
     * eklenir. İkinci sayfanın başlığı birinciyle aynı kalırsa arama motoru
     * ikisini kopya sayar.
     */
    private function withPage(string $title, ViewContext $view): string
    {
        $label = $view->isPaged() ? Str::format('Sayfa %d', $view->page) : '';

        if (!str_contains($title, '%sayfa%')) {
            return $label !== '' ? $title . ' · ' . $label : $title;
        }

        $title = str_replace('%sayfa%', $label, $title);

        if ($label === '') {
            /*
             * Yer tutucu boşaldığında SÜSLEMESİ DE GİDER. "Rehber – Site
             * (%sayfa%)" kalıbı ilk sayfada "Rehber – Site ()" basıyordu:
             * kalıbı yazan kişi parantezi sayfa numarası için koymuştu.
             */
            $title = (string) preg_replace('/\s*[\(\[\{]\s*[\)\]\}]/u', '', $title);
            $title = (string) preg_replace('/\s{2,}/u', ' ', $title);
            $title = trim($title);

            return trim($title, " \t·–—-|,;:");
        }

        return trim((string) preg_replace('/\s{2,}/u', ' ', $title));
    }

    /* ---------------------------------------------------------------------
     * <head> etiketleri
     * ------------------------------------------------------------------ */

    public function head(): null
    {
        $view = hi_view();

        $robots = $this->robots($view);

        if ($robots !== '') {
            echo '<meta name="robots" content="' . Str::attr($robots) . '">' . "\n";
        }

        $canonical = $this->canonical($view);

        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . Str::url($canonical) . '">' . "\n";
        }

        if ($this->wantsSocial()) {
            $this->social($view, $canonical);
        }

        if ((bool) $this->settings->get('json_ld', true)) {
            $data = $this->structuredData($view, $canonical);

            if ($data !== []) {
                echo '<script type="application/ld+json">'
                    . (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . '</script>' . "\n";
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * Ham metin yardımcıları
     * ------------------------------------------------------------------ */

    /**
     * Çekirdeğin ürettiği başlığın KAÇIŞSIZ hâli.
     *
     * `hi_document_title()` çıktısını `Str::html()` ile kaçırarak döndürüyor;
     * bunu ikinci kez kaçırmak `&` işaretini `&amp;amp;` yapar. Kendi
     * hesabımızı yeniden kurmak yerine kaçış geri alınıyor: böylece başka
     * eklentilerin `theme.document_title` filtresine yaptığı değişiklikler de
     * paylaşım etiketlerine yansır.
     */
    private function titleText(): string
    {
        return html_entity_decode(hi_document_title(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function descriptionText(): string
    {
        return html_entity_decode(hi_meta_description(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function locale(): string
    {
        return hi()->translator()->locale();
    }

    /** robots meta içeriği; boş dönerse etiket basılmaz. */
    private function robots(ViewContext $view): string
    {
        // Site ayarından kapatıldıysa her sayfa dizin dışıdır.
        if (!(bool) hi_option('search_engine_index', true)) {
            return 'noindex, nofollow';
        }

        if ($view->isSingular() && $view->entry !== null && $this->entries->isNoindex($view->entry)) {
            return 'noindex, follow';
        }

        if ($view->kind === ViewContext::NOTFOUND) {
            return 'noindex, follow';
        }

        if ($view->kind === ViewContext::SEARCH && (bool) $this->settings->get('noindex_search', true)) {
            return 'noindex, follow';
        }

        $isArchive = $view->is(ViewContext::ARCHIVE, ViewContext::TAXONOMY, ViewContext::AUTHOR);

        if ($isArchive && (bool) $this->settings->get('noindex_archives', false)) {
            return 'noindex, follow';
        }

        if ($view->isPaged() && (bool) $this->settings->get('noindex_paged', false)) {
            return 'noindex, follow';
        }

        return '';
    }

    private function canonical(ViewContext $view): string
    {
        if ($view->kind === ViewContext::NOTFOUND) {
            return '';
        }

        if ($view->isSingular() && $view->entry !== null) {
            $custom = $this->entries->value($view->entry, 'seo_canonical');

            if ($custom !== '') {
                return preg_match('#^https?://#i', $custom) === 1
                    ? $custom
                    : hi()->urls()->to(ltrim($custom, '/'));
            }
        }

        $links = hi()->links();

        return match ($view->kind) {
            ViewContext::SINGLE, ViewContext::PAGE => $view->entry !== null
                ? $links->forEntry($view->entry)
                : hi()->urls()->to(),
            ViewContext::TAXONOMY => $view->term !== null
                ? $links->forTermPage($view->term, $view->page)
                : hi()->urls()->to(),
            ViewContext::ARCHIVE => $view->contentType !== null
                ? $links->forArchive($view->contentType, $view->page)
                : hi()->urls()->to(),
            ViewContext::AUTHOR => $view->author !== null
                ? $links->forAuthor($view->author, $view->page)
                : hi()->urls()->to(),
            ViewContext::SEARCH => $links->forSearch($view->searchTerm, $view->page),
            default             => $links->forHome($view->page),
        };
    }

    /* ---------------------------------------------------------------------
     * Paylaşım etiketleri
     * ------------------------------------------------------------------ */

    private function wantsSocial(): bool
    {
        return (string) $this->settings->get('og_mode', 'auto') !== 'off';
    }

    /**
     * Temanın kendisinin bastığı paylaşım etiketleri.
     *
     * ETİKET BAŞINA denetlenir, toptan değil. "Tema og:title basıyorsa hiçbir
     * şey basma" kuralı fazla kabaydı: varsayılan tema og:title basıp
     * `article:published_time` ve `twitter:site` basmıyor, yani o etiketler
     * hiçbir zaman çıkmıyordu. Artık yalnızca ÇAKIŞAN etiketler atlanıyor.
     *
     * İstek başına bir kez, en çok üç küçük dosya okunur; sonuç belleklenir.
     *
     * @return array<string, true>
     */
    private static function themeTags(): array
    {
        static $found = null;

        if ($found !== null) {
            return $found;
        }

        $found     = [];
        $directory = hi()->themes()->active()?->directory ?? '';

        if ($directory === '') {
            return $found;
        }

        $source = '';

        foreach (['header.php', 'head.php', 'index.php'] as $file) {
            $path = $directory . '/' . $file;

            if (is_file($path)) {
                $source .= (string) file_get_contents($path);
            }
        }

        foreach (self::SOCIAL_TAGS as $tag) {
            if (str_contains($source, $tag)) {
                $found[$tag] = true;
            }
        }

        return $found;
    }

    private function social(ViewContext $view, string $canonical): void
    {
        $entry = $view->isSingular() ? $view->entry : null;
        $image = $this->image($entry);

        // Otomatik modda temanın bastığı etiketler atlanır; "her zaman"
        // modunda eklenti tümünü basar (og basmayan temalar için).
        $skip = (string) $this->settings->get('og_mode', 'auto') === 'auto'
            ? self::themeTags()
            : [];

        $tags = [
            ['property', 'og:site_name', hi_site_name()],
            ['property', 'og:locale', str_replace('-', '_', $this->locale())],
            ['property', 'og:type', $entry !== null ? 'article' : 'website'],
            ['property', 'og:title', $this->titleText()],
            ['property', 'og:description', $this->descriptionText()],
            ['property', 'og:url', $canonical !== '' ? $canonical : hi()->urls()->to()],
        ];

        if ($image !== '') {
            $tags[] = ['property', 'og:image', $image];
        }

        if ($entry !== null) {
            $tags[] = ['property', 'article:published_time', Dates::iso($entry->publishedAt)];
            $tags[] = ['property', 'article:modified_time', Dates::iso(
                $entry->updatedAt !== '' ? $entry->updatedAt : $entry->publishedAt
            )];

            if ($entry->author !== null) {
                $tags[] = ['property', 'article:author', $entry->author->displayName];
            }
        }

        $tags[] = ['name', 'twitter:card', $image !== ''
            ? (string) $this->settings->get('twitter_card', 'summary_large_image')
            : 'summary'];
        $tags[] = ['name', 'twitter:title', $this->titleText()];
        $tags[] = ['name', 'twitter:description', $this->descriptionText()];

        if ($image !== '') {
            $tags[] = ['name', 'twitter:image', $image];
        }

        $handle = trim((string) $this->settings->get('twitter_site', ''));

        if ($handle !== '') {
            $tags[] = ['name', 'twitter:site', str_starts_with($handle, '@') ? $handle : '@' . $handle];
        }

        foreach ($tags as [$attribute, $name, $value]) {
            if ((string) $value === '' || isset($skip[$name])) {
                continue;
            }

            printf(
                '<meta %s="%s" content="%s">' . "\n",
                $attribute,
                Str::attr($name),
                Str::attr((string) $value)
            );
        }
    }

    /** Paylaşım görseli: içerik alanı → öne çıkan görsel → yedek ayar. */
    private function image(?Entry $entry): string
    {
        if ($entry !== null) {
            $custom = $this->entries->value($entry, 'seo_image');

            if ($custom !== '') {
                return $this->absolute($custom);
            }

            if ($entry->image !== null) {
                return hi()->urls()->uploads($entry->image->path);
            }
        }

        $fallback = trim((string) $this->settings->get('default_image', ''));

        return $fallback !== '' ? $this->absolute($fallback) : '';
    }

    private function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : hi()->urls()->to(ltrim($url, '/'));
    }

    /* ---------------------------------------------------------------------
     * Yapısal veri
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function structuredData(ViewContext $view, string $canonical): array
    {
        $site = hi_site_name();
        $home = hi()->urls()->to();

        if ($view->isSingular() && $view->entry !== null) {
            $entry = $view->entry;

            $data = [
                '@context'      => 'https://schema.org',
                '@type'         => $entry->type === 'page' ? 'WebPage' : 'BlogPosting',
                'headline'      => Str::limit($entry->title, 110, ''),
                'description'   => $this->descriptionText(),
                'url'           => $canonical !== '' ? $canonical : hi()->links()->forEntry($entry),
                'datePublished' => Dates::iso($entry->publishedAt),
                'dateModified'  => Dates::iso($entry->updatedAt !== '' ? $entry->updatedAt : $entry->publishedAt),
                'publisher'     => ['@type' => 'Organization', 'name' => $site, 'url' => $home],
                'inLanguage'    => $this->locale(),
            ];

            if ($entry->author !== null) {
                $data['author'] = [
                    '@type' => 'Person',
                    'name'  => $entry->author->displayName,
                    'url'   => hi()->links()->forAuthor($entry->author),
                ];
            }

            $image = $this->image($entry);

            if ($image !== '') {
                $data['image'] = $image;
            }

            $crumbs = $this->breadcrumb($entry);

            if ($crumbs !== []) {
                $data['breadcrumb'] = [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => $crumbs,
                ];
            }

            return $data;
        }

        if ($view->kind === ViewContext::HOME) {
            return [
                '@context'        => 'https://schema.org',
                '@type'           => 'WebSite',
                'name'            => $site,
                'url'             => $home,
                'description'     => $this->descriptionText(),
                'inLanguage'      => $this->locale(),
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    // Yer tutucu adresi üretildikten SONRA yerleştirilir; aksi
                    // hâlde süslü parantezler URL kaçışına girer.
                    'target'      => str_replace(
                        'HISEO_ARAMA',
                        '{search_term_string}',
                        hi()->links()->forSearch('HISEO_ARAMA')
                    ),
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        if ($view->is(ViewContext::ARCHIVE, ViewContext::TAXONOMY, ViewContext::AUTHOR)) {
            return [
                '@context'    => 'https://schema.org',
                '@type'       => 'CollectionPage',
                'name'        => $this->subject($view),
                'url'         => $canonical !== '' ? $canonical : $home,
                'description' => $this->descriptionText(),
                'isPartOf'    => ['@type' => 'WebSite', 'name' => $site, 'url' => $home],
            ];
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breadcrumb(Entry $entry): array
    {
        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => hi_site_name(),
            'item'     => hi()->urls()->to(),
        ]];

        $type = hi()->types()->get($entry->type);

        if ($type !== null && $type->hasArchive()) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => count($items) + 1,
                'name'     => $type->plural,
                'item'     => hi()->links()->forArchive($type),
            ];
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => count($items) + 1,
            'name'     => $entry->title,
            'item'     => hi()->links()->forEntry($entry),
        ];

        return $items;
    }
}
