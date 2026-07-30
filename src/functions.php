<?php

declare(strict_types=1);

/**
 * HiCMS — Tema API'si
 *
 * Temaların kullandığı kısa küresel fonksiyonlar. Hepsi çekirdek servislerine
 * ince bir sarmalayıcıdır: iş mantığı burada değil, ilgili sınıftadır. Amaç
 * şablon dosyalarının okunur kalması:
 *
 *     <?php while (hi_has_next()) : hi_the_entry(); ?>
 *         <h2><a href="<?= hi_permalink() ?>"><?= hi_title() ?></a></h2>
 *     <?php endwhile; ?>
 *
 * @package HiCMS
 */

use HiCMS\Content\ContentType;
use HiCMS\Kernel;
use HiCMS\Model\Comment;
use HiCMS\Model\Entry;
use HiCMS\Model\MediaItem;
use HiCMS\Model\Term;
use HiCMS\Model\User;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/* -------------------------------------------------------------------------
 * Çekirdek erişimi
 * ---------------------------------------------------------------------- */

function hi(): Kernel
{
    return Kernel::instance();
}

/* -------------------------------------------------------------------------
 * Kaçış
 * ---------------------------------------------------------------------- */

function esc_html(?string $value): string
{
    return Str::html($value);
}

function esc_attr(?string $value): string
{
    return Str::attr($value);
}

function esc_url(?string $value): string
{
    return Str::url($value);
}

function esc_json(mixed $value): string
{
    return Str::json($value);
}

/* -------------------------------------------------------------------------
 * Çeviri
 * ---------------------------------------------------------------------- */

function __(string $text): string
{
    return hi()->translator()->get($text);
}

function _e(string $text): void
{
    echo Str::html(hi()->translator()->get($text));
}

/** Yer tutuculu çeviri: __f('%d yazı bulundu', 12) */
function __f(string $text, mixed ...$args): string
{
    return hi()->translator()->format($text, ...$args);
}

function _n(string $single, string $plural, int $count): string
{
    return hi()->translator()->plural($single, $plural, $count);
}

/* -------------------------------------------------------------------------
 * Adresler ve ayarlar
 * ---------------------------------------------------------------------- */

function hi_url(string $path = ''): string
{
    return hi()->urls()->to($path);
}

function hi_admin_url(string $path = ''): string
{
    return hi()->urls()->admin($path);
}

/** Etkin temanın dosyası: hi_asset('assets/css/style.css') */
function hi_asset(string $path = ''): string
{
    return hi()->themes()->url($path);
}

function hi_uploads_url(string $path = ''): string
{
    return hi()->urls()->uploads($path);
}

function hi_option(string $key, mixed $default = null): mixed
{
    return hi()->options()->get($key, $default);
}

function hi_site_name(): string
{
    return hi()->siteName();
}

function hi_site_tagline(): string
{
    return hi()->siteTagline();
}

function hi_csrf_field(): string
{
    return hi()->csrf()->field();
}

/* -------------------------------------------------------------------------
 * Görünüm bağlamı
 * ---------------------------------------------------------------------- */

function hi_view(): HiCMS\Theme\ViewContext
{
    return hi()->view();
}

function hi_is_home(): bool
{
    return hi_view()->is('home');
}

function hi_is_single(): bool
{
    return hi_view()->is('single');
}

function hi_is_page(): bool
{
    return hi_view()->is('page');
}

function hi_is_singular(): bool
{
    return hi_view()->isSingular();
}

function hi_is_archive(): bool
{
    return hi_view()->is('archive');
}

function hi_is_taxonomy(): bool
{
    return hi_view()->is('taxonomy');
}

function hi_is_author(): bool
{
    return hi_view()->is('author');
}

function hi_is_search(): bool
{
    return hi_view()->is('search');
}

function hi_is_404(): bool
{
    return hi_view()->is('notfound');
}

function hi_is_paged(): bool
{
    return hi_view()->isPaged();
}

/** @return list<Entry> */
function hi_entries(): array
{
    return hi_view()->entries;
}

function hi_has_entries(): bool
{
    return !hi_view()->isEmpty();
}

/** Döngü: while (hi_has_next()) : hi_the_entry(); */
function hi_has_next(): bool
{
    return hi_view()->hasNext();
}

function hi_the_entry(): ?Entry
{
    return hi_view()->next();
}

function hi_rewind(): void
{
    hi_view()->rewind();
}

/**
 * Geçerli içerik: döngüdeki kayıt, yoksa tek içerik.
 */
function hi_entry(): ?Entry
{
    return hi_view()->current() ?? hi_view()->entry;
}

