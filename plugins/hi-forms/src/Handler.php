<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Http\Request;
use HiCMS\Http\Response;
use HiCMS\Support\Str;

/**
 * Gönderim işleyicisi — POST /form-gonder
 *
 * DOĞRULAMA SUNUCUDA. Tarayıcı doğrulaması (required, type=email) yalnızca
 * kolaylık; buradaki denetimler tekrar eder ve nihai olanıdır.
 *
 * KOŞULLU ALANLARIN DOĞRULANMASI: alanlar tanım sırasıyla gezilir ve her alanın
 * görünürlüğü O ANA KADAR hesaplanmış DEĞERLERE göre belirlenir. Gizli bir
 * alanın değeri boş sayılır ve zincirin devamına boş olarak girer — yoksa
 * "A seçilirse B, B doldurulursa C" gibi bir zincirde, A değişince C hâlâ
 * eski değerine göre zorunlu kalırdı. Betik tarafı da aynı sırayı uygular.
 */
final class Handler
{
    public static function submit(Request $request): Response
    {
        $slug = Str::slug($request->text('form'));
        $form = Forms::find($slug);
        $back = self::backUrl($request);

        if ($form === null) {
            return Response::redirect($back)->withStatus(303);
        }

        $anchor = '#' . Renderer::anchor($slug);

        if (!hi()->csrf()->verify($request->text('_token'))) {
            return self::fail(
                $slug,
                $back . $anchor,
                'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.',
                [],
                []
            );
        }

        $input = $request->input('alan');
        $input = is_array($input) ? $input : [];

        /*
         * İKİ AYRI DİZİ, bilinçli:
         *
         *   $values  — HAM değerler, koşul karşılaştırması için. Betik de ham
         *              denetim değerini okuyor; temizlenmiş değerle karşılaştırmak
         *              iki tarafı ayırırdı. Onay kutusu bunun en net örneği:
         *              tarayıcı "1" görür, kayda "Evet" yazılır. Koşul "Evet"e
         *              göre değerlendirilse ekranda görünen alan sunucuda gizli
         *              sayılır ve sebebi hiçbir yerde yazmaz.
         *   $payload — kaydedilecek temizlenmiş değerler.
         *
         * Gizli alan ikisinde de yoktur: ne doğrulanır ne kaydedilir.
         *
         * @var array<string, string> $payload
         */
        $values  = [];
        $payload = [];
        $errors  = [];
        $old     = [];

        foreach ((array) $form['fields'] as $field) {
            $key = (string) $field['key'];
            $raw = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

            if (!self::visible($field, $values)) {
                $values[$key] = null;

                continue;
            }

            $values[$key] = $raw;
            $old[$key]    = mb_substr($raw, 0, 5000);
            $error        = self::validate($field, $raw);

            if ($error !== '') {
                $errors[$key] = $error;
            }

            $payload[$key] = self::clean($field, $raw);
        }

        if (!empty($form['consent']) && !$request->bool('onay')) {
            $errors['onay'] = 'Devam etmek için onay kutusunu işaretlemeniz gerekiyor.';
        }

        $fingerprint = Guard::fingerprint($request);

        /*
         * İSTENMEYEN KARARI KULLANICIYA SÖYLENMEZ: başarı görünür. Söylenirse
         * filtre kendini tarif eder ve atlatılması kolaylaşır. Bu yüzden bot
         * denetimi alan hatalarından ÖNCE gelir — bota hangi alanın eksik
         * olduğunu bildirmenin bir faydası yok.
         */
        if (Guard::isBot($request)) {
            return self::quarantine($form, $payload, $fingerprint, $request->userAgent(), $back, $anchor);
        }

        if ($errors !== []) {
            return self::fail($slug, $back . $anchor, '', $errors, $old);
        }

        $verdict = Guard::inspect($request, $payload);

        if ($verdict['error'] !== '') {
            return self::fail($slug, $back . $anchor, $verdict['error'], [], $old);
        }

        if ($verdict['spam']) {
            return self::quarantine($form, $payload, $fingerprint, $request->userAgent(), $back, $anchor);
        }

        $id = Submissions::store(
            $slug,
            $payload,
            self::subject($form, $payload),
            $fingerprint,
            $request->userAgent()
        );

        if ($id === 0) {
            return self::fail(
                $slug,
                $back . $anchor,
                'Gönderi kaydedilemedi. Site yöneticisine haber verin.',
                [],
                $old
            );
        }

        $lines = Submissions::lines($slug, $payload);

        if (Notifier::notify($form, $lines, $fingerprint)) {
            Submissions::markNotified($id);
        }

        Notifier::autoReply($form, $lines);

        hi()->events()->emit('forms.submitted', $slug, $payload, $id);

        return self::done($form, $back, $anchor);
    }

