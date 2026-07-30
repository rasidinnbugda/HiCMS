<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;
use HiCMS\Model\Term;
use HiCMS\Support\Str;

/**
 * Taksonomi terimleri deposu.
 *
 * Sayımlar `term_entry` üzerinden, yalnızca görünür içerikler hesaba katılarak
 * yapılır; böylece taslak yazılar kategori sayısını şişirmez.
 */
final class TermRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function find(int $id): ?Term
    {
        $row = $this->db->builder('terms')->where('id', $id)->first();

        return $row !== null ? Term::fromRow($row) : null;
    }

    public function findBySlug(string $taxonomy, string $slug): ?Term
    {
        $row = $this->db->builder('terms')
            ->where('taxonomy', $taxonomy)
            ->where('slug', $slug)
            ->first();

        return $row !== null ? Term::fromRow($row) : null;
    }

    /**
     * Bir taksonominin tüm terimleri, içerik sayılarıyla.
     *
     * @return list<Term>
     */
    public function forTaxonomy(string $taxonomy, bool $withCounts = true, bool $onlyUsed = false): array
    {
        $prefix = $this->db->prefix();

        $sql = sprintf(
            'SELECT t.*, %s AS entry_count
             FROM `%sterms` t
             WHERE t.taxonomy = :taxonomy
             ORDER BY t.position ASC, t.name ASC',
            $withCounts
                ? sprintf(
                    '(SELECT COUNT(*) FROM `%1$sterm_entry` te
                       INNER JOIN `%1$scontent` c ON c.id = te.entry_id
                      WHERE te.term_id = t.id
                        AND c.status = \'published\'
                        AND (c.published_at IS NULL OR c.published_at <= NOW()))',
                    $prefix
                )
                : '0',
            $prefix
        );

        $terms = array_map([Term::class, 'fromRow'], $this->db->select($sql, ['taxonomy' => $taxonomy]));

        if ($onlyUsed) {
            $terms = array_values(array_filter($terms, static fn(Term $t): bool => $t->count > 0));
        }

        return $terms;
    }

    /**
     * Bir içeriğin terimleri.
     *
     * @return list<Term>
     */
    public function forEntry(int $entryId): array
    {
        $prefix = $this->db->prefix();

        $sql = sprintf(
            'SELECT t.* FROM `%1$sterms` t
             INNER JOIN `%1$sterm_entry` te ON te.term_id = t.id
             WHERE te.entry_id = :entry
             ORDER BY t.taxonomy ASC, te.position ASC, t.name ASC',
            $prefix
        );

        return array_map([Term::class, 'fromRow'], $this->db->select($sql, ['entry' => $entryId]));
    }

    /**
     * Birden çok içeriğin terimlerini tek sorguda getirir (N+1 önlemek için).
     *
     * @param list<int> $entryIds
     * @return array<int, list<Term>>
     */
    public function forEntries(array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));

        if ($entryIds === []) {
            return [];
        }

        $prefix       = $this->db->prefix();
        $placeholders = [];
        $bindings     = [];

        foreach ($entryIds as $index => $id) {
            $placeholders[]         = ':e' . $index;
            $bindings['e' . $index] = $id;
        }

        $sql = sprintf(
            'SELECT te.entry_id, t.* FROM `%1$sterms` t
             INNER JOIN `%1$sterm_entry` te ON te.term_id = t.id
             WHERE te.entry_id IN (%2$s)
             ORDER BY t.taxonomy ASC, te.position ASC, t.name ASC',
            $prefix,
            implode(', ', $placeholders)
        );

        $grouped = [];

        foreach ($this->db->select($sql, $bindings) as $row) {
            $grouped[(int) $row['entry_id']][] = Term::fromRow($row);
        }

        return $grouped;
    }

    /**
     * Terim oluşturur. Kısa ad boşsa addan üretir, çakışırsa sayı ekler.
     */
    public function create(Term $term): int
    {
        $term->slug = $this->uniqueSlug($term->taxonomy, $term->slug !== '' ? $term->slug : Str::slug($term->name));

        return $this->db->insert('terms', $term->toRow());
    }

    public function update(Term $term): bool
    {
        if ($term->id <= 0) {
            return false;
        }

        $term->slug = $this->uniqueSlug(
            $term->taxonomy,
            $term->slug !== '' ? $term->slug : Str::slug($term->name),
            $term->id
        );

        $this->db->update('terms', $term->toRow(), ['id' => $term->id]);

        return true;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->db->delete('term_entry', ['term_id' => $id]);
        $this->db->delete('terms', ['id' => $id]);

        // Alt terimleri köke taşı — sessizce kaybolmalarını istemiyoruz.
        $this->db->builder('terms')->where('parent_id', $id)->update(['parent_id' => 0]);

        return true;
    }

    /**
     * Bir içeriğin belirli taksonomilerdeki bağlarını yeniden kurar.
     *
     * @param array<string, list<int>> $termsByTaxonomy taksonomi → terim kimlikleri
     */
    public function syncEntry(int $entryId, array $termsByTaxonomy): void
    {
        if ($entryId <= 0) {
            return;
        }

        $taxonomies = array_keys($termsByTaxonomy);

        if ($taxonomies !== []) {
            // Yalnızca ilgilenilen taksonomilerdeki bağları sil; diğer eklentilerin
            // kurduğu bağlar korunur.
            $prefix       = $this->db->prefix();
            $placeholders = [];
            $bindings     = ['entry' => $entryId];

            foreach ($taxonomies as $index => $taxonomy) {
                $placeholders[]         = ':t' . $index;
                $bindings['t' . $index] = $taxonomy;
            }

            $this->db->statement(
                sprintf(
                    'DELETE te FROM `%1$sterm_entry` te
                     INNER JOIN `%1$sterms` t ON t.id = te.term_id
                     WHERE te.entry_id = :entry AND t.taxonomy IN (%2$s)',
                    $prefix,
                    implode(', ', $placeholders)
                ),
                $bindings
            );
        }

        $position = 0;

        foreach ($termsByTaxonomy as $termIds) {
            foreach (array_unique(array_filter(array_map('intval', $termIds))) as $termId) {
                $this->db->insert('term_entry', [
                    'term_id'  => $termId,
                    'entry_id' => $entryId,
                    'position' => $position++,
                ]);
            }
        }
    }

    /**
     * Ada göre terim bulur, yoksa oluşturur — etiket girişinde kullanılır.
     */
    public function findOrCreateByName(string $taxonomy, string $name): ?Term
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $slug     = Str::slug($name);
        $existing = $this->findBySlug($taxonomy, $slug);

        if ($existing !== null) {
            return $existing;
        }

        $term           = new Term();
        $term->taxonomy = $taxonomy;
        $term->name     = $name;
        $term->slug     = $slug;
        $term->id       = $this->create($term);

        return $term;
    }

    public function countIn(string $taxonomy): int
    {
        return $this->db->builder('terms')->where('taxonomy', $taxonomy)->count();
    }

    private function uniqueSlug(string $taxonomy, string $slug, int $ignoreId = 0): string
    {
        $slug = $slug !== '' ? $slug : 'terim';
        $base = $slug;
        $n    = 1;

        while (true) {
            $query = $this->db->builder('terms')->where('taxonomy', $taxonomy)->where('slug', $slug);

            if ($ignoreId > 0) {
                $query->where('id', '!=', $ignoreId);
            }

            if (!$query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . (++$n);
        }
    }
}