function hi_found(): int
{
    return hi_view()->total;
}

function hi_queried_term(): ?Term
{
    return hi_view()->term;
}

function hi_queried_author(): ?User
{
    return hi_view()->author;
}

function hi_queried_type(): ?ContentType
{
    return hi_view()->contentType;
}

function hi_search_term(): string
{
    return hi_view()->searchTerm;
}

function hi_featured_entry(): ?Entry
{
    return hi_view()->featured;
}

/* -------------------------------------------------------------------------
 * İçerik alanları
 * ---------------------------------------------------------------------- */

function hi_resolve(?Entry $entry = null): ?Entry
{
    return $entry ?? hi_entry();
}

function hi_id(?Entry $entry = null): int
{
    return hi_resolve($entry)?->id ?? 0;
}

function hi_title(?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? '' : Str::html((string) hi()->events()->filter('content.title', $entry->title, $entry));
}

function hi_raw_title(?Entry $entry = null): string
{
    return hi_resolve($entry)?->title ?? '';
}

function hi_permalink(?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? hi_url() : Str::url(hi()->links()->forEntry($entry));
}

/**
 * Blok ağacını HTML'e çevirir.
 */
function hi_content(?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    if ($entry === null) {
        return '';
    }

    $html = hi()->blockRenderer()->renderEntry($entry);

    return (string) hi()->events()->filter('content.body', $html, $entry);
}

function hi_excerpt(?Entry $entry = null, int $length = 180): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? '' : Str::html($entry->summary($length));
}

function hi_date(?Entry $entry = null, string $format = 'j F Y'): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? '' : Str::html(Dates::format($entry->publishedAt ?? $entry->createdAt, $format));
}

function hi_date_iso(?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? '' : Dates::iso($entry->publishedAt ?? $entry->createdAt);
}

function hi_time_ago(?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    return $entry === null ? '' : Str::html(Dates::ago($entry->publishedAt ?? $entry->createdAt));
}

function hi_author(?Entry $entry = null): ?User
{
    return hi_resolve($entry)?->author;
}

function hi_author_name(?Entry $entry = null): string
{
    return Str::html(hi_resolve($entry)?->author?->displayName ?? '');
}

function hi_author_url(?Entry $entry = null): string
{
    $author = hi_resolve($entry)?->author;

    return $author === null ? hi_url() : Str::url(hi()->links()->forAuthor($author));
}

function hi_author_initials(?Entry $entry = null): string
{
    return Str::html(hi_resolve($entry)?->author?->initials() ?? '?');
}

function hi_reading_time(?Entry $entry = null): int
{
    return hi_resolve($entry)?->readingTime() ?? 1;
}

function hi_views(?Entry $entry = null): int
{
    return hi_resolve($entry)?->views ?? 0;
}

function hi_meta(string $key, mixed $default = null, ?Entry $entry = null): mixed
{
    return hi_resolve($entry)?->meta($key, $default) ?? $default;
}

/* -------------------------------------------------------------------------
 * Taksonomi
 * ---------------------------------------------------------------------- */

/** @return list<Term> */
function hi_terms(string $taxonomy = 'category', ?Entry $entry = null): array
{
    return hi_resolve($entry)?->termsIn($taxonomy) ?? [];
}

function hi_primary_term(string $taxonomy = 'category', ?Entry $entry = null): ?Term
{
    return hi_resolve($entry)?->primaryTerm($taxonomy);
}

function hi_term_url(Term $term): string
{
    return Str::url(hi()->links()->forTerm($term));
}

/**
 * Kategori bağlantısını basar.
 */
function hi_the_term(string $taxonomy = 'category', string $class = 'entry-term', ?Entry $entry = null): string
{
    $term = hi_primary_term($taxonomy, $entry);

    if ($term === null) {
        return '';
    }

    return sprintf(
        '<a class="%s" href="%s"%s>%s</a>',
        Str::attr($class),
        Str::url(hi()->links()->forTerm($term)),
        $term->color !== '' ? ' style="--term-color: ' . Str::attr($term->color) . '"' : '',
        Str::html($term->name)
    );
}

/**
 * Etiket bağlantılarını basar.
 */
function hi_the_terms(string $taxonomy = 'tag', string $class = 'entry-tag', ?Entry $entry = null): string
{
    $html = '';

    foreach (hi_terms($taxonomy, $entry) as $term) {
        $html .= sprintf(
            '<a class="%s" href="%s">%s</a>',
            Str::attr($class),
            Str::url(hi()->links()->forTerm($term)),
            Str::html($term->name)
        );
    }

    return $html;
}

