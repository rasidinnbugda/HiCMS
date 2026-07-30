<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Support\Str;

/**
 * Ön yüz form çıktısı.
 *
 * TASARIM KURALI: JS KAPALIYKEN FORM ÇALIŞIR. Bunun iki sonucu var:
 *
 *   1. Koşullu alanlar sunucuda GİZLİ BASILMAZ. Betik yoksa hepsi görünür;
 *      kullanıcı fazladan alan görür ama formu gönderebilir. Ters yapılsaydı
 *      (gizli basıp JS ile açmak) betiksiz ziyaretçi alanlara hiç ulaşamazdı.
 *   2. Koşullu alanlara `required` özniteliği BASILMAZ, `data-hf-required`
 *      basılır. Tarayıcı görünmeyen bir alanı zorunlu tutarsa form hiç
 *      gönderilemez ve sebebi ekranda görünmez. Gerçek kural sunucuda: gizli
 *      alan zorunlu sayılmaz.
 *
 * Koşullar veri özniteliğiyle taşınır (`data-hf-when/op/value`), satır içi
 * betikle değil: işaretlemenin kendisi sözleşme olur, betik yalnızca onu okur.
 *
 * Yeni CSS getirmez — tema sınıflarını kullanır (.block-form, .field,
 * .field-row, .input, .textarea, .consent, .form-note, .button, .req).
 */
final class Renderer
{
    /** Yalnızca sayısal/metin girdisine çevrilebilen türler. */
    private const INPUT_TYPES = ['text', 'email', 'tel', 'url', 'number', 'date'];

    public static function render(string $slug, string $heading = ''): string
    {
        $form = Forms::find($slug);

        if ($form === null) {
            return '';
        }

        $request = hi()->request();
        $flash   = Flash::take($slug);
        $sent    = (string) $request->query('form', '') === $slug;

        $html = '<div class="block-form" id="' . Str::attr(self::anchor($slug)) . '">';

        if ($heading !== '') {
            $html .= '<h2 class="block-form-title">' . Str::html($heading) . '</h2>';
        }

        if ($flash['error'] !== '') {
            $html .= '<p class="form-note" role="alert">' . Str::html($flash['error']) . '</p>';
        } elseif ($flash['errors'] !== []) {
            $html .= '<p class="form-note" role="alert">'
                . 'Gönderi tamamlanmadı: aşağıda işaretli alanları düzeltin.</p>';
        } elseif ($sent) {
            $html .= '<p class="form-note" role="status">'
                . Str::html(self::successMessage($form)) . '</p>';
        }

        $html .= '<form method="post" action="' . Str::url(hi()->urls()->to('form-gonder')) . '"'
            . ' data-hi-form="' . Str::attr($slug) . '">'
            . hi()->csrf()->field()
            . '<input type="hidden" name="form" value="' . Str::attr($slug) . '">'
            . '<input type="hidden" name="donus" value="' . Str::attr(self::returnUrl()) . '">'
            . '<input type="hidden" name="' . Guard::STAMP . '" value="' . Str::attr(Guard::stamp()) . '">'
            . self::honeypot($slug);

        foreach (self::group((array) $form['fields']) as $row) {
            $cells = [];

            foreach ($row as $field) {
                $cells[] = self::field($field, $slug, $flash);
            }

            $html .= count($cells) > 1
                ? '<div class="field-row">' . implode('', $cells) . '</div>'
                : implode('', $cells);
        }

        if (!empty($form['consent'])) {
            $html .= '<div class="field">'
                . '<label class="consent">'
                . '<input type="checkbox" name="onay" value="1" required>'
                . '<span>' . Str::html((string) Forms::setting('consent_text', '')) . '</span>'
                . '</label>'
                . self::error('onay', $flash)
                . '</div>';
        }

        $html .= '<button class="button button-primary" type="submit">'
            . Str::html((string) $form['submit_label']) . '</button>'
            . '</form></div>';

        if (self::hasConditions($form)) {
            /*
             * Betik yalnızca koşullu alan varsa yüklenir ve alt bilgide durur:
             * içerik bloğu gövdede basıldığı için başlık çoktan yazılmış olur.
             */
            hi_enqueue_script(
                'hi-forms',
                hi()->urls()->plugin(Forms::SLUG, 'assets/form.js'),
                [],
                '1.1.0'
            );
        }

        return $html;
    }

