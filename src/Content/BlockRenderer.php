<?php

declare(strict_types=1);

namespace HiCMS\Content;

use HiCMS\Events\Dispatcher;
use HiCMS\Events\Render\BlockRendering;
use HiCMS\Http\Url;
use HiCMS\Model\Entry;
use HiCMS\Model\MediaItem;
use HiCMS\Repository\MediaRepository;
use HiCMS\Support\Html;
use HiCMS\Support\Str;

/**
 * Blok ağacını HTML'e çevirir.
 *
 * Blok çıktısı üretmenin tek yolu burasıdır; kaçış ve medya çözümleme
 * yardımcıları blok tanımlarına bu nesne üzerinden geçer. Her bloğun çıktısı
 * `BlockRendering` olayından geçtiği için eklentiler sonucu sarmalayabilir.
 */
final class BlockRenderer
{
    /** @var array<int, MediaItem|null> Aynı istekte tekrar sorgu atmamak için */
    private array $mediaCache = [];

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly MediaRepository $media,
        private readonly Url $url,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Bir içeriğin tüm bloklarını basar.
     */
    public function renderEntry(Entry $entry): string
    {
        return $this->renderBlocks($entry->blocks);
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function renderBlocks(array $blocks): string
    {
        $output = [];

        foreach ($blocks as $index => $block) {
            $html = $this->renderBlock($block, $index);

            if ($html !== '') {
                $output[] = $html;
            }
        }

        return implode("\n", $output);
    }

    /**
     * @param array{type: string, data: array<string, mixed>} $block
     */
    public function renderBlock(array $block, int $index = 0): string
    {
        $definition = $this->registry->definition($block['type']);

        if ($definition === null) {
            // Bilinmeyen blok (eklenti devre dışı bırakılmış olabilir): sessizce atla,
            // ama veri kaybolmaz — kayıt olduğu gibi durur.
            return '';
        }

        /** @var callable $render */
        $render = $definition['render'];
        $html   = (string) $render($block['data'], $this);

        if ($html === '') {
            return '';
        }

        $event = $this->events->dispatch(new BlockRendering($html, $block, $index));

        return $event->html;
    }

    /* ---------------------------------------------------------------------
     * Blok tanımlarının kullandığı yardımcılar
     * ------------------------------------------------------------------ */

    /** Düz metin kaçışı. */
    public function text(?string $value): string
    {
        return Str::html($value);
    }

    public function attr(?string $value): string
    {
        return Str::attr($value);
    }

    public function url(?string $value): string
    {
        return Str::url($value);
    }

    /**
     * Zengin metin: yalnızca güvenli satır içi etiketler geçer, satır sonları
     * paragrafa çevrilir.
     */
    public function rich(string $value): string
    {
        $value = Html::clean($value);

        // Zaten blok etiketi içeriyorsa dokunma. (`div` temizleyicinin izin
        // listesinde yok; soyulduğu için burada aranmaz.)
        if (preg_match('/<(p|ul|ol|blockquote|h[2-6]|figure|table|pre)\b/i', $value) === 1) {
            return $value;
        }

        $paragraphs = preg_split('/\n{2,}/', trim($value)) ?: [];

        if (count($paragraphs) <= 1) {
            return nl2br(trim($value), false);
        }

        $html = implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(trim($p), false) . '</p>',
            $paragraphs
        ));