/* -------------------------------------------------------------------------
 * Görseller
 * ---------------------------------------------------------------------- */

function hi_has_thumbnail(?Entry $entry = null): bool
{
    return hi_resolve($entry)?->image !== null;
}

function hi_thumbnail_url(?Entry $entry = null): string
{
    $image = hi_resolve($entry)?->image;

    return $image === null ? '' : hi()->urls()->uploads($image->path);
}

/**
 * Öne çıkan görseli basar. Görsel yoksa kategori renginde bir yer tutucu üretir,
 * böylece liste düzeni bozulmaz.
 */
function hi_thumbnail(
    ?Entry $entry = null,
    string $sizes = '(max-width: 720px) 100vw, 720px',
    string $class = 'entry-thumb',
): string {
    $entry = hi_resolve($entry);

    if ($entry === null) {
        return '';
    }

    if ($entry->image instanceof MediaItem) {
        return hi()->blockRenderer()->image($entry->image, $sizes, $class);
    }

    $term  = $entry->primaryTerm();
    $color = $term?->color !== null && $term?->color !== '' ? $term->color : '#95389e';

    return sprintf(
        '<span class="%s %s-placeholder" style="--term-color: %s" aria-hidden="true"><span>%s</span></span>',
        Str::attr($class),
        Str::attr($class),
        Str::attr($color),
        Str::html(mb_substr($entry->title !== '' ? $entry->title : 'H', 0, 1, 'UTF-8'))
    );
}

/* -------------------------------------------------------------------------
 * Sınıf adları ve belge başlığı
 * ---------------------------------------------------------------------- */

/**
 * @param list<string> $extra
 */
function hi_body_class(array $extra = []): string
{
    $classes = array_merge(hi_view()->bodyClasses(), $extra);
    $classes = (array) hi()->events()->filter('theme.body_class', $classes);

    return 'class="' . Str::attr(implode(' ', array_unique($classes))) . '"';
}

/**
 * @param list<string> $extra
 */
function hi_entry_class(array $extra = [], ?Entry $entry = null): string
{
    $entry = hi_resolve($entry);

    if ($entry === null) {
        return 'class="' . Str::attr(implode(' ', $extra)) . '"';
    }

    $classes = array_merge([
        'entry',
        'entry-' . $entry->id,
        'type-' . $entry->type,
        'status-' . $entry->status,
    ], $extra);

    if ($entry->featured) {
        $classes[] = 'is-featured';
    }

    if ($entry->image === null) {
        $classes[] = 'has-no-thumb';
    }

    $term = $entry->primaryTerm();

    if ($term !== null) {
        $classes[] = 'term-' . $term->slug;
    }

    $classes = (array) hi()->events()->filter('theme.entry_class', $classes, $entry);

    return 'class="' . Str::attr(implode(' ', array_unique($classes))) . '"';
}

function hi_document_title(): string
{
    $view = hi_view();
    $site = hi_site_name();

    $title = match ($view->kind) {
        'single', 'page' => ($view->entry?->title ?? '') . ' — ' . $site,
        'taxonomy'       => ($view->term?->name ?? '') . ' — ' . $site,
        'archive'        => ($view->contentType?->plural ?? '') . ' — ' . $site,
        'author'         => ($view->author?->displayName ?? '') . ' — ' . $site,
        'search'         => __f('"%s" için arama sonuçları', $view->searchTerm) . ' — ' . $site,
        'notfound'       => __('Sayfa bulunamadı') . ' — ' . $site,
        default          => hi_site_tagline() !== '' ? $site . ' — ' . hi_site_tagline() : $site,
    };

    if ($view->isPaged()) {
        $title = __f('Sayfa %d', $view->page) . ' — ' . $title;
    }

    return Str::html((string) hi()->events()->filter('theme.document_title', $title));
}

function hi_meta_description(): string
{
    $view = hi_view();

    $description = match ($view->kind) {
        'single', 'page' => $view->entry?->summary(155) ?? '',
        'taxonomy'       => $view->term?->description ?? '',
        'archive'        => $view->contentType?->description ?? '',
        'author'         => $view->author?->bio ?? '',
        default          => (string) hi_option('site_description', hi_site_tagline()),
    };

    if (trim($description) === '') {
        $description = (string) hi_option('site_description', hi_site_tagline());
    }

    return Str::attr((string) hi()->events()->filter('theme.meta_description', $description));
}

/* -------------------------------------------------------------------------
 * Şablon parçaları
 * ---------------------------------------------------------------------- */

