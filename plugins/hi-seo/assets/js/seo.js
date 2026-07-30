/* =========================================================================
   HiSEO — arama sonucu önizlemesi ve karakter sayaçları

   BU DOSYA OLMADAN DA HER ŞEY ÇALIŞIR. Önizleme sunucuda basılıyor, sayaçlar
   sunucuda hesaplanmış değerlerle geliyor, form normal bir form. Betik yalnızca
   yazarken canlı geri bildirim ekliyor.

   KURULUM HiAdmin.onMount İÇİNDE. Panel bölge değişimiyle geziniyor
   (admin/assets/js/nav.js): <main> yeniden yazıldığında bu betik TEKRAR
   çalıştırılmaz, yalnızca mount kancaları çağrılır. Kurulumu doğrudan yapmak,
   SEO ekranına ikinci kez girildiğinde önizlemenin ölmesi demekti.

   Veri satır içi <script> ile DEĞİL, hi_admin_data() ile geliyor: bölge
   değişiminde gelen betik etiketleri çalıştırılmıyor, JSON öğeleri okunabilir
   kalıyor.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.HiAdmin || !window.HiAdmin.onMount) {
        return; // panel betiği yüklenmemiş: sunucu çıktısı olduğu gibi kalır
    }

    /** Sınır aşımını da bildiren sayaç metni. */
    function counterText(length, limit) {
        return length + ' / ' + limit + ' karakter' + (length > limit ? ' — kırpılır' : '');
    }

    function kirp(text, limit) {
        return text.length > limit ? text.slice(0, limit - 1) + '…' : text;
    }

    function setup(scope) {
        var root = scope || document;
        var preview = root.querySelector('[data-seo-preview]');
        var fields = root.querySelectorAll('[data-seo-count]');

        if (!fields.length) return;

        var data = window.HiAdmin.data('hi-seo-onizleme') || {};

        Array.prototype.forEach.call(fields, function (field) {
            // Aynı alana ikinci dinleyici bağlanmasın.
            if (!window.HiAdmin.once(field, 'seo-count')) return;

            var limit = parseInt(field.getAttribute('data-seo-count'), 10) || 60;
            var kind = field.getAttribute('data-seo-field') || 'title';
            var counter = root.querySelector('[data-seo-counter="' + kind + '"]');

            var target = null;

            if (preview) {
                target = kind === 'title'
                    ? preview.querySelector('[data-seo-preview-title]')
                    : preview.querySelector('[data-seo-preview-text]');
            }

            var fallback = kind === 'title'
                ? (data.fallbackTitle || '')
                : (data.fallbackText || '');

            var sync = function () {
                var value = field.value.trim();
                var shown = value !== '' ? value : fallback;

                if (counter) {
                    counter.textContent = counterText(shown.length, limit);
                    counter.classList.toggle('is-over', shown.length > limit);
                }

                if (target) {
                    target.textContent = kirp(shown, limit);
                }
            };

            field.addEventListener('input', sync);
            sync();
        });
    }

    window.HiAdmin.onMount(setup);
})();
