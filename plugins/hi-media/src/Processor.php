<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Model\MediaItem;
use HiCMS\Support\Str;

/**
 * Görsel işleme — GD sarmalayıcısı.
 *
 * Tek sorumluluk: bir görselden istenen genişliklerde ve formatlarda kopya
 * üretmek. Veritabanına dokunmaz, ayar okumaz; ne yapacağını kurucudan alır.
 * Böylece GD yoksa YALNIZCA bu sınıf devre dışı kalır; eklentinin geri kalanı
 * (klasör, etiket, alt metin denetimi, kullanım taraması) çalışmaya devam eder.
 *
 * Ölümcül hata üretmez: eksik fonksiyon, bozuk dosya, yetersiz bellek ve
 * animasyonlu GIF durumlarında gerekçeli bir sonuç döner.
 */
final class Processor
{
    /** Kaynak olarak okunabilen türler. SVG vektördür, ölçeklenmez. */
    public const READABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /** @param list<int> $widths */
    public function __construct(
        private readonly string $uploadsDir,
        private readonly array $widths = [480, 800, 1280, 1920],
        private readonly bool $webp = true,
        private readonly bool $avif = false,
        private readonly int $quality = 82,
        private readonly int $avifQuality = 52,
    ) {
    }

    /* ---------------------------------------------------------------------
     * Sunucu yetenekleri
     * ------------------------------------------------------------------ */

    public static function hasGd(): bool
    {
        return extension_loaded('gd') && function_exists('imagescale') && function_exists('imagecopyresampled');
    }

    public static function hasWebp(): bool
    {
        return self::hasGd() && function_exists('imagewebp') && function_exists('imagecreatefromwebp');
    }

    public static function hasAvif(): bool
    {
        return self::hasGd() && function_exists('imageavif');
    }

    /**
     * Sunucu yetenek tablosu — panelde olduğu gibi gösterilir.
     *
     * @return array<string, bool>
     */
    public static function capabilities(): array
    {
        return [
            'GD eklentisi'    => self::hasGd(),
            'WebP yazma'      => self::hasWebp(),
            'AVIF yazma'      => self::hasAvif(),
            'EXIF okuma'      => function_exists('exif_read_data'),
        ];
    }

    /** Bu görsel türünden kopya üretilebilir mi? */
    public static function processable(MediaItem $item): bool
    {
        return $item->isImage() && in_array($item->mime, self::READABLE, true);
    }

    /* ---------------------------------------------------------------------
     * Üretim
     * ------------------------------------------------------------------ */

