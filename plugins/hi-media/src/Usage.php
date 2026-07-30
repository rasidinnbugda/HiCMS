<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Database\Connection;
use HiCMS\Model\MediaItem;

/**
 * Kullanım taraması — hangi medya hiçbir yerde geçmiyor?
 *
 * İki farklı başvuru biçimi aranır, çünkü içerik ikisini de üretiyor:
 *
 *   1. KİMLİK — blok verisinde `mediaId`/`mediaIds`, içeriğin `media_id`
 *      sütunu, özel alanların (`media`, `media-list`) meta değeri.
 *   2. YOL — panoya yapıştırılmış ya da elle yazılmış `2026/07/dosya.jpg`
 *      biçimindeki adresler. Zengin metin ve bileşen ayarları böyle taşır.
 *
 * Yalnızca kimliğe bakmak, yazının içinde adresle gömülü bir görseli
 * "kullanılmıyor" göstermeye ve kullanıcının onu silmesine yol açardı.
 *
 * Sürüm geçmişi de taranır: eski bir sürümde geçen dosya silinirse o sürümü
 * geri yüklemek kırık görselle döner.
 */
final class Usage
{
    /** @var array{ids: array<int, bool>, paths: array<string, bool>}|null */
    private ?array $references = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{ids: array<int, bool>, paths: array<string, bool>}
     */
    public function references(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }

        $ids   = [];
        $paths = [];

        // 1. Öne çıkan görsel sütunu.
        foreach ($this->db->builder('content')->where('media_id', '>', 0)->pluck('media_id') as $id) {
            $ids[(int) $id] = true;
        }

        // 2. Blok ağaçları (içerik + sürüm geçmişi) ve özel alanlar.
        $this->scanColumn('content', 'blocks', $ids, $paths);

        if ($this->db->tableExists('revisions')) {
            $this->scanColumn('revisions', 'blocks', $ids, $paths);
        }

        $this->scanColumn('content_meta', 'meta_value', $ids, $paths);

        // 3. Site ayarları ve bileşen tanımları (logo, favicon, bileşen görseli).
        $this->scanColumn('options', 'value', $ids, $paths, idKeysOnly: true);

