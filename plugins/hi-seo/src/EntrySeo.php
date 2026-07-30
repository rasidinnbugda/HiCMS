<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Content\Field;
use HiCMS\Model\Entry;
use HiCMS\Support\Str;

/**
 * İçerik başına SEO alanları.
 *
 * DEPOLAMA: çekirdeğin `content_meta` tablosu. Ayrı bir tablo açılmadı çünkü
 * alanlar içeriğe ait: içerik silinince meta da silinir (ContentRepository
 * `delete()` zaten temizliyor), yedek ve göç aynı yolu izler.
 *
 * Temizleme `HiCMS\Content\Field` ile yapılır — panelin özel alanları için
 * zaten tanımlı olan kurallar. İkinci bir doğrulama seti doğmuyor.
 *
 * BOŞ DEĞER SATIR AÇMAZ: boşaltılan alanın meta satırı silinir. Böylece
 * "kaç içerikte arama açıklaması var" sorusu tek COUNT ile cevaplanabilir.
 */
final class EntrySeo
{
    public const TITLE_LIMIT       = 60;
    public const DESCRIPTION_LIMIT = 160;

    /** @var list<Field>|null */
    private static ?array $fields = null;

    /**
     * Alan tanımları.
     *
     * @return list<Field>
     */
    public static function fields(): array
    {
        return self::$fields ??= array_map(
            static fn(array $definition): Field => Field::fromArray($definition),
            [
                ['key' => 'seo_title', 'type' => 'text', 'label' => 'Arama başlığı',
                 'help' => 'Boşsa içeriğin kendi başlığı kullanılır.'],
                ['key' => 'seo_description', 'type' => 'textarea', 'label' => 'Arama açıklaması', 'rows' => 3,
                 'help' => 'Boşsa özet, o da yoksa içeriğin ilk satırları kullanılır.'],
                ['key' => 'seo_focus', 'type' => 'text', 'label' => 'Odak ifade',
                 'help' => 'Denetim bu ifadeyi başlıkta, açıklamada ve gövdede arar.'],
                ['key' => 'seo_canonical', 'type' => 'text', 'label' => 'Canonical adres',
                 'placeholder' => 'https://… veya /yol',
                 'help' => 'Aynı içerik başka bir adreste asıl kabul ediliyorsa doldurun.'],
                ['key' => 'seo_image', 'type' => 'text', 'label' => 'Paylaşım görseli',
                 'placeholder' => 'https://…/gorsel.jpg',
                 'help' => 'Boşsa öne çıkan görsel, o da yoksa yedek görsel kullanılır.'],
                ['key' => 'seo_noindex', 'type' => 'switch', 'label' => 'Dizine eklenmesin',
                 'default' => false,
                 'help' => 'noindex etiketi basılır ve içerik site haritasından düşer.'],
            ]
        );
    }