function hi_head(): void
{
    echo '<meta name="generator" content="HiCMS ' . Str::attr(Kernel::VERSION) . '">' . "\n";
    echo hi()->assets()->renderHead();

    hi()->events()->emit('theme.head');
}

function hi_foot(): void
{
    echo hi()->assets()->renderFooter();

    hi()->events()->emit('theme.foot');
}

function hi_header(string $name = ''): void
{
    hi()->templates()->header($name);
}

function hi_footer(string $name = ''): void
{
    hi()->templates()->footer($name);
}

function hi_sidebar(string $name = ''): void
{
    hi()->templates()->sidebar($name);
}

/**
 * @param array<string, mixed> $data
 */
function hi_part(string $slug, string $name = '', array $data = []): void
{
    hi()->templates()->part($slug, $name, $data);
}

function hi_comments_template(): void
{
    $entry = hi_entry();

    if ($entry === null) {
        return;
    }

    hi()->templates()->comments(['entry' => $entry]);
}

/* -------------------------------------------------------------------------
 * Menüler ve bileşenler
 * ---------------------------------------------------------------------- */

/**
 * @param array<string, mixed> $args
 */
function hi_menu(string $location, array $args = []): string
{
    $args['current'] ??= hi()->request()->path;

    return hi()->menus()->render($location, $args);
}

function hi_has_menu(string $location): bool
{
    return hi()->menus()->hasLocation($location);
}

function hi_widgets(string $areaId): string
{
    return hi()->widgets()->renderArea($areaId);
}

function hi_has_widgets(string $areaId): bool
{
    return hi()->widgets()->isActive($areaId);
}

/* -------------------------------------------------------------------------
 * Yorumlar
 * ---------------------------------------------------------------------- */

/** @return list<Comment> */
function hi_comments(?Entry $entry = null): array
{
    $entry = hi_resolve($entry);

    return $entry === null ? [] : hi()->comments()->treeFor($entry->id);
}

function hi_comment_count(?Entry $entry = null): int
{
    return hi_resolve($entry)?->commentCount ?? 0;
}

function hi_comments_open(?Entry $entry = null): bool
{
    $entry = hi_resolve($entry);

    if ($entry === null) {
        return false;
    }

    $type = hi()->types()->get($entry->type);

    return $entry->commentsOpen
        && (bool) hi_option('comments_open', true)
        && ($type === null || $type->hasComments);
}

/* -------------------------------------------------------------------------
 * Sayfalama
 * ---------------------------------------------------------------------- */

/**
 * Sayfalama bağlantılarını üretir.
 */
function hi_pagination(): string
{
    $view = hi_view();

    if ($view->pages < 2) {
        return '';
    }

    $current = $view->page;
    $total   = $view->pages;
    $links   = hi()->links();

    $urlFor = static function (int $page) use ($view, $links): string {
        return match ($view->kind) {
            'taxonomy' => $view->term !== null ? $links->forTermPage($view->term, $page) : $links->forHome($page),
            'archive'  => $view->contentType !== null ? $links->forArchive($view->contentType, $page) : $links->forHome($page),
            'author'   => $view->author !== null ? $links->forAuthor($view->author, $page) : $links->forHome($page),
            'search'   => $links->forSearch($view->searchTerm, $page),
            default    => $links->forHome($page),
        };
    };

    $html = '<nav class="pagination" aria-label="' . esc_attr(__('Sayfalama')) . '">';

    if ($current > 1) {
        $html .= '<a class="pagination-link pagination-prev" rel="prev" href="' . Str::url($urlFor($current - 1)) . '">'
            . '<span aria-hidden="true">←</span> ' . esc_html(__('Önceki')) . '</a>';
    }

    $html .= '<span class="pagination-numbers">';

    for ($page = 1; $page <= $total; $page++) {
        $isEdge = $page === 1 || $page === $total;
        $isNear = abs($page - $current) <= 1;

        if (!$isEdge && !$isNear) {
            if (abs($page - $current) === 2) {
                $html .= '<span class="pagination-gap">…</span>';
            }

            continue;
        }

        $html .= $page === $current
            ? '<span class="pagination-link is-current" aria-current="page">' . $page . '</span>'
            : '<a class="pagination-link" href="' . Str::url($urlFor($page)) . '">' . $page . '</a>';
    }

    $html .= '</span>';

    if ($current < $total) {
        $html .= '<a class="pagination-link pagination-next" rel="next" href="' . Str::url($urlFor($current + 1)) . '">'
            . esc_html(__('Sonraki')) . ' <span aria-hidden="true">→</span></a>';
    }

    return $html . '</nav>';
}

