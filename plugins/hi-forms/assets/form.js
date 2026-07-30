/* =========================================================================
   HiForms — koşullu alanlar (ön yüz)
   -------------------------------------------------------------------------
   Bu betik OLMASA DA form çalışır: sunucu tüm alanları görünür basar,
   koşulları kendisi değerlendirir ve gizli alanı zorunlu saymaz. Betiğin işi
   yalnızca ekranı kurala uydurmak.

   Sözleşme işaretlemede durur, burada değil:
     data-hi-form="<kısa ad>"        formun kendisi
     data-hf-field="<anahtar>"       alan sarmalayıcı
     data-hf-when / -op / -value     koşul
     data-hf-required="1"            koşullu alanın gerçek zorunluluğu

   Sunucu ile AYNI sırayla değerlendirir: alanlar tanım sırasıyla gezilir,
   gizli bir alanın değeri boş sayılır ve zincirin devamına boş girer.
   ========================================================================= */

(function () {
    'use strict';

    var list = function (nodes) {
        return Array.prototype.slice.call(nodes);
    };

    /** Sarmalayıcının o anki değeri. */
    function readValue(wrap) {
        var radios = wrap.querySelectorAll('input[type="radio"]');

        if (radios.length) {
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    return radios[i].value;
                }
            }

            return '';
        }

        var box = wrap.querySelector('input[type="checkbox"]');

        if (box) {
            return box.checked ? box.value : '';
        }

        var control = wrap.querySelector('input, select, textarea');

        return control ? control.value : '';
    }

    function matches(operator, actual, expected) {
        if (operator === 'neq') {
            return actual !== expected;
        }

        if (operator === 'filled') {
            return actual !== '';
        }

        if (operator === 'empty') {
            return actual === '';
        }

        return actual === expected;
    }

    function setup(form) {
        var wraps = list(form.querySelectorAll('[data-hf-field]'));

        if (!wraps.length) {
            return;
        }

        function apply() {
            var values = {};

            wraps.forEach(function (wrap) {
                var when = wrap.getAttribute('data-hf-when');
                var visible = true;

                if (when) {
                    visible = matches(
                        wrap.getAttribute('data-hf-op') || 'eq',
                        Object.prototype.hasOwnProperty.call(values, when) ? values[when] : '',
                        wrap.getAttribute('data-hf-value') || ''
                    );
                }

                wrap.hidden = !visible;

                /*
                 * Görünmeyen alan zorunlu OLAMAZ: tarayıcı ekranda olmayan bir
                 * alanı zorunlu tutarsa form hiç gönderilemez ve kullanıcı
                 * sebebini göremez.
                 */
                list(wrap.querySelectorAll('[data-hf-required]')).forEach(function (control) {
                    if (visible) {
                        control.setAttribute('required', 'required');
                    } else {
                        control.removeAttribute('required');
                    }
                });

                values[wrap.getAttribute('data-hf-field')] = visible ? readValue(wrap) : '';
            });
        }

        form.addEventListener('input', apply);
        form.addEventListener('change', apply);

        apply();
    }

    function init() {
        list(document.querySelectorAll('form[data-hi-form]')).forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