    public static function field(string $key): ?Field
    {
        foreach (self::fields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * İçeriğin SEO alanları (kayıtlı değer + varsayılan).
     *
     * @return array<string, mixed>
     */
    public function values(Entry $entry): array
    {
        $values = [];

        foreach (self::fields() as $field) {
            $stored = $entry->meta[$field->key] ?? null;

            $values[$field->key] = $field->type === 'switch'
                ? (bool) $stored
                : trim((string) (is_scalar($stored) ? $stored : ''));
        }

        return $values;
    }

    public function value(Entry $entry, string $key): string
    {
        $stored = $entry->meta[$key] ?? '';

        return trim((string) (is_scalar($stored) ? $stored : ''));
    }

    public function isNoindex(Entry $entry): bool
    {
        return (bool) ($entry->meta['seo_noindex'] ?? false);
    }

    /**
     * Gönderilen formu temizleyip saklar.
     *
     * Eksik anahtar kuralı ayar sözleşmesiyle aynı: işaretsiz anahtar POST'a
     * hiç girmediği için "kapalı", eksik metin alanı "boşaltılmış" sayılır.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> Saklanan değerler
     */
    public function save(int $entryId, array $input): array
    {
        $write = [];
        $clear = [];
        $clean = [];

        foreach (self::fields() as $field) {
            $value = $field->sanitize($input[$field->key] ?? null);

            $clean[$field->key] = $value;

            if ($field->isEmpty($value)) {
                $clear[] = $field->key;

                continue;
            }

            $write[$field->key] = $value;
        }

        if ($write !== []) {
            hi()->content()->saveMeta($entryId, $write);
        }

        foreach ($clear as $key) {
            hi()->content()->deleteMeta($entryId, $key);
        }

        return $clean;
    }

    /**
     * Arama sonucu önizlemesinin metinleri.
     *
     * @param array<string, mixed>|null $values
     * @return array{title: string, description: string, url: string}
     */
    public function preview(Entry $entry, ?array $values = null): array
    {
        $values = $values ?? $this->values($entry);

        $title = trim((string) ($values['seo_title'] ?? ''));
        $title = $title !== '' ? $title : $entry->title;

        $description = trim((string) ($values['seo_description'] ?? ''));
        $description = $description !== '' ? $description : $entry->summary(self::DESCRIPTION_LIMIT + 40);

        return [
            'title'       => $title,
            'description' => $description,
            'url'         => hi()->links()->forEntry($entry),
        ];
    }

    /**
     * Kısa denetim.
     *
     * @param array<string, mixed>|null $values
     * @return array{score: int, issues: list<array{level: string, text: string}>,
     *               title: string, description: string}
     */
    public function analyse(Entry $entry, ?array $values = null): array
    {
        $values  = $values ?? $this->values($entry);
        $preview = $this->preview($entry, $values);
        $issues  = [];

        $titleLength = mb_strlen($preview['title']);
        $descLength  = mb_strlen($preview['description']);

        if ($titleLength === 0) {
            $issues[] = ['level' => 'err', 'text' => 'Başlık yok.'];
        } elseif ($titleLength > self::TITLE_LIMIT) {
            $issues[] = ['level' => 'warn', 'text' => Str::format(
                'Başlık %d karakter; arama sonucunda %d karakterden sonrası kırpılır.',
                $titleLength,
                self::TITLE_LIMIT
            )];
        } elseif ($titleLength < 20) {
            $issues[] = ['level' => 'warn', 'text' => Str::format('Başlık kısa (%d karakter).', $titleLength)];
        }

        if ($descLength === 0) {
            $issues[] = ['level' => 'err', 'text' => 'Açıklama yok; arama sonucu metnini motor kendisi seçer.'];
        } elseif ($descLength > self::DESCRIPTION_LIMIT) {
            $issues[] = ['level' => 'warn', 'text' => Str::format(
                'Açıklama %d karakter; %d karakterden sonrası kırpılır.',
                $descLength,
                self::DESCRIPTION_LIMIT
            )];
        } elseif ($descLength < 70) {
            $issues[] = ['level' => 'warn', 'text' => Str::format('Açıklama kısa (%d karakter).', $descLength)];
        }

        $focus = trim((string) ($values['seo_focus'] ?? ''));

        if ($focus !== '') {
            $needle = mb_strtolower($focus);

            if (!str_contains(mb_strtolower($preview['title']), $needle)) {
                $issues[] = ['level' => 'warn', 'text' => 'Odak ifade başlıkta geçmiyor.'];
            }

            if (!str_contains(mb_strtolower($preview['description']), $needle)) {
                $issues[] = ['level' => 'warn', 'text' => 'Odak ifade açıklamada geçmiyor.'];
            }

            if (!str_contains(mb_strtolower($entry->plainText()), $needle)) {
                $issues[] = ['level' => 'warn', 'text' => 'Odak ifade içerik gövdesinde geçmiyor.'];
            }
        }

        if (mb_strlen($entry->slug) > 60) {
            $issues[] = ['level' => 'warn', 'text' => 'Kısa ad uzun; adres okunaksız kalır.'];
        }

        if (trim((string) ($values['seo_image'] ?? '')) === '' && $entry->image === null) {
            $issues[] = ['level' => 'warn', 'text' => 'Paylaşım görseli yok.'];
        }

        if (!empty($values['seo_noindex'])) {
            $issues[] = ['level' => 'info', 'text' => 'Bu içerik dizine eklenmiyor.'];
        }

        $score = 100;

        foreach ($issues as $issue) {
            $score -= match ($issue['level']) {
                'err'  => 25,
                'warn' => 10,
                default => 0,
            };
        }

        return [
            'score'       => max(0, $score),
            'issues'      => $issues,
            'title'       => $preview['title'],
            'description' => $preview['description'],
        ];
    }

    /**
     * Dizine eklenmesi kapatılmış içerik kimlikleri (site haritası dışlaması).
     *
     * @return list<int>
     */
    public function noindexIds(): array
    {
        $db = hi()->db();

        if (!$db->tableExists('content_meta')) {
            return [];
        }

        return array_values(array_map(
            'intval',
            $db->builder('content_meta')
                ->where('meta_key', 'seo_noindex')
                ->whereIn('meta_value', ['true', '1', '"1"'])
                ->pluck('entry_id')
        ));
    }

    /**
     * Kaç içerikte hangi alan dolu.
     *
     * @return array<string, int>
     */
    public function coverage(): array
    {
        $db     = hi()->db();
        $counts = ['seo_title' => 0, 'seo_description' => 0, 'seo_noindex' => 0];

        if (!$db->tableExists('content_meta')) {
            return $counts;
        }

        $rows = $db->builder('content_meta')
            ->select(['meta_key'])
            ->selectRaw('COUNT(*) AS total')
            ->whereIn('meta_key', array_keys($counts))
            ->whereIn('meta_value', ['""', 'null', 'false', '"0"'], true)
            ->groupBy('meta_key')
            ->get();

        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');

            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }
}