/* -------------------------------------------------------------------------
 * Kancalar (tema ve eklentiler için)
 * ---------------------------------------------------------------------- */

/** Tipli olaya dinleyici bağlar. */
function hi_listen(string $eventClass, callable $listener, int $priority = 10): void
{
    hi()->events()->listen($eventClass, $listener, $priority);
}

/** Adlandırılmış kancaya dinleyici bağlar. */
function hi_on(string $hook, callable $listener, int $priority = 10): void
{
    hi()->events()->on($hook, $listener, $priority);
}

function hi_emit(string $hook, mixed ...$args): void
{
    hi()->events()->emit($hook, ...$args);
}

function hi_add_filter(string $hook, callable $listener, int $priority = 10): void
{
    hi()->events()->addFilter($hook, $listener, $priority);
}

function hi_filter(string $hook, mixed $value, mixed ...$args): mixed
{
    return hi()->events()->filter($hook, $value, ...$args);
}

/* -------------------------------------------------------------------------
 * Tema kaydı (functions.php içinde kullanılır)
 * ---------------------------------------------------------------------- */

function hi_theme_support(string $feature, mixed $value = true): void
{
    hi()->themes()->addSupport($feature, $value);
}

function hi_supports(string $feature): bool
{
    return hi()->themes()->supports($feature);
}

/**
 * @param array<string, string> $locations
 */
function hi_register_menus(array $locations): void
{
    hi()->menus()->registerLocations($locations);
}

/**
 * @param array{id: string, name?: string, description?: string} $args
 */
function hi_register_widget_area(array $args): string
{
    return hi()->widgets()->registerArea($args);
}

/**
 * @param array{label: string, icon?: string, fields?: list<array<string, mixed>>, render: callable} $definition
 */
function hi_register_widget_type(string $type, array $definition): void
{
    hi()->widgets()->registerType($type, $definition);
}

/**
 * @param array{label: string, render: callable, icon?: string, group?: string, fields?: list<array<string, mixed>>} $definition
 */
function hi_register_block(string $type, array $definition): void
{
    hi()->blocks()->register($type, $definition);
}

/**
 * @param array<string, mixed> $definition
 */
function hi_register_content_type(array $definition, string $source = 'theme'): void
{
    hi()->types()->registerArray($definition, $source);
}

/**
 * @param array<string, mixed> $definition
 */
function hi_register_taxonomy(array $definition, string $source = 'theme'): void
{
    hi()->types()->registerTaxonomy(HiCMS\Content\Taxonomy::fromArray($definition, $source));
}

/**
 * @param list<string> $deps
 */
function hi_enqueue_style(string $handle, string $src, array $deps = [], ?string $version = null): void
{
    hi()->assets()->style($handle, $src, $deps, $version);
}

/**
 * @param list<string> $deps
 */
function hi_enqueue_script(
    string $handle,
    string $src,
    array $deps = [],
    ?string $version = null,
    bool $footer = true,
): void {
    hi()->assets()->script($handle, $src, $deps, $version, $footer);
}

function hi_inline_style(string $handle, string $css): void
{
    hi()->assets()->inlineStyle($handle, $css);
}

function hi_inline_script(string $handle, string $js): void
{
    hi()->assets()->inlineScript($handle, $js);
}

/* -------------------------------------------------------------------------
 * Sorgu yardımcıları (tema içinde ek listeler için)
 * ---------------------------------------------------------------------- */

/**
 * Tema içinde ek içerik listesi çeker.
 *
 * @param array<string, mixed> $args
 * @return list<Entry>
 */
function hi_query(array $args = []): array
{
    $args['visibleOnly'] ??= true;

    return hi()->content()->get($args);
}

/**
 * @return list<Term>
 */
function hi_all_terms(string $taxonomy = 'category', bool $onlyUsed = true): array
{
    return hi()->terms()->forTaxonomy($taxonomy, true, $onlyUsed);
}

function hi_related(int $limit = 3, ?Entry $entry = null): array
{
    $entry = hi_resolve($entry);

    return $entry === null ? [] : hi()->content()->related($entry, $limit);
}

function hi_adjacent(string $direction = 'next', ?Entry $entry = null): ?Entry
{
    $entry = hi_resolve($entry);

    return $entry === null ? null : hi()->content()->adjacent($entry, $direction);
}

/**
 * Geçerli içeriği elle ayarlar (özel döngüler için).
 */
function hi_setup_entry(Entry $entry): void
{
    hi_view()->entries[] = $entry;
    hi_view()->next();
}
