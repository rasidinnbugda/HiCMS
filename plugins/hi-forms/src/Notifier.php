<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * E-posta bildirimi.
 *
 * Tek araç PHP'nin `mail()` fonksiyonu: eklenti üçüncü parti kütüphane
 * kullanmıyor ve HiCMS çekirdeğinde posta katmanı yok. `mail()` bulunmayan ya
 * da kapalı olan sunucularda gönderim SESSİZCE ATLANIR, gönderi yine kaydedilir
 * — bildirim gidemedi diye ziyaretçinin mesajını kaybetmek en kötü sonuç olur.
 *
 * Bildirimin gerçekten gidip gitmediği gönderi kaydına yazılır (`notified`),
 * böylece panel "gitti mi?" sorusuna tahminle değil kayıtla cevap verir.
 */
final class Notifier
{
    /**
     * Yöneticiye bildirim gönderir.
     *
     * @param array<string, mixed> $form
     * @param list<array{key: string, label: string, value: string}> $lines
     */
    public static function notify(array $form, array $lines, string $ip): bool
    {
        if (!Forms::flag('notify')) {
            return false;
        }

        $to = $form['notify_to'] !== [] ? $form['notify_to'] : Forms::lines('notify_to');
        $to = array_values(array_filter(
            $to,
            static fn(string $mail): bool => filter_var($mail, FILTER_VALIDATE_EMAIL) !== false
        ));

        if ($to === []) {
            return false;
        }

        $body = [
            Str::format('%s sitesindeki "%s" formuna yeni bir gönderi geldi.', hi_site_name(), (string) $form['name']),
            '',
        ];

        foreach ($lines as $line) {
            $body[] = $line['label'] . ': ' . $line['value'];
        }

        $body[] = '';
        $body[] = 'Tarih: ' . Dates::format(Dates::stamp(), 'j F Y, H:i');

        if ($ip !== '') {
            $body[] = 'Gönderen imzası: ' . $ip;
        }

        $body[] = 'Panel: ' . hi()->urls()->admin('plugin.php?eklenti=hi-forms');

        $headers = self::headers(self::replyTo($form, $lines));
        $subject = Str::format('[%s] %s', hi_site_name(), (string) $form['name']);

        return self::send(implode(', ', $to), $subject, implode("\r\n", $body), $headers);
    }

    /**
     * Gönderene otomatik yanıt. Yalnızca formda açıksa ve yanıt adresi
     * bulunabiliyorsa gönderilir.
     *
     * @param array<string, mixed> $form
     * @param list<array{key: string, label: string, value: string}> $lines
     */
    public static function autoReply(array $form, array $lines): bool
    {
        if (!Forms::flag('notify') || empty($form['autoreply'])) {
            return false;
        }

        $to = self::replyTo($form, $lines);

        if ($to === '') {
            return false;
        }

        $body = trim((string) $form['autoreply_body']);

        if ($body === '') {
            return false;
        }

        /*
         * Yer tutucular: gönderenin doldurduğu alanlar metne girebilir.
         * Değerler düz metne yazıldığı için kaçış gerekmez.
         */
        $replace = ['{site}' => hi_site_name(), '{form}' => (string) $form['name']];

        foreach ($lines as $line) {
            $replace['{' . $line['key'] . '}'] = $line['value'];
        }

        $subject = trim((string) $form['autoreply_subject']);
        $subject = $subject !== '' ? $subject : Str::format('%s — mesajınızı aldık', hi_site_name());

        return self::send(
            $to,
            strtr($subject, $replace),
            strtr($body, $replace),
            self::headers('')
        );
    }

    /* ---------------------------------------------------------------------
     * İç işler
     * ------------------------------------------------------------------ */

    /**
     * Gönderenin adresi: formda hangi alanın yanıt adresi olduğu belirtilmişse
     * o, yoksa ilk geçerli e-posta değeri.
     *
     * @param array<string, mixed> $form
     * @param list<array{key: string, label: string, value: string}> $lines
     */
    private static function replyTo(array $form, array $lines): string
    {
        $wanted = (string) $form['reply_field'];

        foreach ($lines as $line) {
            if ($wanted !== '' && $line['key'] !== $wanted) {
                continue;
            }

            if (filter_var($line['value'], FILTER_VALIDATE_EMAIL) !== false) {
                return $line['value'];
            }
        }

        return '';
    }

    /** @return list<string> */
    private static function headers(string $replyTo): array
    {
        $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];

        $fromMail = trim((string) Forms::setting('from_email', ''));

        if (filter_var($fromMail, FILTER_VALIDATE_EMAIL) !== false) {
            $fromName = trim((string) Forms::setting('from_name', ''));
            $fromName = $fromName !== '' ? $fromName : hi_site_name();

            $headers[] = 'From: ' . self::encode($fromName) . ' <' . $fromMail . '>';
        }

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        return $headers;
    }

    /**
     * @param list<string> $headers
     */
    private static function send(string $to, string $subject, string $body, array $headers): bool
    {
        if (!function_exists('mail')) {
            return false;
        }

        /*
         * Başlıklara satır sonu enjeksiyonu: konu ve adres kullanıcı verisinden
         * gelebiliyor. Base64 kodlaması konuyu güvenli hâle getirir, adresler
         * ise FILTER_VALIDATE_EMAIL'den geçmiş oluyor.
         */
        return @mail(
            $to,
            self::encode(str_replace(["\r", "\n"], ' ', $subject)),
            $body,
            implode("\r\n", $headers)
        );
    }

    /** RFC 2047 — başlıkta Türkçe karakter. */
    private static function encode(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
