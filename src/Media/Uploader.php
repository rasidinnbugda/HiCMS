<?php

declare(strict_types=1);

namespace HiCMS\Media;

use HiCMS\Events\Dispatcher;
use HiCMS\Events\Media\Uploaded;
use HiCMS\Model\MediaItem;
use HiCMS\Repository\MediaRepository;
use HiCMS\Support\Fs;
use HiCMS\Support\Str;

/**
 * Dosya yükleme.
 *
 * Çekirdek yalnızca güvenli yüklemeyi ve kaydı bilir. Türev boyut üretme,
 * WebP/AVIF dönüştürme gibi işler `Media\Uploaded` olayına bağlanan HiMedia
 * eklentisinin sorumluluğundadır — eklenti yoksa dosya olduğu gibi kullanılır.
 *
 * Güvenlik kararları:
 *   • Uzantı beyaz listesi + gerçek MIME denetimi (fileinfo)
 *   • Dosya adı yeniden üretilir; kullanıcı adı yola karışmaz
 *   • Yıl/ay klasörlerine yazılır, klasörde PHP çalıştırma kapatılır
 *   • SVG yalnızca izin verilirse ve temizlenerek kabul edilir
 */
final class Uploader
{
    /** uzantı → izin verilen MIME türleri */
    private const ALLOWED = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'mp3'  => ['audio/mpeg'],
        'woff2' => ['font/woff2', 'application/octet-stream'],
    ];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly MediaRepository $media,
        private readonly Dispatcher $events,
        private readonly int $maxBytes = 16777216,
        private readonly bool $allowSvg = false,
    ) {
    }

    /**
     * Yüklenen dosyayı kaydeder.
     *
     * @param array<string, mixed> $file $_FILES girdisi
     * @return array{ok: bool, error: string, item: MediaItem|null}
     */
    public function store(array $file, int $authorId, string $alt = '', string $title = ''): array
    {
        $error = $this->validateUpload($file);

        if ($error !== '') {
            return ['ok' => false, 'error' => $error, 'item' => null];
        }

        $original  = (string) ($file['name'] ?? '');
        $temp      = (string) ($file['tmp_name'] ?? '');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED[$extension])) {
            return ['ok' => false, 'error' => 'Bu dosya türüne izin verilmiyor: .' . $extension, 'item' => null];
        }

        if ($extension === 'svg' && !$this->allowSvg) {
            return [
                'ok'    => false,
                'error' => 'SVG yüklemesi kapalı. Ayarlar → Medya bölümünden açabilirsiniz.',
                'item'  => null,
            ];
        }

        $mime = $this->detectMime($temp);

        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            return [
                'ok'    => false,
                'error' => Str::format('Dosya içeriği uzantısıyla uyuşmuyor (%s).', $mime),
                'item'  => null,
            ];
        }

        // Görsel iddiasında olan dosyalar gerçekten görsel olmalı.
        $width  = 0;
        $height = 0;

        if (str_starts_with($mime, 'image/') && $extension !== 'svg') {
            $info = @getimagesize($temp);

            if ($info === false) {
                return ['ok' => false, 'error' => 'Dosya geçerli bir görsel değil.', 'item' => null];
            }

            $width  = (int) $info[0];
            $height = (int) $info[1];
        }

        $folder    = date('Y/m');
        $targetDir = $this->uploadsDir . '/' . $folder;

        if (!Fs::ensureDir($targetDir)) {
            return ['ok' => false, 'error' => 'Yükleme klasörü oluşturulamadı. İzinleri kontrol edin.', 'item' => null];
        }

        // Klasörde PHP çalıştırmayı kapat — yükleme dizini asla kod çalıştırmamalı.
        $this->hardenUploadsDir();

        $basename = Str::slug(pathinfo($original, PATHINFO_FILENAME));
        $basename = $basename !== '' ? mb_substr($basename, 0, 60) : 'dosya';
        $filename = $this->uniqueName($targetDir, $basename, $extension);
        $absolute = $targetDir . '/' . $filename;

        $moved = is_uploaded_file($temp)
            ? @move_uploaded_file($temp, $absolute)
            : @rename($temp, $absolute);

        if (!$moved) {
            return ['ok' => false, 'error' => 'Dosya taşınamadı.', 'item' => null];
        }

        @chmod($absolute, 0o644);

        if ($extension === 'svg') {
            $this->sanitizeSvg($absolute);
        }

        $item            = new MediaItem();
        $item->filename  = $filename;
        $item->path      = $folder . '/' . $filename;
        $item->mime      = $mime;
        $item->size      = (int) @filesize($absolute);
        $item->width     = $width;
        $item->height    = $height;
        $item->alt       = mb_substr(trim($alt), 0, 250);
        $item->title     = mb_substr(trim($title !== '' ? $title : $basename), 0, 250);
        $item->authorId  = $authorId;

        $this->media->create($item);

        // HiMedia eklentisi burada türev boyutları üretir ve $item->sizes doldurur.
        $event = $this->events->dispatch(new Uploaded($item, $absolute));

        if ($event->item->sizes !== []) {
            $this->media->updateSizes($event->item->id, $event->item->sizes);
        }

        return ['ok' => true, 'error' => '', 'item' => $event->item];
    }

    /**
     * Dosyayı ve türevlerini diskten siler.
     */
    public function deleteFiles(MediaItem $item): void
    {
        $absolute = $this->uploadsDir . '/' . $item->path;

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        foreach ($item->sizes as $size) {
            $file = $this->uploadsDir . '/' . (string) ($size['file'] ?? '');

            if (($size['file'] ?? '') !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function absolutePath(MediaItem $item): string
    {
        return $this->uploadsDir . '/' . $item->path;
    }

    public function uploadsDir(): string
    {
        return $this->uploadsDir;
    }

    /**
     * PHP'nin bildirdiği yükleme hatalarını okunur mesaja çevirir.
     *
     * @param array<string, mixed> $file
     */
    private function validateUpload(array $file): string
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($code !== UPLOAD_ERR_OK) {
            return match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu sınırından büyük.',
                UPLOAD_ERR_PARTIAL                        => 'Dosya tam yüklenemedi, tekrar deneyin.',
                UPLOAD_ERR_NO_FILE                        => 'Dosya seçilmedi.',
                UPLOAD_ERR_NO_TMP_DIR                     => 'Sunucuda geçici klasör yok.',
                UPLOAD_ERR_CANT_WRITE                     => 'Sunucu diske yazamadı.',
                default                                   => 'Yükleme başarısız (kod ' . $code . ').',
            };
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            return 'Dosya boş.';
        }

        if ($size > $this->maxBytes) {
            return Str::format('Dosya çok büyük. En fazla %s yükleyebilirsiniz.', Str::bytes($this->maxBytes));
        }

        return '';
    }

    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return (string) (@mime_content_type($path) ?: 'application/octet-stream');
    }

    private function uniqueName(string $directory, string $basename, string $extension): string
    {
        $name    = $basename . '.' . $extension;
        $counter = 1;

        while (is_file($directory . '/' . $name)) {
            $name = $basename . '-' . (++$counter) . '.' . $extension;
        }

        return $name;
    }

    /**
     * Yükleme dizininde kod çalıştırmayı engeller.
     */
    private function hardenUploadsDir(): void
    {
        $htaccess = $this->uploadsDir . '/.htaccess';

        if (is_file($htaccess)) {
            return;
        }

        $rules = "# HiCMS: yükleme dizininde kod çalıştırılamaz\n"
            . "<FilesMatch \"\\.(php|phtml|phar|php[0-9]|cgi|pl|py|asp|aspx|jsp|htaccess)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
            . "Options -Indexes -ExecCGI\n"
            . "AddType text/plain .php .phtml .phar\n";

        @file_put_contents($htaccess, $rules);
    }

    /**
     * SVG'den betik ve olay özniteliklerini temizler.
     */
    private function sanitizeSvg(string $path): void
    {
        $svg = (string) @file_get_contents($path);

        if ($svg === '') {
            return;
        }

        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<(foreignObject|iframe|embed|object|use)\b[^>]*>.*?</\1>#is', '', $svg) ?? $svg;
        $svg = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
        $svg = preg_replace('/(href|xlink:href)\s*=\s*(["\']?)\s*(javascript|data)\s*:/i', '$1=$2#', $svg) ?? $svg;
        $svg = preg_replace('#<!DOCTYPE[^>]*>#i', '', $svg) ?? $svg;
        $svg = preg_replace('#<!ENTITY[^>]*>#i', '', $svg) ?? $svg;

        @file_put_contents($path, $svg);
    }
}
