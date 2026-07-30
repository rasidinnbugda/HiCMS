<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Events\Media\Uploaded;
use HiCMS\Model\MediaItem;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Str;

/**
 * HiMedia — görsel boru hattı
 *
 * Çekirdek yalnızca dosyayı kaydeder ve `sizes` doluysa srcset basar. Bu eklenti
 * tam olarak o boşluğu doldurur: yükleme anında türev boyutlar ve WebP kopyaları
 * üretir, sonucu `sizes` alanına yazar.
 *
 * Eklenti kapatıldığında hiçbir şey bozulmaz — tema aynı kodla tek dosyayı
 * kullanmaya döner. Var olan türevler diskte kalır, yeni yüklemeler üretilmez.
 *
 * Otomatik çalışması ayardan kapatılabilir; kapalıyken medya sayfasından tek tek
 * "türevleri üret" denebilir.
 */
final class Plugin extends BasePlugin
{
    /** Üretilecek genişlikler. Kaynak görselden büyük olanlar atlanır. */
    private const WIDTHS = [
        'small'  => 480,
        'medium' => 800,
        'large'  => 1280,
        'xlarge' => 1920,
    ];

    public function boot(): void
    {
        // Yükleme anında türev üret.
        hi_listen(Uploaded::class, function (Uploaded $event): void {
            if (!$this->option('auto', true)) {
                return;
            }

            $this->process($event->item, $event->absolutePath);
        });

        // Ayar ekranı: HiCMS'in ayar sayfasına kendi bölümünü ekler.
        hi_on('admin.notices', function (): void {
            if (!$this->supported()) {
                echo ui_notice(
                    'warning',
                    'HiMedia için PHP GD eklentisi gerekiyor; sunucuda etkin değil. '
                    . 'Türev üretimi devre dışı, yüklemeler etkilenmez.'
                );
            }
        });

        // Medya sayfasına "türevleri üret" düğmesi eklemek için kanca noktası.
        hi_on('media.regenerate', function (int $mediaId): void {
            $item = hi()->mediaRepo()->find($mediaId);

            if ($item !== null) {
                $this->process($item, hi()->uploader()->absolutePath($item));
            }
        });
    }

    public function activate(): void
    {
        $this->setOption('auto', true);
        $this->setOption('webp', true);
        $this->setOption('quality', 82);
    }

    /**
     * Görselden türev boyutlar üretir.
     */
    public function process(MediaItem $item, string $path): void
    {
        if (!$this->supported() || !$item->isImage() || !is_file($path)) {
            return;
        }

        // SVG ölçeklenmez, gerek de yok.
        if ($item->mime === 'image/svg+xml') {
            return;
        }

        $source = $this->load($path, $item->mime);

        if ($source === null) {
            return;
        }

        $originalWidth  = imagesx($source);
        $originalHeight = imagesy($source);
        $quality        = max(40, min(95, (int) $this->option('quality', 82)));
        $wantWebp       = (bool) $this->option('webp', true) && function_exists('imagewebp');

        $directory = dirname($path);
        $base      = pathinfo($item->filename, PATHINFO_FILENAME);
        $sizes     = $item->sizes;

        foreach (self::WIDTHS as $name => $width) {
            if ($width >= $originalWidth) {
                continue;
            }

            $height = (int) round($originalHeight * ($width / $originalWidth));
            $resized = imagescale($source, $width, $height, IMG_BICUBIC);

            if ($resized === false) {
                continue;
            }

            $extension = $wantWebp ? 'webp' : $this->extensionFor($item->mime);
            $filename  = sprintf('%s-%dw.%s', $base, $width, $extension);
            $target    = $directory . '/' . $filename;

            $written = $wantWebp
                ? imagewebp($resized, $target, $quality)
                : $this->write($resized, $target, $item->mime, $quality);

            imagedestroy($resized);

            if (!$written) {
                continue;
            }

            $sizes[$name] = [
                'file'   => dirname($item->path) . '/' . $filename,
                'width'  => $width,
                'height' => $height,
            ];
        }

        // Özgün görselin WebP kopyası: en büyük boy için de kazanç sağlar.
        if ($wantWebp && $originalWidth > 0) {
            $filename = sprintf('%s-%dw.webp', $base, $originalWidth);
            $target   = $directory . '/' . $filename;

            if (imagewebp($source, $target, $quality)) {
                $sizes['full'] = [
                    'file'   => dirname($item->path) . '/' . $filename,
                    'width'  => $originalWidth,
                    'height' => $originalHeight,
                ];
            }
        }

        imagedestroy($source);

        if ($sizes !== $item->sizes) {
            $item->sizes = $sizes;
            hi()->mediaRepo()->updateSizes($item->id, $sizes);

            hi()->audit()->record(
                action: 'media.derivatives',
                userId: hi()->auth()->id(),
                actor: (string) hi()->auth()->user()?->displayName,
                subjectType: 'media',
                subjectId: $item->id,
                summary: Str::format('%d türev üretildi: %s', count($sizes), $item->filename),
            );
        }
    }

    public function supported(): bool
    {
        return extension_loaded('gd') && function_exists('imagescale');
    }

    /**
     * @return \GdImage|null
     */
    private function load(string $path, string $mime): ?object
    {
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/gif'  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };

        if ($image === false) {
            return null;
        }

        // Şeffaflığı koru.
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // EXIF yönü varsa düzelt — telefonla çekilmiş fotoğraflar yan durmasın.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 0);

            $angle = match ($orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);

                if ($rotated !== false) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    private function write(object $image, string $target, string $mime, int $quality): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, $quality),
            'image/png'  => imagepng($image, $target),
            'image/gif'  => imagegif($image, $target),
            'image/webp' => imagewebp($image, $target, $quality),
            default      => false,
        };
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
