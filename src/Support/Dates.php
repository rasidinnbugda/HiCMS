<?php

declare(strict_types=1);

namespace HiCMS\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Tarih biçimlendirme. Ay ve gün adları çeviri dosyasına değil, buradaki
 * tablolara bağlıdır; başka bir dil eklendiğinde `names` filtresiyle
 * değiştirilebilir.
 */
final class Dates
{
    private const MONTHS = [
        1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık',
    ];

    private const DAYS = [
        'Sunday' => 'Pazar', 'Monday' => 'Pazartesi', 'Tuesday' => 'Salı',
        'Wednesday' => 'Çarşamba', 'Thursday' => 'Perşembe',
        'Friday' => 'Cuma', 'Saturday' => 'Cumartesi',
    ];

    public static function now(?string $timezone = null): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($timezone ?? date_default_timezone_get()));
    }

    /** Veritabanına yazılacak biçim. */
    public static function stamp(?string $when = null): string
    {
        $time = $when !== null ? strtotime($when) : time();

        return date('Y-m-d H:i:s', $time !== false ? $time : time());
    }

    /**
     * Türkçe ay/gün adlarıyla biçimlendirir.
     * Desteklenen ek belirteçler: F (ay), M (kısa ay), l (gün).
     */
    public static function format(?string $datetime, string $format = 'j F Y'): string
    {
        if ($datetime === null || $datetime === '' || str_starts_with($datetime, '0000')) {
            return '';
        }

        $time = strtotime($datetime);

        if ($time === false) {
            return '';
        }

        $out    = '';
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            switch ($char) {
                case 'F':
                    $out .= self::MONTHS[(int) date('n', $time)];
                    break;
                case 'M':
                    $out .= mb_substr(self::MONTHS[(int) date('n', $time)], 0, 3, 'UTF-8');
                    break;
                case 'l':
                    $out .= self::DAYS[date('l', $time)];
                    break;
                case '\\':
                    $i++;
                    $out .= $format[$i] ?? '';
                    break;
                default:
                    $out .= date($char, $time);
            }
        }

        return $out;
    }

    /** ISO 8601 (datetime özniteliği için). */
    public static function iso(?string $datetime): string
    {
        $time = $datetime !== null ? strtotime($datetime) : false;

        return $time !== false ? date('c', $time) : '';
    }

    /** "3 saat önce" biçiminde göreli zaman. */
    public static function ago(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $time = strtotime($datetime);

        if ($time === false) {
            return '';
        }

        $diff = time() - $time;

        if ($diff < 0) {
            return self::format($datetime);
        }

        $units = [
            31536000 => 'yıl',
            2592000  => 'ay',
            604800   => 'hafta',
            86400    => 'gün',
            3600     => 'saat',
            60       => 'dakika',
        ];

        foreach ($units as $seconds => $label) {
            if ($diff >= $seconds) {
                return (int) floor($diff / $seconds) . ' ' . $label . ' önce';
            }
        }

        return 'az önce';
    }

    /** `datetime-local` girdisi için biçim. */
    public static function forInput(?string $datetime): string
    {
        $time = $datetime !== null && $datetime !== '' ? strtotime($datetime) : time();

        return date('Y-m-d\TH:i', $time !== false ? $time : time());
    }
}