        return $this->references = ['ids' => $ids, 'paths' => $paths];
    }

    public function isUsed(MediaItem $item): bool
    {
        $references = $this->references();

        if (isset($references['ids'][$item->id]) || isset($references['paths'][$item->path])) {
            return true;
        }

        /*
         * TÜREV ÜZERİNDEN KULLANIM. İçerikte "2026/07/foo-800w.webp" geçiyorsa
         * özgün "foo.jpg" de kullanılıyor demektir; ölçü ekiyle başlayan her yol
         * özgüne sayılır. Bu denetim olmadan kullanıcı, sayfada görünen bir
         * görselin özgününü "kullanılmıyor" diye silebilirdi.
         */
        $stem = $this->stem($item);

        if ($stem === '') {
            return false;
        }

        foreach (array_keys($references['paths']) as $path) {
            if (str_starts_with($path, $stem)) {
                return true;
            }
        }

        return false;
    }

    /** "2026/07/foo.jpg" → "2026/07/foo-" */
    private function stem(MediaItem $item): string
    {
        $name = pathinfo($item->filename, PATHINFO_FILENAME);

        if ($name === '' || $item->path === '') {
            return '';
        }

        $directory = trim(dirname($item->path), '/.');

        return ($directory !== '' ? $directory . '/' : '') . $name . '-';
    }

    /**
     * Hiçbir yerde geçmeyen medya kayıtları.
     *
     * @return list<MediaItem>
     */
    public function unused(int $limit = 100): array
    {
        $unused = [];
        $offset = 0;

        while (count($unused) < $limit) {
            $rows = $this->db->builder('media')
                ->orderBy('id')
                ->limit(500)
                ->offset($offset)
                ->get();

            if ($rows === []) {
                break;
            }

            $offset += 500;

            foreach ($rows as $row) {
                $item = MediaItem::fromRow($row);

                if (!$this->isUsed($item)) {
                    $unused[] = $item;

                    if (count($unused) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $unused;
    }

    /** Kullanılmayan kayıt sayısı (tamamı taranır). */
    public function countUnused(): int
    {
        $count  = 0;
        $offset = 0;

        while (true) {
            $rows = $this->db->builder('media')
                ->orderBy('id')
                ->limit(500)
                ->offset($offset)
                ->get();

            if ($rows === []) {
                return $count;
            }

            $offset += 500;

            foreach ($rows as $row) {
                if (!$this->isUsed(MediaItem::fromRow($row))) {
                    $count++;
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Tarama
     * ------------------------------------------------------------------ */

    /**
     * Bir metin sütununu parça parça tarar.
     *
     * @param array<int, bool> $ids
     * @param array<string, bool> $paths
     */
    private function scanColumn(
        string $table,
        string $column,
        array &$ids,
        array &$paths,
        bool $idKeysOnly = false,
    ): void {
        if (!$this->db->tableExists($table)) {
            return;
        }

        $offset = 0;

        while (true) {
            $rows = $this->db->builder($table)
                ->select(['`' . $column . '`'])
                ->limit(200)
                ->offset($offset)
                ->get();

            if ($rows === []) {
                return;
            }

            $offset += 200;

            foreach ($rows as $row) {
                $raw = (string) (reset($row) ?: '');

                if ($raw === '') {
                    continue;
                }

                $this->collectPaths($raw, $paths);

                if ($idKeysOnly) {
                    $this->collectKeyedIds($raw, $ids);

                    continue;
                }

                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $this->walk($decoded, $ids);

                    continue;
                }

                // JSON değil: düz sayı bir medya kimliği olabilir (media alanı).
                if (ctype_digit(trim($raw))) {
                    $ids[(int) trim($raw)] = true;
                }
            }
        }
    }

    /**
     * Blok/alan ağacında medya kimliklerini toplar.
     *
     * @param array<mixed> $node
     * @param array<int, bool> $ids
     */
    private function walk(array $node, array &$ids): void
    {
        foreach ($node as $key => $value) {
            $isMediaKey = is_string($key) && str_contains(strtolower($key), 'media');

            if (is_array($value)) {
                if ($isMediaKey) {
                    foreach ($value as $candidate) {
                        if (is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) {
                            $ids[(int) $candidate] = true;
                        }
                    }
                }

                $this->walk($value, $ids);

                continue;
            }

            if (!$isMediaKey) {
                continue;
            }

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[(int) $value] = true;
            }
        }
    }

    /**
     * `"media_id": 12` / `"logo_id":"12"` gibi ayar biçimlerinden kimlik toplar.
     *
     * @param array<int, bool> $ids
     */
    private function collectKeyedIds(string $raw, array &$ids): void
    {
        if (preg_match_all('/"[^"]*(?:media|logo|image|gorsel|favicon)[^"]*"\s*:\s*"?(\d+)"?/i', $raw, $matches) < 1) {
            return;
        }

        foreach ($matches[1] as $id) {
            $ids[(int) $id] = true;
        }
    }

    /**
     * Metindeki yükleme yollarını toplar.
     *
     * JSON kodlamasında eğik çizgi `\/` olarak kaçırıldığı için önce düzeltilir.
     *
     * @param array<string, bool> $paths
     */
    private function collectPaths(string $raw, array &$paths): void
    {
        if (!str_contains($raw, '/')) {
            return;
        }

        $text = str_replace('\\/', '/', $raw);

        if (preg_match_all('~(\d{4}/\d{2}/[\w.@-]+\.[A-Za-z0-9]{2,5})~', $text, $matches) < 1) {
            return;
        }

        foreach ($matches[1] as $path) {
            $paths[$path] = true;
        }
    }
}
