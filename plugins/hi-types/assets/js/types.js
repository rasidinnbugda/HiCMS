/* =========================================================================
   HiTypes — alan düzenleyici
   -------------------------------------------------------------------------
   Bağımlılık yok. Yaptığı üç iş var:

     1. Alan/alt alan satırı ekleyip çıkarmak (şablondan kopyalayarak).
     2. Alan türüne göre gereksiz kutuları saklamak — "seçenekler" yalnızca
        açılır listede, "grup alanları" yalnızca yinelenen grupta anlamlı.
     3. Etiketten anahtar önermek.

   JS KAPALIYKEN: sunucu var olan alanların ardına iki boş yuva basar,
   anahtarı boş bırakılan yuva kaydedilmez. Bütün kutular görünür durur ve
   ilgisiz olanlar sunucu tarafında yok sayılır. Yani düzenleyici betiksiz de
   tam işlevlidir; bu dosya yalnızca daha az tıklama demek.

   KURULUM SÖZLEŞMESİ: kurulum HiAdmin.onMount() içinde. Panel anında
   gezinmede yalnızca `main.content` bölgesini değiştiriyor; bu dosya bir kez
   yüklenir, kurulum her bölge değişiminde yeniden koşar. Aynı DOM'a ikinci
   dinleyici bağlanmasını HiAdmin.once() engelliyor.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.HiAdmin || !window.HiAdmin.onMount) {
        return; // panel betiği yüklenmemiş: sunucu biçimi tek başına çalışır
    }

    var HiAdmin = window.HiAdmin;

    /* ------------------------------------------------------------- yardımcı */

    /** Kapsamın kendisi de eşleşiyorsa listeye girer. */
    function each(scope, selector, callback) {
        if (scope.nodeType === 1 && scope.matches && scope.matches(selector)) {
            callback(scope);
        }

        Array.prototype.forEach.call(scope.querySelectorAll(selector), callback);
    }

    /**
     * Yalnızca BU gruba/satıra ait öğeler. İç içe gruplarda (yinelenen grup)
     * seçici hem dıştaki hem içteki öğeleri bulur; sahibine bakarak süzülür.
     */
    function owned(root, selector, ownerSelector) {
        var result = [];

        Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
            if (node.closest(ownerSelector) === root) {
                result.push(node);
            }
        });

        return result;
    }

    function first(root, selector, ownerSelector) {
        var found = owned(root, selector, ownerSelector);

        return found.length > 0 ? found[0] : null;
    }

    function config() {
        var data = HiAdmin.data ? HiAdmin.data('hi-types') : null;

        // Veri yoksa hiçbir kutu saklanmaz: JS kapalı hâline eşdeğer.
        return data && data.shows ? data : null;
    }

    function rows(list) {
        var found = [];

        Array.prototype.forEach.call(list.children, function (node) {
            if (node.hasAttribute && node.hasAttribute('data-hit-row')) {
                found.push(node);
            }
        });

        return found;
    }

    /* --------------------------------------------------------- tür bağlı kutu */

    function applyType(row, cfg) {
        var select = first(row, '[data-hit-type]', '[data-hit-row]');

        if (!select) {
            return;
        }

        var shows = cfg ? (cfg.shows[select.value] || []) : null;

        owned(row, '[data-hit-when]', '[data-hit-row]').forEach(function (box) {
            box.hidden = shows ? shows.indexOf(box.getAttribute('data-hit-when')) === -1 : false;
        });
    }

    /* ------------------------------------------------------------- numaralama */

    function renumber(group) {
        var list = first(group, '[data-hit-list]', '[data-hit-group]');

        if (!list) {
            return;
        }

        var items = rows(list);
        var max = parseInt(group.getAttribute('data-hit-max') || '0', 10) || 0;

        items.forEach(function (row, index) {
            var badge = first(row, '[data-hit-no]', '[data-hit-row]');

            if (badge) {
                badge.textContent = String(index + 1);
            }
        });

        var add = first(group, '[data-hit-add]', '[data-hit-group]');

        if (add && max > 0) {
            add.disabled = items.length >= max;
            add.title = add.disabled ? 'En fazla ' + max + ' satır' : '';
        }
    }

    /* ------------------------------------------------------------ satır ekleme */

    function addRow(group, cfg) {
        var list = first(group, '[data-hit-list]', '[data-hit-group]');
        var tpl = first(group, 'template[data-hit-template]', '[data-hit-group]');

        if (!list || !tpl) {
            return;
        }

        var current = rows(list);
        var max = parseInt(group.getAttribute('data-hit-max') || '0', 10) || 0;

        if (max > 0 && current.length >= max) {
            return;
        }

        /*
         * Ad sırası ARTAN bir sayaçtan gelir, satır sayısından değil: satır
         * silindikten sonra sayıya dönmek var olan bir satırın adını ezerdi
         * (alan[2] iki kez) ve iki alandan biri sunucuda kaybolurdu.
         */
        var seq = parseInt(group.getAttribute('data-hit-seq') || '', 10);

        if (isNaN(seq)) {
            seq = current.length;
        }

        group.setAttribute('data-hit-seq', String(seq + 1));

        var token = group.getAttribute('data-hit-token') || '__i__';
        var holder = document.createElement('div');

        holder.innerHTML = tpl.innerHTML.split(token).join(String(seq));

        var node = holder.firstElementChild;

        if (!node) {
            return;
        }

        list.appendChild(node);
        mount(node, cfg);
        renumber(group);

        var focusable = node.querySelector('input, select, textarea');

        if (focusable) {
            focusable.focus();
        }
    }

    function removeRow(row) {
        var group = row.closest('[data-hit-group]');

        if (row.parentNode) {
            row.parentNode.removeChild(row);
        }

        if (group) {
            renumber(group);
        }
    }

    /* ---------------------------------------------------------- anahtar önerme */

    function slugKey(value) {
        var base = HiAdmin.slugify ? HiAdmin.slugify(value) : String(value).toLowerCase();

        return base.replace(/-+/g, '_').replace(/^_+|_+$/g, '');
    }

    function bindKey(row) {
        var key = first(row, '[data-hit-key]', '[data-hit-row]');
        var label = first(row, '[data-hit-label]', '[data-hit-row]');

        if (!key || !label) {
            return;
        }

        // Dolu gelen anahtar kullanıcının kararıdır: üzerine yazılmaz.
        if (key.value !== '') {
            key.setAttribute('data-hit-touched', '1');
        }

        key.addEventListener('input', function () {
            key.setAttribute('data-hit-touched', '1');
        });

        label.addEventListener('input', function () {
            if (key.getAttribute('data-hit-touched') === '1') {
                return;
            }

            key.value = slugKey(label.value);
        });
    }

    /* ------------------------------------------------------------------ kurulum */

    /**
     * Görünümü tazeler: numaralar ve türe bağlı kutular. Dinleyici bağlamaz,
     * bu yüzden kaç kez çağrılırsa çağrılsın güvenli.
     */
    function refresh(scope, cfg) {
        each(scope, '[data-hit-row]', function (row) {
            applyType(row, cfg);
        });

        each(scope, '[data-hit-group]', function (group) {
            renumber(group);
        });
    }

    function mount(scope, cfg) {
        each(scope, '[data-hit-group]', function (group) {
            if (!HiAdmin.once(group, 'hit-group')) {
                return;
            }

            var list = first(group, '[data-hit-list]', '[data-hit-group]');

            if (list) {
                group.setAttribute('data-hit-seq', String(rows(list).length));
            }
        });

        each(scope, '[data-hit-row]', function (row) {
            if (!HiAdmin.once(row, 'hit-row')) {
                return;
            }

            var select = first(row, '[data-hit-type]', '[data-hit-row]');

            if (select) {
                select.addEventListener('change', function () {
                    applyType(row, cfg);
                });
            }

            bindKey(row);
        });

        each(scope, '[data-hit-add]', function (button) {
            if (!HiAdmin.once(button, 'hit-add')) {
                return;
            }

            button.addEventListener('click', function () {
                var group = button.closest('[data-hit-group]');

                if (group) {
                    addRow(group, cfg);
                }
            });
        });

        each(scope, '[data-hit-remove]', function (button) {
            if (!HiAdmin.once(button, 'hit-remove')) {
                return;
            }

            button.addEventListener('click', function () {
                var row = button.closest('[data-hit-row]');

                if (row) {
                    removeRow(row);
                }
            });
        });

        refresh(scope, cfg);
    }

    function present(root) {
        if (!root.querySelector) {
            return false;
        }

        return !!(root.querySelector('[data-hit-group]')
            || (root.matches && root.matches('[data-hit-group]')));
    }

    HiAdmin.onMount(function (scope) {
        var root = scope || document;

        if (!present(root)) {
            return; // bu ekranda alan düzenleyici yok
        }

        mount(root, config());
    });

    /*
     * onMount ilk kurulumu ANINDA çalıştırır; bu dosya belge ayrıştırılırken
     * yüklendiği için, veri düğümü betikten sonra basılmışsa o kurulum
     * yapılandırmayı göremez ve hiçbir kutu saklanmaz. Belge hazır olduğunda
     * görünüm bir kez tazelenir — dinleyici bağlanmadığı için ikinci bir
     * kurulum riski yok.
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            if (present(document)) {
                refresh(document, config());
            }
        });
    }
})();
