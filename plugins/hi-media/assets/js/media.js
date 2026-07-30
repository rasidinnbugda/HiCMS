/* =========================================================================
   HiMedia — toplu üretim sürdürücü
   -------------------------------------------------------------------------
   Tek iş yapar: bir parti bittiğinde sıradaki partiyi kendiliğinden başlatır.
   Yüzlerce görsel için düğmeye elli kez basmak gerekmesin.

   JS KAPALIYKEN hiçbir şey kaybolmaz: form normal bir POST formudur, kullanıcı
   her partide bir kez "Sonraki partiyi üret" der. Bu dosya yalnızca o tıklamayı
   üstlenir.

   SÖZLEŞMELER
     • Kurulum HiAdmin.onMount() içinde: nav.js bölge değiştirdiğinde yeni gelen
       forma yeniden bağlanmak gerekiyor. HiAdmin.once() aynı forma iki kez
       bağlanmayı engelliyor.
     • Sunucu verisi hi_admin_data('hi-media') ile JSON düğümü olarak basılıyor;
       satır içi <script>window.X = …</script> bölge değişiminde ÇALIŞMAZ.
     • Ama JSON düğümü <main> DIŞINDA (admin.footer) basıldığı için bölge
       değişiminden sonra eskimiş kalabiliyor. O yüzden karar formun data-*
       öznitelikleriyle DOĞRULANIYOR: onlar bölgeyle birlikte geliyor, yani
       her zaman taze.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.HiAdmin || !window.HiAdmin.onMount) {
        return; // panel betiği yüklenmemiş: form elle çalışmaya devam eder
    }

    /* Bir oturumda en fazla bu kadar parti kendiliğinden koşar. Sunucu tarafı
       her partide ilerlediği için gerekmiyor, ama beklenmeyen bir durumda
       tarayıcının sonsuz döngüye girmemesi garanti altında olsun. */
    var MAX_CHAIN = 200;
    var DELAY_MS = 500;

    var chain = 0;

    function flag(form, name) {
        return form.getAttribute('data-' + name) === '1';
    }

    function number(form, name) {
        return parseInt(form.getAttribute('data-' + name) || '0', 10) || 0;
    }

    function setStatus(form, text) {
        var box = form.querySelector('[data-himedia-status]');

        if (box) {
            box.textContent = text;
        }
    }

    window.HiAdmin.onMount(function (scope) {
        var root = scope || document;
        var form = root.querySelector('[data-himedia-batch]');

        if (!form || !window.HiAdmin.once(form, 'himedia-batch')) {
            return;
        }

        var data = window.HiAdmin.data('hi-media');
        var processed = number(form, 'islenen');
        var more = flag(form, 'devam');

        /* Sunucu JSON'u yalnızca taze olduğunda (imleç aynı) dikkate alınır. */
        if (data && typeof data.kalan === 'number' && data.imlec === number(form, 'imlec')) {
            setStatus(form, 'Kalan: ' + data.kalan);
        }

        /* İlk açılışta hiç parti koşmadıysa kullanıcı başlatır — kendiliğinden
           iş yapmak, sayfayı açmakla üretim başlatmayı aynı şey yapardı. */
        if (processed < 1) {
            return;
        }

        if (!more) {
            setStatus(form, 'Bitti: sırada iş kalmadı.');

            if (window.HiAdmin.toast) {
                window.HiAdmin.toast('Toplu üretim tamamlandı.');
            }

            return;
        }

        if (++chain > MAX_CHAIN) {
            setStatus(form, 'Otomatik sürdürme durdu; devam için düğmeye basın.');

            return;
        }

        setStatus(form, 'Sıradaki parti başlıyor…');

        window.setTimeout(function () {
            /* Form hâlâ belgede mi? Kullanıcı bu arada başka sayfaya geçmiş olabilir. */
            if (!form.isConnected) {
                return;
            }

            var button = form.querySelector('[data-himedia-run]');

            if (button) {
                button.disabled = true;
                button.textContent = 'Üretiliyor…';
            }

            form.submit();
        }, DELAY_MS);
    });
})();
