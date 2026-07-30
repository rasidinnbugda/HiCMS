<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Extension\Settings;
use HiCMS\Model\MediaItem;
use HiCMS\Support\Str;
use Throwable;

/**
 * Üretim boru hattı: ayarları oku → kopyaları üret → kaydı yaz.
 *
 * Hem yükleme anındaki tek dosya hem panelden yürütülen toplu işlem aynı
 * yoldan geçer; iki ayrı davranış olmasın diye tek yöntem var.
 */
final class Pipeline
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
    ) {
    }

    /** Yükleme anında otomatik üretim açık mı? */
    public function isAutomatic(): bool
    {
        return (bool) $this->settings->get('auto', true);
    }

    /**
     * Ayarlardan kurulmuş işleyici.
     *
     * `himedia.widths` süzgeciyle tema ya da başka bir eklenti genişlik
     * listesini değiştirebilir.
     */
    public function processor(): Processor
    {
        return new Processor(
            uploadsDir: hi()->uploader()->uploadsDir(),
            widths: $this->widths(),
            webp: (bool) $this->settings->get('webp', true),
            avif: (bool) $this->settings->get('avif', false),
            quality: (int) $this->settings->get('quality', 82),
            avifQuality: (int) $this->settings->get('avif_quality', 52),
        );
    }

    /** @return list<int> */
    public function widths(): array
    {
        $raw = $this->settings->get('widths', []);
        $raw = is_array($raw) ? $raw : (preg_split('/[\s,]+/', (string) $raw) ?: []);

        $widths = [];

        foreach ($raw as $value) {
            $width = (int) $value;

            if ($width >= 32 && $width <= 8192) {
                $widths[$width] = $width;
            }
        }

        if ($widths === []) {
            $widths = [480 => 480, 800 => 800, 1280 => 1280, 1920 => 1920];
        }

        ksort($widths);

        /** @var list<int> $filtered */
        $filtered = array_values(array_map('intval', (array) hi_filter('himedia.widths', array_values($widths))));

        return $filtered;
    }

    /**
     * Bir görselin kopyalarını üretir ve kaydeder.
     *
     * @return array{ok: bool, reason: string, count: int, bytes: int}
     */
    public function run(MediaItem $item, bool $persistSizes = true, bool $audit = true): array
    {
        try {
            $result = $this->processor()->derive($item);
        } catch (Throwable $exception) {
            // Bozuk bir dosya yüzünden yükleme ya da toplu işlem düşmesin.
            return ['ok' => false, 'reason' => $exception->getMessage(), 'count' => 0, 'bytes' => 0];
        }

        if (!$result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'count' => 0, 'bytes' => 0];
        }

        $this->sweepStaleFiles($item, $result['variants']);
        $this->store->replaceVariants($item->id, $result['variants']);

        $item->sizes = $result['sizes'];

        if ($persistSizes) {
            hi()->mediaRepo()->updateSizes($item->id, $result['sizes']);
        }

        if ($audit) {
            $this->record(
                Str::format('%d kopya üretildi: %s', count($result['variants']), $item->filename),
                $item->id
            );
        }

        return [
            'ok'     => true,
            'reason' => '',
            'count'  => count($result['variants']),
            'bytes'  => $result['bytes'],
        ];
    }

    /**
     * Bir medyanın diskteki tüm kopyalarını siler (kayıt tablosuna göre).
     *
     * @return int silinen dosya sayısı
     */
    public function purgeFiles(int $mediaId): int
    {
        $uploads = hi()->uploader()->uploadsDir();
        $removed = 0;

        foreach ($this->store->variantsFor($mediaId) as $variant) {
            $file = (string) ($variant['file'] ?? '');

            if ($file !== '' && is_file($uploads . '/' . $file) && @unlink($uploads . '/' . $file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function record(string $summary, int $mediaId = 0): void
    {
        hi()->audit()->record(
            action: 'media.derivatives',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'media',
            subjectId: $mediaId,
            summary: $summary,
        );
    }

    /**
     * Önceki üretimden kalan, yeni kümede olmayan dosyaları siler.
     *
     * Genişlik listesi ya da format ayarı değiştiğinde eski dosyalar diskte
     * kalıyordu; kimse onlara bakmadığı için sessizce yer yiyorlardı.
     *
     * @param list<array{file: string}> $variants
     */
    private function sweepStaleFiles(MediaItem $item, array $variants): void
    {
        $keep    = array_column($variants, 'file');
        $uploads = hi()->uploader()->uploadsDir();

        foreach ($this->store->variantsFor($item->id) as $old) {
            $file = (string) ($old['file'] ?? '');

            if ($file === '' || in_array($file, $keep, true)) {
                continue;
            }

            if (is_file($uploads . '/' . $file)) {
                @unlink($uploads . '/' . $file);
            }
        }
    }
}
