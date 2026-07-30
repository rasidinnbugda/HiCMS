<?php

declare(strict_types=1);

namespace HiCMS\Support;

/**
 * Metin yardımcıları ve bağlama duyarlı kaçış (escape) fonksiyonları.
 *
 * Çıktı kaçışı bağlama göre yapılır: HTML gövdesi, öznitelik, URL ve JS için
 * ayrı yöntemler vardır. Şablonlarda doğrudan bu sınıf yerine `esc_html()` gibi
 * kısa küresel fonksiyonlar kullanılır (bkz. src/functions.php).
 */
final class Str
{
    private const TR_MAP = [
        'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'İ' => 'i',
        'ö' => 'o', 'Ö' => 'o', 'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u',
    ];

    /** HTML gövdesi için kaçış. */
    public static function html(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** HTML özniteliği için kaçış. */
    public static function attr(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * URL kaçışı. javascript:, data:, vbscript: gibi şemalar reddedilir.
     */
    public static function url(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^\s*(javascript|vbscript|data|file)\s*:#i', $value)) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** JS bağlamı için güvenli JSON. */
    public static function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * Zengin metni güvenli bir etiket kümesine indirger.
     * Blok içeriklerinde kullanıcı HTML'i buradan geçer.
     */
    public static function safeHtml(?string $value): string
    {
        $allowed = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote>'
            . '<h2><h3><h4><h5><h6><code><pre><figure><figcaption><img><hr>'
            . '<table><thead><tbody><tr><th><td><small><sup><sub><span><div>';

        $value = strip_tags((string) $value, $allowed);

        // Olay öznitelikleri ve tehlikeli şemaları temizle.
        $value = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value) ?? '';
        $value = preg_replace('/(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data)\s*:/i', '$1=$2#', $value) ?? '';

        return $value;
    }

    /** URL'de kullanılabilir kısa ad üretir. Türkçe karakter duyarlı. */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtr($value, self::TR_MAP);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', $separator, $value) ?? '';

        return trim($value, $separator);
    }

    /** Kelime sınırına saygılı kısaltma. */
    public static function limit(string $value, int $length = 160, string $end = '…'): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        $cut   = mb_substr($value, 0, $length, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');

        if ($space !== false) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }

        return rtrim($cut, " ,.;:!?-") . $end;
    }

    /** HTML'i temizleyip kısaltır. */
    public static function excerpt(string $html, int $length = 160, string $end = '…'): string
    {
        return self::limit(strip_tags($html), $length, $end);
    }

    public static function words(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return $text === '' ? 0 : count(explode(' ', $text));
    }

    /** Dakika cinsinden okuma süresi (200 kelime/dakika). */
    public static function readingTime(string $text): int
    {
        return max(1, (int) ceil(self::words($text) / 200));
    }

    /** Avatar yer tutucusu için baş harfler. */
    public static function initials(string $name, int $count = 2): string
    {
        $parts    = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, $count) as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
            }
        }

        return $initials !== '' ? $initials : 'H';
    }

    /** Kriptografik olarak güvenli rastgele dizge. */
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Türkçe binlik ayırıcıyla sayı biçimlendirir. */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /** Bayt değerini okunur biçime çevirir. */
    public static function bytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }

    /**
     * Yer tutuculu biçimlendirme. Çeviri metnindeki tek `%` işaretleri
     * otomatik kaçırılır; böylece "%100 hazır" gibi metinler bozulmaz.
     */
    public static function format(string $template, mixed ...$args): string
    {
        if ($args === []) {
            return $template;
        }

        $safe = preg_replace_callback(
            '/%(\d+\$)?[bcdeEfFgGosuxX]|%%|%/',
            static fn(array $m): string => $m[0] === '%' ? '%%' : $m[0],
            $template
        );

        return vsprintf((string) $safe, $args);
    }
}
