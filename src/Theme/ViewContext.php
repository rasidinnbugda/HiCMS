<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use HiCMS\Content\ContentType;
use HiCMS\Model\Entry;
use HiCMS\Model\Term;
use HiCMS\Model\User;

/**
 * Geçerli isteğin görünüm bağlamı.
 *
 * Şablonlar bu nesneye doğrudan değil, `hi_is_single()` / `hi_entries()` gibi
 * fonksiyonlarla erişir. Yönlendirici isteği çözdükten sonra bağlamı doldurur;
 * şablon yalnızca hazır veriyi basar — şablon içinde sorgu kurulmaz.
 */
final class ViewContext
{
    public const HOME     = 'home';
    public const SINGLE   = 'single';
    public const PAGE     = 'page';
    public const ARCHIVE  = 'archive';
    public const TAXONOMY = 'taxonomy';
    public const AUTHOR   = 'author';
    public const SEARCH   = 'search';
    public const NOTFOUND = 'notfound';

    public string $kind = self::HOME;

    /** @var list<Entry> */
    public array $entries = [];

    public ?Entry $entry = null;

    public ?Term $term = null;

    public ?User $author = null;

    public ?ContentType $contentType = null;

    public int $page = 1;

    public int $perPage = 10;

    public int $total = 0;

    public int $pages = 1;

    public string $searchTerm = '';

    /** Manşete çıkarılan içerik (ana sayfada listeden düşülür). */
    public ?Entry $featured = null;

    /** Döngü imleci */
    private int $cursor = -1;

    public function is(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }

    public function isSingular(): bool
    {
        return $this->is(self::SINGLE, self::PAGE);
    }

    public function isListing(): bool
    {
        return $this->is(self::HOME, self::ARCHIVE, self::TAXONOMY, self::AUTHOR, self::SEARCH);
    }

    public function isPaged(): bool
    {
        return $this->page > 1;
    }

    /* ---------------------------------------------------------------------
     * Döngü
     * ------------------------------------------------------------------ */

    public function hasNext(): bool
    {
        return ($this->cursor + 1) < count($this->entries);
    }

    public function next(): ?Entry
    {
        $this->cursor++;

        return $this->entries[$this->cursor] ?? null;
    }

    public function current(): ?Entry
    {
        return $this->entries[$this->cursor] ?? null;
    }

    public function rewind(): void
    {
        $this->cursor = -1;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Şablon hiyerarşisi adaylarını üretir.
     *
     * @return list<string>
     */
    public function templateCandidates(): array
    {
        return match ($this->kind) {
            self::SINGLE => array_values(array_filter([
                $this->entry !== null && $this->entry->template !== '' ? $this->entry->template : null,
                $this->entry !== null ? "single-{$this->entry->type}-{$this->entry->slug}.php" : null,
                $this->entry !== null ? "single-{$this->entry->type}.php" : null,
                'single.php',
                'index.php',
            ])),
            self::PAGE => array_values(array_filter([
                $this->entry !== null && $this->entry->template !== '' ? $this->entry->template : null,
                $this->entry !== null ? "page-{$this->entry->slug}.php" : null,
                'page.php',
                'single.php',
                'index.php',
            ])),
            self::ARCHIVE => array_values(array_filter([
                $this->contentType !== null ? "archive-{$this->contentType->name}.php" : null,
                'archive.php',
                'index.php',
            ])),
            self::TAXONOMY => array_values(array_filter([
                $this->term !== null ? "taxonomy-{$this->term->taxonomy}-{$this->term->slug}.php" : null,
                $this->term !== null ? "taxonomy-{$this->term->taxonomy}.php" : null,
                'taxonomy.php',
                'archive.php',
                'index.php',
            ])),
            self::AUTHOR   => ['author.php', 'archive.php', 'index.php'],
            self::SEARCH   => ['search.php', 'archive.php', 'index.php'],
            self::NOTFOUND => ['404.php', 'index.php'],
            default        => ['home.php', 'index.php'],
        };
    }

    /**
     * <body> sınıfları.
     *
     * @return list<string>
     */
    public function bodyClasses(): array
    {
        $classes = ['hi-site', 'view-' . $this->kind];

        if ($this->isPaged()) {
            $classes[] = 'is-paged';
        }

        if ($this->entry !== null) {
            $classes[] = 'type-' . $this->entry->type;
            $classes[] = 'entry-' . $this->entry->slug;
        }

        if ($this->term !== null) {
            $classes[] = 'term-' . $this->term->taxonomy . '-' . $this->term->slug;
        }

        if ($this->contentType !== null) {
            $classes[] = 'archive-' . $this->contentType->name;
        }

        return $classes;
    }
}