    /**
     * Bir medya kaydından türev kopyalar üretir.
     *
     * Diske yazar ama veritabanına yazmaz — kaydı çağıran yapar. Böylece aynı
     * yöntem hem yükleme anında hem toplu işlemde kullanılabiliyor.
     *
     * @return array{
     *     ok: bool,
     *     reason: string,
     *     sizes: array<string, array{file: string, width: int, height: int}>,
     *     variants: list<array{size_key: string, format: string, width: int, height: int, file: string, bytes: int}>,
     *     bytes: int
     * }
     */
    public function derive(MediaItem $item): array
    {
        if (!self::hasGd()) {
            return $this->fail('GD eklentisi yok');
        }

        if (!self::processable($item)) {
            return $this->fail('Ölçeklenebilir bir görsel değil: ' . $item->mime);
        }

        $absolute = $this->uploadsDir . '/' . $item->path;

        if (!is_file($absolute)) {
            return $this->fail('Dosya diskte yok');
        }

        $info = @getimagesize($absolute);

        if ($info === false || (int) $info[0] < 1) {
            return $this->fail('Görsel okunamadı');
        }

        [$sourceWidth, $sourceHeight] = [(int) $info[0], (int) $info[1]];

        if ($item->mime === 'image/gif' && $this->isAnimated($absolute)) {
            return $this->fail('Animasyonlu GIF — ölçeklemek animasyonu bozar');
        }

        if (!$this->fitsInMemory($sourceWidth, $sourceHeight)) {
            return $this->fail(Str::format(
                'Bellek yetmiyor: %d×%d piksel (memory_limit=%s)',
                $sourceWidth,
                $sourceHeight,
                (string) ini_get('memory_limit')
            ));
        }

        $source = $this->load($absolute, $item->mime);

        if ($source === null) {
            return $this->fail('Görsel çözülemedi');
        }

        // EXIF döndürmesi kaynak ölçüyü değiştirmiş olabilir.
        $sourceWidth  = imagesx($source);
        $sourceHeight = imagesy($source);

        $directory = dirname($absolute);
        $relative  = trim(dirname($item->path), '/.');
        $base      = pathinfo($item->filename, PATHINFO_FILENAME);
        $formats   = $this->formats($item->mime);

        $sizes    = [];
        $variants = [];
        $bytes    = 0;

        foreach ($this->targetWidths($sourceWidth) as $width) {
            $height  = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
            $scaled  = $this->scale($source, $width, $height);

            if ($scaled === null) {
                continue;
            }

            $key = 'w' . $width;

            foreach ($formats as $index => $format) {
                $written = $this->put($scaled, $directory, $relative, $base, $width, $format, $item->filename);

                if ($written === null) {
                    continue;
                }

                $variants[] = [
                    'size_key' => $key,
                    'format'   => $format,
                    'width'    => $width,
                    'height'   => $height,
                    'file'     => $written['file'],
                    'bytes'    => $written['bytes'],
                ];

                $bytes += $written['bytes'];

                // Birincil format srcset'e girer; ek formatlar <picture> için durur.
                if ($index === 0) {
                    $sizes[$key] = ['file' => $written['file'], 'width' => $width, 'height' => $height];
                }
            }

            imagedestroy($scaled);
        }

        /*
         * TAM ÖLÇÜ KOPYASI yalnızca format DEĞİŞİYORSA üretilir. Kaynakla aynı
         * formatta ikinci bir tam ölçü dosyası saf israftır: çekirdek srcset'e
         * özgün dosyayı kendi genişliğiyle zaten ekliyor.
         */
        foreach ($formats as $index => $format) {
            if ($format === $this->extensionOf($item->mime)) {
                continue;
            }

            $written = $this->put($source, $directory, $relative, $base, $sourceWidth, $format, $item->filename);

            if ($written === null) {
                continue;
            }

            $variants[] = [
                'size_key' => 'full',
                'format'   => $format,
                'width'    => $sourceWidth,
                'height'   => $sourceHeight,
                'file'     => $written['file'],
                'bytes'    => $written['bytes'],
            ];

            $bytes += $written['bytes'];

            if ($index === 0) {
                $sizes['full'] = [
                    'file'   => $written['file'],
                    'width'  => $sourceWidth,
                    'height' => $sourceHeight,
                ];
            }
        }

        imagedestroy($source);

        return [
            'ok'       => $variants !== [],
            'reason'   => $variants === []
                ? Str::format('Kaynak %dpx; üretilecek daha küçük ölçü ya da format yok', $sourceWidth)
                : '',
            'sizes'    => $sizes,
            'variants' => $variants,
            'bytes'    => $bytes,
        ];
    }

    /**
     * Üretilecek genişlikler: kaynaktan büyük olanlar atlanır.
     *
     * @return list<int>
     */
    public function targetWidths(int $sourceWidth): array
    {
        $widths = [];

        foreach ($this->widths as $width) {
            $width = (int) $width;

            if ($width >= 32 && $width < $sourceWidth) {
                $widths[$width] = $width;
            }
        }

        ksort($widths);

        return array_values($widths);
    }

    /**
     * Üretilecek formatlar. İlk eleman BİRİNCİL formattır (srcset'e o girer).
     *
     * @return list<string>
     */
    public function formats(string $mime): array
    {
        $source  = $this->extensionOf($mime);
        $formats = [];

        if ($this->webp && self::hasWebp()) {
            $formats[] = 'webp';
        }

        if ($formats === []) {
            // WebP kapalı ya da desteklenmiyor: birincil format kaynağın kendisi.
            $formats[] = $source;
        }

        if ($this->avif && self::hasAvif() && $source !== 'avif') {
            $formats[] = 'avif';
        }

        return array_values(array_unique($formats));
    }

    /* ---------------------------------------------------------------------
     * İç yardımcılar
     * ------------------------------------------------------------------ */

