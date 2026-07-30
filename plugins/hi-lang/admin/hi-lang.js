/* =========================================================================
   HiLang — dil tanımları ekranı için ilerlemeli iyileştirme
   -------------------------------------------------------------------------
   Bu dosya OLMADAN da ekran tam çalışır: sunucu mevcut dillerden bir fazla
   satır basıyor, birincil dil radyo düğmesiyle seçiliyor, kaydetme normal
   form gönderimiyle oluyor. Buradaki iki iş yalnızca yazma yükünü azaltır:

     1. Dil kodu yazıldığında boş olan ad ve önek alanlarını doldurmak.
     2. Aynı anda birkaç dil eklemek için satır eklemek.

   SÖZLEŞME
     • Veri sunucudan `hi_admin_data('hi-lang', …)` ile geliyor ve
       `HiAdmin.data('hi-lang')` ile okunuyor. Satır içi `window.X = …`
       yazılmıyor: anında sayfa geçişinde (nav.js) bölge değişimi <script>
       etiketlerini çalıştırmıyor, veri kaybolurdu.
     • Kurulum `HiAdmin.onMount()` içinde: bölge her değiştiğinde yeni gelen
       DOM'un davranış kazanması gerekiyor. Aynı DOM'a iki kez bağlanmamak
       için `HiAdmin.once()`.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.HiAdmin) {
        return;
    }

    /** Betik ilk yüklemede footer'da; veri o anda hazır olmayabilir, her seferinde okunur. */
    function config() {
        var data = HiAdmin.data('hi-lang') || {};

        return {
            max: parseInt(data.max, 10) > 0 ? parseInt(data.max, 10) : 8,
            suggest: data.suggest && typeof data.suggest === 'object' ? data.suggest : {},
        };
    }

    /** `tr-tr`, `TR_tr` → `tr_TR`; tanınmayan biçim için boş dize. */
    function normalize(value) {
        var match = /^([a-z]{2,3})(?:[_-]([a-z]{2}))?$/i.exec(String(value || '').trim());

        if (!match) {
            return '';
        }

        return match[1].toLowerCase() + (match[2] ? '_' + match[2].toUpperCase() : '');
    }

    function field(row, role) {
        return row.querySelector('[data-role="' + role + '"]');
    }

    /** Satırdaki kod alanına göre boş olan ad/önek alanlarını doldurur. */
    function suggest(row, table) {
        var code = field(row, 'code');
        var label = field(row, 'label');
        var prefix = field(row, 'prefix');

        if (!code) {
            return;
        }

        var normalized = normalize(code.value);

        if (normalized === '') {
            return;
        }

        var known = table[normalized] || {};

        if (label && label.value.trim() === '' && known.label) {
            label.value = known.label;
        }

        if (prefix && prefix.value.trim() === '') {
            prefix.value = known.prefix || normalized.split('_')[0].toLowerCase();
        }
    }

    /**
     * Yeni satır: son satırı kopyalayıp değerleri temizler ve adları yeniden
     * numaralar. Şablonu sunucudan almak yerine kopyalamak, işaretlemeyi tek
     * yerde (PHP) tutuyor.
     */
    function addRow(rows, index) {
        var last = rows.lastElementChild;

        if (!last) {
            return null;
        }

        var fresh = last.cloneNode(true);

        fresh.setAttribute('data-index', String(index));

        Array.prototype.forEach.call(fresh.querySelectorAll('input'), function (input) {
            if (input.type === 'radio') {
                input.value = String(index);
                input.checked = false;

                return;
            }

            input.value = '';

            var name = input.getAttribute('name') || '';
            input.setAttribute('name', name.replace(/\[\d+\]/, '[' + index + ']'));

            var id = input.getAttribute('id') || '';

            if (id) {
                input.setAttribute('id', id.replace(/-\d+$/, '-' + index));
            }

            var aria = input.getAttribute('aria-label') || '';

            if (aria) {
                input.setAttribute('aria-label', aria.replace(/\d+$/, String(index + 1)));
            }
        });

        rows.appendChild(fresh);

        return fresh;
    }

    HiAdmin.onMount(function (scope) {
        var root = (scope || document).querySelector('[data-hi-lang-locales]');

        if (!root || !HiAdmin.once(root, 'hi-lang-locales')) {
            return;
        }

        var settings = config();
        var rows = root.querySelector('[data-hi-lang-rows]');
        var add = root.querySelector('[data-hi-lang-add]');

        if (!rows) {
            return;
        }

        var max = parseInt(root.getAttribute('data-max'), 10) || settings.max;

        // Kod alanından çıkıldığında öneri uygula (yazarken değil: kullanıcı
        // henüz kodu bitirmemiş olabilir).
        rows.addEventListener('change', function (event) {
            var input = event.target;

            if (!input || input.getAttribute('data-role') !== 'code') {
                return;
            }

            var row = input.closest('[data-hi-lang-row]');

            if (row) {
                suggest(row, settings.suggest);
            }
        });

        if (!add) {
            return;
        }

        function refresh() {
            var count = rows.querySelectorAll('[data-hi-lang-row]').length;
            add.hidden = count >= max;
        }

        add.addEventListener('click', function () {
            var count = rows.querySelectorAll('[data-hi-lang-row]').length;

            if (count >= max) {
                return;
            }

            var fresh = addRow(rows, count);

            refresh();

            if (fresh) {
                var code = field(fresh, 'code');

                if (code) {
                    code.focus();
                }
            }
        });

        refresh();
    });
})();
