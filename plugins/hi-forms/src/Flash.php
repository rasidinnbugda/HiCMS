<?php

declare(strict_types=1);

namespace HiForms;

/**
 * Başarısız gönderimin hatırlanması.
 *
 * Gönderim ayrı bir rotaya (POST /form-gonder) gidiyor, hata durumunda
 * kullanıcı formun bulunduğu sayfaya geri yönlendiriliyor. Hata mesajlarını ve
 * GİRİLEN DEĞERLERİ taşımanın tek makul yolu oturum: sorgu dizesine sığmazlar
 * (uzun metin alanları), gizli alana da yazılamazlar (istek yeniden başlıyor).
 *
 * Oturum ön yüzde `index.php` tarafından zaten başlatılıyor; bu sınıf oturum
 * açmaya ÇALIŞMAZ, yoksa sessizce devre dışı kalır.
 */
final class Flash
{
    private const KEY = 'hi-forms.flash';

    /**
     * @param array<string, string> $errors alan anahtarı → mesaj
     * @param array<string, string> $old girilen değerler
     */
    public static function put(string $slug, string $error, array $errors, array $old): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[self::KEY] = [
            'form'   => $slug,
            'error'  => $error,
            'errors' => $errors,
            'old'    => $old,
        ];
    }

    /**
     * Bekleyen hatayı okur ve siler. Yalnızca ilgili form için döner.
     *
     * @return array{error: string, errors: array<string, string>, old: array<string, string>}
     */
    public static function take(string $slug): array
    {
        $blank = ['error' => '', 'errors' => [], 'old' => []];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $blank;
        }

        $flash = $_SESSION[self::KEY] ?? null;

        if (!is_array($flash) || (string) ($flash['form'] ?? '') !== $slug) {
            return $blank;
        }

        unset($_SESSION[self::KEY]);

        return [
            'error'  => (string) ($flash['error'] ?? ''),
            'errors' => array_map('strval', array_filter((array) ($flash['errors'] ?? []), 'is_scalar')),
            'old'    => array_map('strval', array_filter((array) ($flash['old'] ?? []), 'is_scalar')),
        ];
    }
}
