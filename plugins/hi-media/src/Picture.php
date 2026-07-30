<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Events\Render\BlockRendering;
use HiCMS\Support\Str;

/**
 * Görsel bloğunda modern biçimleri `<picture>` ile sunar.
 *
 * NEDEN GEREKLİ: `srcset` biçim karıştıramaz — tarayıcı adayların hepsini aynı
 * biçim sayar, `type` bildirimi yoktur. Bu yüzden AVIF kopyalar `media.sizes`
 * içine YAZILMIYOR; oraya yazmak, AVIF desteklemeyen tarayıcıya
 * desteklemediği dosyayı göndermek olurdu. Doğru yol `<source type="…">`:
 *
 *     <picture>
 *       <source type="image/avif" srcset="…-480w.avif 480w, …">
 *       <source type="image/webp" srcset="…-480w.webp 480w, …">
 *       <img src="…" srcset="…" sizes="…" …>
 *     </picture>
 *
 * ÇEKİRDEKLE İŞ BÖLÜMÜ: `BlockRenderer::image()` çıktıyı kendisi `<picture>`
 * içine alabiliyor (`sizes` içindeki dosya uzantılarından biçim türetiyor).
 * O durumda buradan YENİDEN sarmak iki iç içe `<picture>` üretirdi; onun
 * yerine yalnızca EKSİK biçimler var olan `<picture>`ın başına eklenir.
 * Sıra önemli: tarayıcı desteklediği ilk kaynağı seçer, AVIF önce gelmeli.
 *
 * Kopya yoksa çıktıya dokunulmaz: eklenti kapatıldığında ya da üretim
 * yapılmadığında tema tek dosyayla çalışmaya devam eder.
 */
final class Picture
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * Blok çıktısını yerinde değiştirir.
     */
    public function upgrade(BlockRendering $event): void
    {
        if ($event->type() !== 'image') {
            return;
        }

        $mediaId = (int) ($event->block['data']['mediaId'] ?? 0);

        if ($mediaId <= 0 || !str_contains($event->html, '<img')) {
            return;
        }

        $wrapped = str_contains($event->html, '<picture');
        $sources = $this->sources($mediaId);

        foreach (array_keys($sources) as $format) {
            // Çekirdek ya da başka bir eklenti bu biçimi zaten bildirmişse tekrar etme.
            if (str_contains($event->html, 'type="image/' . $format . '"')) {
                unset($sources[$format]);
            }
        }

        if ($sources === []) {
            return;
        }

        $sizes = $this->sizesAttribute($event->html);
        $tags  = '';

        foreach ($sources as $format => $srcset) {
            $tags .= sprintf(
                '<source type="image/%s" srcset="%s"%s>',
                Str::attr($format),
                Str::attr($srcset),
                $sizes !== '' ? ' sizes="' . Str::attr($sizes) . '"' : ''
            );
        }

        $replaced = $wrapped
            ? preg_replace('~<picture\b[^>]*>~', '$0' . $tags, $event->html, 1)
            : preg_replace('~<img\b[^>]*>~', '<picture>' . $tags . '$0</picture>', $event->html, 1);

        if (is_string($replaced) && $replaced !== '') {
            $event->html = $replaced;
        }
    }

    /**
     * Format → srcset. Modern format önce gelir; tarayıcı ilk desteklediğini alır.
     *
     * @return array<string, string>
     */
    private function sources(int $mediaId): array
    {
        $byFormat = [];

        foreach ($this->store->variantsFor($mediaId) as $variant) {
            $format = (string) ($variant['format'] ?? '');
            $file   = (string) ($variant['file'] ?? '');
            $width  = (int) ($variant['width'] ?? 0);

            // Yalnızca tarayıcı seçimi için anlamlı olan modern formatlar.
            if ($file === '' || $width <= 0 || !in_array($format, ['avif', 'webp'], true)) {
                continue;
            }

            $byFormat[$format][$width] = hi()->urls()->uploads($file) . ' ' . $width . 'w';
        }

        $sources = [];

        foreach (['avif', 'webp'] as $format) {
            if (!isset($byFormat[$format])) {
                continue;
            }

            ksort($byFormat[$format]);

            $sources[$format] = implode(', ', $byFormat[$format]);
        }

        return $sources;
    }

    /** `<img … sizes="…">` değerini kopyalar; kaynaklar aynı ölçü ipucunu kullanmalı. */
    private function sizesAttribute(string $html): string
    {
        return preg_match('~\ssizes="([^"]*)"~', $html, $match) === 1 ? $match[1] : '';
    }
}
