<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Http\Request;
use HiCMS\Support\Dates;

/**
 * İstenmeyen koruması — harici servis yok, çerez yok, CAPTCHA yok.
 *
 * Dört katman, hepsi bağımsız kapatılabilir:
 *
 *   1. BAL KÜPÜ  — ekran dışında bir metin alanı. İnsan görmez, otomatik
 *      doldurucu doldurur.
 *   2. SÜRE TUZAĞI — formun basıldığı an gizli bir alanda taşınır. Gönderim
 *      bundan `min_seconds` kadar bile sonra gelmediyse form okunmamış demektir.
 *      Zaman damgası İMZALI: imzasız olsaydı bot damgayı geçmişe çekip tuzağı
 *      bedavaya atlatırdı (tuzak "çok hızlı"yı yakalar, botun istediği şey de
 *      damganın eski görünmesidir).
 *   3. ARALIK SINIRI — aynı ziyaretçiden pencere içinde en fazla N gönderim.
 *      Bu bir HATA'dır, istenmeyen değil: kullanıcıya söylenir.
 *   4. YASAKLI SÖZCÜK — gönderinin herhangi bir alanında geçiyorsa istenmeyen.
 *
 * İstenmeyen kararı KULLANICIYA SÖYLENMEZ: bot da insan da başarı mesajı görür.
 * Söylenirse istenmeyen filtresi kendi kendini tarif eden bir hata ayıklama
 * aracına dönüşür.
 */
final class Guard
{
    /** Bal küpü alanının adı — insan gözüne makul, bota davetkâr. */
    public const HONEYPOT = 'hf_website';

    /** İmzalı zaman damgasının taşındığı alan. */
    public const STAMP = 'hf_open';

    /** Formun basıldığı anı taşıyan imzalı değer. */
    public static function stamp(): string
    {
        $now = time();

        return $now . '.' . self::sign((string) $now);
    }

    /**
     * Gönderiyi hiç doğrulamadan reddedebilir miyiz?
     *
     * Bu iki denetim ALAN DOĞRULAMASINDAN ÖNCE yapılır: bota alan alan hata
     * mesajı vermek, filtrenin nasıl çalıştığını anlatan bir hata ayıklama
     * arayüzü sunmak olur.
     */
    public static function isBot(Request $request): bool
    {
        if (Forms::flag('honeypot') && $request->text(self::HONEYPOT) !== '') {
            return true;
        }

        $minimum = max(0, Forms::number('min_seconds', 3));

        return $minimum > 0 && !self::openedLongEnough($request->text(self::STAMP), $minimum);
    }

    /**
     * İçeriğe ve sıklığa bakan denetimler.
     *
     * @param array<string, string> $payload
     * @return array{spam: bool, error: string}
     */
    public static function inspect(Request $request, array $payload): array
    {
        foreach (Forms::lines('blocklist') as $word) {
            $word = mb_strtolower(trim($word));

            if ($word === '') {
                continue;
            }

            foreach ($payload as $value) {
                if (mb_strpos(mb_strtolower($value), $word) !== false) {
                    return ['spam' => true, 'error' => ''];
                }
            }
        }

        $limit  = max(0, Forms::number('max_in_window', 2));
        $window = max(0, Forms::number('window', 60));

        if ($limit > 0 && $window > 0 && self::recent($request, $window) >= $limit) {
            return [
                'spam'  => false,
                'error' => 'Çok kısa aralıkla gönderim yapıyorsunuz. Lütfen biraz bekleyip tekrar deneyin.',
            ];
        }

        return ['spam' => false, 'error' => ''];
    }

    /**
     * Kayda yazılacak ziyaretçi imzası.
     *
     * IP saklama kapalıyken ham adres YERİNE kısaltılmış bir HMAC yazılır.
     * Böylece kayıtta kimlik kalmaz ama aralık sınırı yine çalışır — aksi
     * hâlde gizliliği açmak istenmeyen korumasını kapatmak olurdu.
     */
    public static function fingerprint(Request $request): string
    {
        $ip = $request->ip();

        if ($ip === '') {
            return '';
        }

        return Forms::flag('store_ip') ? $ip : 'h:' . substr(self::sign('ip|' . $ip), 0, 32);
    }

    /* ---------------------------------------------------------------------
     * İç işler
     * ------------------------------------------------------------------ */

    private static function openedLongEnough(string $value, int $minimum): bool
    {
        if (!str_contains($value, '.')) {
            // Alan hiç gelmemiş: form bizim bastığımız form değil.
            return false;
        }

        [$stamp, $signature] = explode('.', $value, 2);

        if (!ctype_digit($stamp) || !hash_equals(self::sign($stamp), $signature)) {
            return false;
        }

        $age = time() - (int) $stamp;

        /*
         * Çok eski damga da kabul edilmez: bir kez alınıp aylarca yeniden
         * kullanılan damga tuzağı işlevsizleştirir. On iki saat, sekmeyi açık
         * bırakan gerçek kullanıcıya fazlasıyla yeter.
         */
        return $age >= $minimum && $age <= 43200;
    }

    private static function recent(Request $request, int $window): int
    {
        if (!Submissions::ready()) {
            return 0;
        }

        $who = self::fingerprint($request);

        if ($who === '') {
            return 0;
        }

        return hi()->db()->builder(Submissions::TABLE)
            ->where('ip', $who)
            ->where('created_at', '>', Dates::stamp('-' . $window . ' seconds'))
            ->count();
    }

    private static function sign(string $value): string
    {
        $secret = (string) hi()->config()->get('keys.app', '');

        return hash_hmac('sha256', 'hi-forms|' . $value, $secret !== '' ? $secret : Forms::SLUG);
    }
}