    public static function anchor(string $slug): string
    {
        return 'hf-' . Str::slug($slug);
    }

    /** @param array<string, mixed> $form */
    public static function successMessage(array $form): string
    {
        $own = trim((string) $form['success']);

        if ($own !== '') {
            return $own;
        }

        $global = trim((string) Forms::setting('success_message', ''));

        return $global !== '' ? $global : 'Gönderiniz alındı.';
    }

    /** @param array<string, mixed> $form */
    public static function hasConditions(array $form): bool
    {
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if ((string) ($field['cond_field'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     * Parçalar
     * ------------------------------------------------------------------ */

    /**
     * Yarım genişlikli alanları ikişerli satırlara toplar.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<list<array<string, mixed>>>
     */
    private static function group(array $fields): array
    {
        $rows = [];
        $open = [];

        foreach ($fields as $field) {
            $half = ($field['width'] ?? 'full') === 'half' && ($field['type'] ?? '') !== 'textarea';

            if (!$half) {
                if ($open !== []) {
                    $rows[] = $open;
                    $open   = [];
                }

                $rows[] = [$field];

                continue;
            }

            $open[] = $field;

            if (count($open) === 2) {
                $rows[] = $open;
                $open   = [];
            }
        }

        if ($open !== []) {
            $rows[] = $open;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $field
     * @param array{error: string, errors: array<string, string>, old: array<string, string>} $flash
     */
    private static function field(array $field, string $slug, array $flash): string
    {
        $key   = (string) $field['key'];
        $type  = (string) $field['type'];
        $label = (string) $field['label'];
        $id    = 'hf-' . Str::slug($slug . '-' . $key);
        $name  = 'alan[' . $key . ']';
        $value = (string) ($flash['old'][$key] ?? '');

        $wrapper = '<div class="field" data-hf-field="' . Str::attr($key) . '"' . self::condition($field) . '>';

        // Onay kutusunda etiket girdinin İÇİNDE durur; ayrı bir <label> basılmaz.
        if ($type === 'checkbox') {
            return $wrapper
                . '<label class="consent">'
                . '<input type="checkbox" id="' . Str::attr($id) . '" name="' . Str::attr($name) . '"'
                . ' value="1"' . ($value !== '' ? ' checked' : '') . self::requiredAttr($field) . '>'
                . '<span>' . Str::html($label) . self::mark($field) . '</span>'
                . '</label>'
                . self::help($field)
                . self::error($key, $flash)
                . '</div>';
        }

        $html = $wrapper . '<label for="' . Str::attr($id) . '">'
            . Str::html($label) . self::mark($field) . '</label>';

        $html .= match ($type) {
            'textarea' => '<textarea class="textarea" id="' . Str::attr($id) . '" name="' . Str::attr($name) . '"'
                . self::placeholder($field) . self::requiredAttr($field) . '>' . Str::html($value) . '</textarea>',
            'select'   => self::select($field, $id, $name, $value),
            'radio'    => self::radios($field, $id, $name, $value),
            default    => '<input class="input" type="'
                . Str::attr(in_array($type, self::INPUT_TYPES, true) ? $type : 'text') . '"'
                . ' id="' . Str::attr($id) . '" name="' . Str::attr($name) . '"'
                . ' value="' . Str::attr($value) . '"'
                . self::placeholder($field) . self::requiredAttr($field) . '>',
        };

        return $html . self::help($field) . self::error($key, $flash) . '</div>';
    }

    /** @param array<string, mixed> $field */
    private static function select(array $field, string $id, string $name, string $value): string
    {
        // Boş ilk seçenek her zaman basılır: yoksa "zorunlu" seçim anlamsızlaşır.
        $html = '<select class="input" id="' . Str::attr($id) . '" name="' . Str::attr($name) . '"'
            . self::requiredAttr($field) . '><option value="">— seçin —</option>';

        foreach ((array) $field['choices'] as $choice) {
            $choice = (string) $choice;

            $html .= '<option value="' . Str::attr($choice) . '"'
                . ($choice === $value ? ' selected' : '') . '>' . Str::html($choice) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param array<string, mixed> $field */
    private static function radios(array $field, string $id, string $name, string $value): string
    {
        $html  = '';
        $index = 0;

        foreach ((array) $field['choices'] as $choice) {
            $choice = (string) $choice;
            $index++;

            $html .= '<label class="consent">'
                . '<input type="radio" id="' . Str::attr($id . '-' . $index) . '"'
                . ' name="' . Str::attr($name) . '" value="' . Str::attr($choice) . '"'
                . ($choice === $value ? ' checked' : '') . self::requiredAttr($field) . '>'
                . '<span>' . Str::html($choice) . '</span></label>';
        }

        return $html;
    }

    /**
     * Koşul veri öznitelikleri.
     *
     * @param array<string, mixed> $field
     */
    private static function condition(array $field): string
    {
        $on = (string) ($field['cond_field'] ?? '');

        if ($on === '') {
            return '';
        }

        return ' data-hf-when="' . Str::attr($on) . '"'
            . ' data-hf-op="' . Str::attr((string) $field['cond_op']) . '"'
            . ' data-hf-value="' . Str::attr((string) $field['cond_value']) . '"';
    }

    /**
     * Zorunluluk özniteliği. Koşullu alanda tarayıcıya DEĞİL betiğe söylenir.
     *
     * @param array<string, mixed> $field
     */
    private static function requiredAttr(array $field): string
    {
        if (empty($field['required'])) {
            return '';
        }

        return (string) ($field['cond_field'] ?? '') === '' ? ' required' : ' data-hf-required="1"';
    }

    /** @param array<string, mixed> $field */
    private static function mark(array $field): string
    {
        return empty($field['required']) ? '' : ' <span class="req" aria-hidden="true">*</span>';
    }

    /** @param array<string, mixed> $field */
    private static function placeholder(array $field): string
    {
        $text = (string) ($field['placeholder'] ?? '');

        return $text !== '' ? ' placeholder="' . Str::attr($text) . '"' : '';
    }

    /** @param array<string, mixed> $field */
    private static function help(array $field): string
    {
        $text = (string) ($field['help'] ?? '');

        return $text !== '' ? '<small>' . Str::html($text) . '</small>' : '';
    }

    /**
     * @param array{error: string, errors: array<string, string>, old: array<string, string>} $flash
     */
    private static function error(string $key, array $flash): string
    {
        $message = (string) ($flash['errors'][$key] ?? '');

        return $message !== ''
            ? '<strong class="req" role="alert">' . Str::html($message) . '</strong>'
            : '';
    }

    private static function honeypot(string $slug): string
    {
        if (!Forms::flag('honeypot')) {
            return '';
        }

        $id = 'hf-' . Str::slug($slug) . '-hp';

        return '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"'
            . ' aria-hidden="true">'
            . '<label for="' . Str::attr($id) . '">Web adresiniz</label>'
            . '<input type="text" id="' . Str::attr($id) . '" name="' . Guard::HONEYPOT . '"'
            . ' value="" tabindex="-1" autocomplete="off">'
            . '</div>';
    }

    /**
     * Gönderim sonrası dönülecek adres.
     *
     * Referer'a güvenilmez (vekil/eklenti temizler, tarayıcı ayarı kapatır);
     * formun kendisi nereye döneceğini taşır. Sunucu tarafında adresin siteye
     * ait olduğu ayrıca doğrulanır.
     */
    private static function returnUrl(): string
    {
        $request = hi()->request();
        $query   = $request->query;

        // Kendi işaretlerimiz dönüş adresinde birikmesin.
        unset($query['form'], $query['hf']);

        $path = ltrim($request->path, '/');

        return hi()->urls()->to($path) . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