    /**
     * Gerekçeli başarısızlık. Dizi birleştirme (`+`) var olan anahtarı
     * EZMEDİĞİ için gerekçe tek yerde kurulur.
     *
     * @return array{ok: bool, reason: string, sizes: array<string, array{file: string, width: int, height: int}>, variants: list<array<string, mixed>>, bytes: int}
     */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'sizes' => [], 'variants' => [], 'bytes' => 0];
    }

    /**
     * Ölçekli kopyayı diske yazar.
     *
     * @return array{file: string, bytes: int}|null
     */
    private function put(
        object $image,
        string $directory,
        string $relative,
        string $base,
        int $width,
        string $format,
        string $sourceFilename,
    ): ?array {
        $filename = Str::format('%s-%dw.%s', $base, $width, $format);
        $absolute = $directory . '/' . $filename;

        // Özgün dosyanın üzerine asla yazılmaz.
        if ($filename === $sourceFilename) {
            return null;
        }

        if (!$this->encode($image, $absolute, $format)) {
            @unlink($absolute);

            return null;
        }

        @chmod($absolute, 0o644);

        return [
            'file'  => ($relative !== '' ? $relative . '/' : '') . $filename,
            'bytes' => (int) @filesize($absolute),
        ];
    }

    private function encode(object $image, string $target, string $format): bool
    {
        $quality = max(40, min(95, $this->quality));

        return match ($format) {
            'webp' => function_exists('imagewebp') && @imagewebp($image, $target, $quality),
            'avif' => function_exists('imageavif')
                && @imageavif($image, $target, max(30, min(80, $this->avifQuality))),
            'png'  => function_exists('imagepng') && @imagepng($image, $target),
            'gif'  => function_exists('imagegif') && @imagegif($image, $target),
            'jpg'  => function_exists('imagejpeg') && @imagejpeg($image, $target, $quality),
            default => false,
        };
    }

    /** @return \GdImage|null */
    private function scale(object $source, int $width, int $height): ?object
    {
        $scaled = @imagescale($source, $width, $height, IMG_BICUBIC);

        if ($scaled === false) {
            return null;
        }

        // Şeffaflık ölçeklemede kaybolabiliyor; yeniden işaretlenir.
        @imagealphablending($scaled, false);
        @imagesavealpha($scaled, true);

        return $scaled;
    }

    /** @return \GdImage|null */
    private function load(string $path, string $mime): ?object
    {
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/gif'  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default      => false,
        };

        if ($image === false || !($image instanceof \GdImage)) {
            return null;
        }

        if (in_array($mime, ['image/png', 'image/webp', 'image/avif'], true)) {
            @imagealphablending($image, false);
            @imagesavealpha($image, true);
        }

        return $this->applyOrientation($image, $path, $mime);
    }

    /**
     * EXIF yönünü uygular — telefonla çekilmiş fotoğraf yan durmasın.
     *
     * @return \GdImage
     */
    private function applyOrientation(object $image, string $path, string $mime): object
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 0) : 0)) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function extensionOf(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default      => 'jpg',
        };
    }

    /**
     * Animasyonlu GIF mi? Birden çok grafik denetim bloğu varsa evet.
     */
    private function isAnimated(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $frames = 0;
        $buffer = '';

        while (!feof($handle) && $frames < 2) {
            $buffer .= (string) fread($handle, 65536);
            $frames  = substr_count($buffer, "\x00\x21\xF9\x04");

            // Kuyruk taşıması: desen tam sınıra düşerse kaçmasın.
            $buffer = substr($buffer, -8);
        }

        fclose($handle);

        return $frames > 1;
    }

    /**
     * Kaynak + ölçekli kopya belleğe sığar mı?
     *
     * GD, ölçeklemeden önce görselin tamamını piksel başına 4 bayt ile açar;
     * sınırı aşan bir dosya PHP'yi ölümcül hatayla düşürür ve kullanıcı
     * "yükleme başarısız" bile göremez. O yüzden ÖNCEDEN sorulur.
     */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = self::memoryLimit();

        if ($limit <= 0) {
            return true; // sınırsız
        }

        $needed = ($width * $height * 4 * 2) + 2097152;

        return ($needed + memory_get_usage(true)) < $limit;
    }

    private static function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g'     => $value * 1073741824,
            'm'     => $value * 1048576,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
