<?php

declare(strict_types=1);

namespace HiCMS\Http;

use HiCMS\Support\Str;

/**
 * CSRF koruması.
 *
 * Oturum açıksa anahtar oturuma bağlanır (asıl koruma). Oturum yoksa —
 * ziyaretçinin yorum bırakması gibi durumlarda — gizli anahtar ve günün
 * tarihinden türetilen durumsuz bir anahtar kullanılır; böylece her anonim
 * ziyaretçi için oturum açmak gerekmez ve sayfa önbelleklenebilir kalır.
 */
final class Csrf
{
    private const SESSION_KEY = 'hicms.csrf';

    public function __construct(private readonly string $secret)
    {
    }

    public function token(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION[self::SESSION_KEY])) {
                $_SESSION[self::SESSION_KEY] = Str::random(32);
            }

            return (string) $_SESSION[self::SESSION_KEY];
        }

        return $this->statelessToken(gmdate('Y-m-d'));
    }

    public function verify(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[self::SESSION_KEY])) {
            return hash_equals((string) $_SESSION[self::SESSION_KEY], $token);
        }

        // Gün değişiminde açık duran formlar geçersiz olmasın.
        return hash_equals($this->statelessToken(gmdate('Y-m-d')), $token)
            || hash_equals($this->statelessToken(gmdate('Y-m-d', time() - 86400)), $token);
    }

    /** Formlara basılacak gizli alan. */
    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Str::attr($this->token()) . '">';
    }

    /** Oturum yenilendiğinde anahtarı da yenile (oturum sabitleme koruması). */
    public function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = Str::random(32);
        }
    }

    private function statelessToken(string $day): string
    {
        return hash_hmac('sha256', 'hicms-form|' . $day, $this->secret);
    }
}