    /* ---------------------------------------------------------------------
     * Koşul ve doğrulama
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $field
     * @param array<string, string|null> $values o ana kadar hesaplanan değerler
     */
    public static function visible(array $field, array $values): bool
    {
        $on = (string) ($field['cond_field'] ?? '');

        if ($on === '') {
            return true;
        }

        $actual   = (string) ($values[$on] ?? '');
        $expected = (string) ($field['cond_value'] ?? '');

        return match ((string) ($field['cond_op'] ?? 'eq')) {
            'neq'    => $actual !== $expected,
            'filled' => $actual !== '',
            'empty'  => $actual === '',
            default  => $actual === $expected,
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function validate(array $field, string $value): string
    {
        $label = (string) $field['label'];

        if ($value === '') {
            return !empty($field['required'])
                ? Str::format('"%s" alanı zorunludur.', $label)
                : '';
        }

        return match ((string) $field['type']) {
            'email'  => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? Str::format('"%s" için geçerli bir e-posta adresi girin.', $label) : '',
            'url'    => filter_var(self::withScheme($value), FILTER_VALIDATE_URL) === false
                ? Str::format('"%s" için geçerli bir web adresi girin.', $label) : '',
            'number' => !is_numeric($value)
                ? Str::format('"%s" yalnızca sayı olabilir.', $label) : '',
            'date'   => strtotime($value) === false
                ? Str::format('"%s" için geçerli bir tarih girin.', $label) : '',
            'tel'    => preg_match('/^[0-9+()\/\s.-]{6,25}$/', $value) !== 1
                ? Str::format('"%s" için geçerli bir telefon numarası girin.', $label) : '',
            'select',
            'radio'  => !in_array($value, array_map('strval', (array) $field['choices']), true)
                ? Str::format('"%s" için listedeki seçeneklerden birini seçin.', $label) : '',
            default  => '',
        };
    }

    /**
     * Kaydedilecek biçime çevirir.
     *
     * @param array<string, mixed> $field
     */
    private static function clean(array $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return match ((string) $field['type']) {
            'checkbox' => 'Evet',
            'url'      => mb_substr(self::withScheme($value), 0, 500),
            'textarea' => mb_substr($value, 0, 5000),
            default    => mb_substr($value, 0, 500),
        };
    }

    /** Şemasız girilen adresler ("ornek.com") reddedilmesin. */
    private static function withScheme(string $value): string
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 ? $value : 'https://' . $value;
    }

    /**
     * Panelde listede görünecek özet.
     *
     * @param array<string, mixed> $form
     * @param array<string, string> $payload
     */
    private static function subject(array $form, array $payload): string
    {
        foreach ((array) $form['fields'] as $field) {
            $value = trim((string) ($payload[(string) $field['key']] ?? ''));

            if ($value !== '' && in_array((string) $field['type'], ['text', 'email', 'textarea'], true)) {
                return Str::limit($value, 120);
            }
        }

        return (string) $form['name'];
    }

    /* ---------------------------------------------------------------------
     * Yanıtlar
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private static function fail(string $slug, string $url, string $error, array $errors, array $old): Response
    {
        Flash::put($slug, $error, $errors, $old);

        return Response::redirect($url)->withStatus(303);
    }

    /**
     * İstenmeyen gönderi: ayar açıksa karantinaya alınır, kapalıysa hiç
     * kaydedilmez. Her iki hâlde ziyaretçi başarı mesajı görür.
     *
     * @param array<string, mixed> $form
     * @param array<string, string> $payload
     */
    private static function quarantine(
        array $form,
        array $payload,
        string $fingerprint,
        string $userAgent,
        string $back,
        string $anchor,
    ): Response {
        if (Forms::flag('keep_spam')) {
            Submissions::store(
                (string) $form['slug'],
                $payload,
                self::subject($form, $payload),
                $fingerprint,
                $userAgent,
                'spam'
            );
        }

        return self::done($form, $back, $anchor);
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function done(array $form, string $back, string $anchor): Response
    {
        $redirect = self::onSite((string) $form['redirect']);

        if ($redirect !== '') {
            return Response::redirect($redirect)->withStatus(303);
        }

        $url = $back . (str_contains($back, '?') ? '&' : '?')
            . 'form=' . rawurlencode((string) $form['slug']) . $anchor;

        return Response::redirect($url)->withStatus(303);
    }

    /**
     * Dönüş adresi.
     *
     * AÇIK YÖNLENDİRME KORUMASI: adres formdan (yani kullanıcı girdisinden)
     * geliyor. Sitenin kendi tabanıyla başlamayan her şey atılır; kalan son
     * çare ana sayfadır. Referer yalnızca yedek — vekiller ve tarayıcı ayarları
     * onu boş bırakabiliyor.
     */
    private static function backUrl(Request $request): string
    {
        foreach ([$request->text('donus'), $request->referer()] as $candidate) {
            $url = self::onSite($candidate);

            if ($url !== '') {
                return $url;
            }
        }

        return hi()->urls()->to();
    }

    /** Siteye ait olmayan adresler için boş döner. */
    private static function onSite(string $url): string
    {
        $url  = trim($url);
        $base = rtrim(hi()->urls()->to(), '/');

        if ($url === '') {
            return '';
        }

        // Göreli yol verildiyse (yönlendirme ayarı) siteye bağlanır.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return hi()->urls()->to(ltrim($url, '/'));
        }

        return str_starts_with($url, $base . '/') || $url === $base || $url === $base . '/' ? $url : '';
    }
}
