<?php

declare(strict_types=1);

namespace HiCMS\Content;

use HiCMS\Http\Url;
use HiCMS\Model\Entry;
use HiCMS\Model\Term;
use HiCMS\Model\User;
use HiCMS\Repository\OptionRepository;

/**
 * Kalıcı bağlantı üretici.
 *
 * Bağlantı yapısı ayarlardan gelir ve rota tablosu da aynı yapıdan üretilir —
 * yani ayar değiştiğinde hem üretilen bağlantılar hem çözümlenen rotalar
 * birlikte değişir, ikisi asla ayrışmaz.
 *
 * Yapılar:
 *   route → /yazi/{slug}            (varsayılan, kısa ve okunur)
 *   date  → /2026/07/{slug}         (haber siteleri)
 *   flat  → /{slug}                 (yalnızca yazı türü için; çakışma riski var)
 */
final class Permalinks
{
    public const STRUCTURES = [
        'route' => '/{tur}/{kisa-ad}',
        'date'  => '/{yil}/{ay}/{kisa-ad}',
        'flat'  => '/{kisa-ad}',
    ];

    public function __construct(
        private readonly Url $url,
        private readonly TypeRegistry $types,
        private readonly OptionRepository $options,
    ) {
    }

    public function structure(): string
    {
        $structure = (string) $this->options->get('permalink_structure', 'route');

        return isset(self::STRUCTURES[$structure]) ? $structure : 'route';
    }

    /**
     * Bir içeriğin tam adresi.
     */
    public function forEntry(Entry $entry): string
    {
        return $this->url->to($this->pathForEntry($entry));
    }

    /**
     * Bir içeriğin site köküne göre yolu.
     */
    public function pathForEntry(Entry $entry): string
    {
        $type = $this->types->get($entry->type);

        // Rota öneki olmayan türler (sayfa) her zaman kökte oturur.
        if ($type !== null && $type->route === '') {
            return $this->pagePath($entry);
        }

        $prefix = $type?->route ?? $entry->type;

        return match ($this->structure()) {
            'date' => $this->datePath($entry),
            'flat' => $entry->slug,
            default => $prefix . '/' . $entry->slug,
        };
    }

    /**
     * Hiyerarşik sayfalar için üst sayfaları yola ekler: /hakkinda/ekibimiz
     */
    private function pagePath(Entry $entry): string
    {
        return $entry->slug;
    }

    private function datePath(Entry $entry): string
    {
        $time = strtotime($entry->publishedAt ?? $entry->createdAt);
        $time = $time !== false ? $time : time();

        return date('Y/m', $time) . '/' . $entry->slug;
    }

    /**
     * Taksonomi arşivi adresi.
     */
    public function forTerm(Term $term): string
    {
        $taxonomy = $this->types->taxonomy($term->taxonomy);
        $prefix   = $taxonomy?->route !== null && $taxonomy?->route !== ''
            ? $taxonomy->route
            : $term->taxonomy;

        return $this->url->to($prefix . '/' . $term->slug);
    }

    public function forTermPage(Term $term, int $page): string
    {
        $base = rtrim($this->forTerm($term), '/');

        return $page > 1 ? $base . '/sayfa/' . $page : $base;
    }

    /**
     * İçerik türü arşivi (ör. /portfolyo).
     */
    public function forArchive(ContentType $type, int $page = 1): string
    {
        if (!$type->hasArchive()) {
            return $this->url->to();
        }

        $base = (string) $type->archive;

        return $this->url->to($page > 1 ? $base . '/sayfa/' . $page : $base);
    }

    public function forAuthor(User $user, int $page = 1): string
    {
        $path = 'yazar/' . $user->slug;

        return $this->url->to($page > 1 ? $path . '/sayfa/' . $page : $path);
    }

    /**
     * Ana sayfa / blog akışı.
     */
    public function forHome(int $page = 1): string
    {
        return $this->url->to($page > 1 ? 'sayfa/' . $page : '');
    }

    public function forSearch(string $term, int $page = 1): string
    {
        $query = ['q' => $term];

        if ($page > 1) {
            $query['sayfa'] = (string) $page;
        }

        return $this->url->with('arama', $query);
    }

    public function forFeed(): string
    {
        return $this->url->to('feed');
    }

    /**
     * Panelde önizleme adresi — taslaklar için imzalı bağlantı.
     */
    public function preview(Entry $entry, string $token): string
    {
        return $this->url->with($this->pathForEntry($entry), ['onizleme' => $token]);
    }

    public function urls(): Url
    {
        return $this->url;
    }
}