        /* Paragrafa bölme temizleyiciden SONRA çalışıyor ve bölme noktası açık
         * bir satır içi öğenin ortasına düşebiliyor: `<mark>bir\n\niki</mark>`
         * girdisi `<p><mark>bir</p><p>iki</mark></p>` üretiyordu — DENGESİZ.
         * Temizleyicinin "çıktı her zaman dengeli" güvencesi bu satırda
         * yeniden kuruluyor; Html::clean() değişmez (idempotent) olduğu için
         * ikinci geçiş zaten temiz olan parçalara dokunmaz.
         * (ELEŞTİRMEN BULGUSU — bkz. build/smoke.php "HTML temizleyici".) */
        return Html::clean($html);
    }

    /** Kullanıcı HTML'i — güvenli etiket kümesine indirgenir. */
    public function safe(string $value): string
    {
        return Html::clean($value);
    }

    /**
     * Satır tabanlı alanı diziye çevirir.
     *
     * @param mixed $value
     * @return list<string>
     */
    public function lines(mixed $value): array
    {
        if (is_array($value)) {
            $lines = array_map('strval', array_filter($value, 'is_scalar'));
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
        }

        return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
    }

    /** Başlık bloğu için çıpa kimliği. */
    public function anchor(string $text): string
    {
        $slug = Str::slug($text);

        return $slug !== '' ? $slug : 'baslik';
    }

    public function media(int $id): ?MediaItem
    {
        if ($id <= 0) {
            return null;
        }

        return $this->mediaCache[$id] ??= $this->media->find($id);
    }

    /**
     * Duyarlı `<img>` etiketi üretir.
     *
     * `sizes` alanı doluysa srcset basılır (HiMedia eklentisi doldurur), boşsa
     * tek dosya kullanılır. Tema kodu iki durumda da aynıdır.
     */
    public function image(MediaItem $item, string $sizes = '100vw', string $class = ''): string
    {
        $src = $this->url->uploads($item->path);

        $attributes = [
            'src'      => $src,
            'alt'      => $item->alt,
            'loading'  => 'lazy',
            'decoding' => 'async',
        ];

        if ($item->width > 0 && $item->height > 0) {
            $attributes['width']  = (string) $item->width;
            $attributes['height'] = (string) $item->height;
        }

        if ($class !== '') {
            $attributes['class'] = $class;
        }

        $srcset = $this->srcset($item);

        if ($srcset !== '') {
            $attributes['srcset'] = $srcset;
            $attributes['sizes']  = $sizes;
        }

        $html = '<img';

        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . Str::attr($value) . '"';
        }

        $html .= '>';

        /*
         * MODERN BİÇİMLER `<picture>` İLE SUNULUR.
         *
         * `srcset` BİÇİM KARIŞTIRAMAZ: tarayıcı listedeki adayların hepsinin
         * aynı biçimde olduğunu varsayar, `type` bildirimi yoktur. WebP'yi
         * srcset'e koymak, desteklemeyen tarayıcıya bozuk görsel göstermek olur.
         *
         * Bu yüzden 0.2.0'da HiMedia'nın ürettiği WebP dosyası diskte duruyor
         * ama hiçbir yerde sunulmuyordu — kazanç üretilmiş, teslim edilmemiş.
         *
         * `<picture>` YALNIZCA modern türev gerçekten varsa basılır; yoksa çıktı
         * bugünküyle birebir aynı kalır. Böylece tema CSS'i ve mevcut
         * işaretleme beklentileri bozulmuyor.
         */
        $modern = $this->modernSources($item);

        if ($modern === []) {
            return $html;
        }

        $picture = '<picture>';

        foreach ($modern as $mime => $set) {
            $picture .= '<source type="' . Str::attr($mime) . '" srcset="' . Str::attr($set) . '"'
                . ' sizes="' . Str::attr($sizes) . '">';
        }

        return $picture . $html . '</picture>';
    }

    /**
     * Modern biçim türevlerini MIME türüne göre gruplar.
     *
     * Biçim dosya UZANTISINDAN türetilir, `sizes` içindeki bir anahtardan değil:
     * eklentinin ayrıca bir alan doldurmasına bağlı kalmadan çalışır.
     *
     * Sıra önemli — tarayıcı desteklediği İLK kaynağı seçer, o yüzden en verimli
     * biçim başta olmalı: AVIF, sonra WebP.
     *
     * @return array<string, string> MIME → srcset
     */
    private function modernSources(MediaItem $item): array
    {
        if ($item->sizes === []) {
            return [];
        }

        $byMime = [];

        foreach ($item->sizes as $size) {
            $file  = (string) ($size['file'] ?? '');
            $width = (int) ($size['width'] ?? 0);

            if ($file === '' || $width <= 0) {
                continue;
            }

            $mime = match (strtolower((string) pathinfo($file, PATHINFO_EXTENSION))) {
                'avif' => 'image/avif',
                'webp' => 'image/webp',
                default => '',
            };

            if ($mime === '') {
                continue;
            }

            $byMime[$mime][$width] = $this->url->uploads($file) . ' ' . $width . 'w';
        }

        $ordered = [];

        foreach (['image/avif', 'image/webp'] as $mime) {
            if (!isset($byMime[$mime])) {
                continue;
            }

            ksort($byMime[$mime]);
            $ordered[$mime] = implode(', ', $byMime[$mime]);
        }

        return $ordered;
    }

    /**
     * Türev boyutlardan srcset dizesi kurar.
     */
    public function srcset(MediaItem $item): string
    {
        if ($item->sizes === []) {
            return '';
        }

        $parts = [];

        foreach ($item->sizes as $size) {
            $file  = (string) ($size['file'] ?? '');
            $width = (int) ($size['width'] ?? 0);

            if ($file === '' || $width <= 0) {
                continue;
            }

            /*
             * Modern biçim türevleri buraya GİRMEZ. Tek bir srcset içinde iki
             * farklı biçim bulunamaz: tarayıcı adayların hepsini aynı biçim
             * sayar ve `type` bildirimi yoktur, dolayısıyla WebP'yi burada
             * sunmak desteklemeyen tarayıcıya bozuk görsel göstermek olur.
             * Onlar <picture><source> ile sunuluyor (bkz. modernSources()).
             */
            if (in_array(strtolower((string) pathinfo($file, PATHINFO_EXTENSION)), ['webp', 'avif'], true)) {
                continue;
            }

            $parts[$width] = $this->url->uploads($file) . ' ' . $width . 'w';
        }

        if ($item->width > 0) {
            $parts[$item->width] = $this->url->uploads($item->path) . ' ' . $item->width . 'w';
        }

        ksort($parts);

        return implode(', ', $parts);
    }

    /**
     * YouTube/Vimeo bağlantısını gömme adresine çevirir.
     * Desteklenmeyen adreslerde null döner — rastgele iframe basılmaz.
     */
    public function embedUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }

    public function urls(): Url
    {
        return $this->url;
    }

    public function registry(): BlockRegistry
    {
        return $this->registry;
    }
}
