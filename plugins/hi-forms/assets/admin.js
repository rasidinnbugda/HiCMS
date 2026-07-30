/* =========================================================================
   HiForms — panel form düzenleyici
   -------------------------------------------------------------------------
   KURULUM SÖZLEŞMESİ: her şey HiAdmin.onMount() içinde. Panel bölge
   değişimiyle geziniyor (admin/assets/js/nav.js); kurulum bir kez yapılırsa
   düzenleyiciye ikinci kez girildiğinde ölü bir ekran kalır. Aynı DOM'a ikinci
   dinleyici bağlamamak için HiAdmin.once() kullanılır.

   VERİ: hi_admin_data('hi-forms', …) ile basılan JSON öğesinden okunur.
   Satır içi <script>window.X = …</script> ÇALIŞMAZ — bölge değişiminde gelen
   betik etiketleri çalıştırılmıyor.

   Betiksiz de düzenleme yapılabilir: her satırın bir "sıra" sayısı var, üç boş
   satır her zaman basılıyor ve seçenek alanı görünür durur.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.HiAdmin) {
        return;
    }

    var list = function (nodes) {
        return Array.prototype.slice.call(nodes);
    };

    var data = HiAdmin.data('hi-forms') || {};
    var choiceTypes = data.choiceTypes || [];
    var counter = data.nextIndex || 50;

    /** Seçenek satırı yalnızca liste isteyen türlerde görünür. */
    function syncChoices(row) {
        if (!row) {
            return;
        }

        var type = row.querySelector('[data-hf-type]');
        var choices = row.querySelector('[data-hf-choices]');

        if (!type || !choices) {
            return;
        }

        choices.hidden = choiceTypes.indexOf(type.value) === -1;
    }

    /** Sürükleyerek taşımadan sonra sıra sayılarını yeniden yazar. */
    function renumber(container) {
        list(container.querySelectorAll('[data-hf-row]')).forEach(function (row, index) {
            var order = row.querySelector('[data-hf-order]');

            if (order) {
                order.value = index;
            }
        });
    }

    /** O anda yazılı olan alan anahtarları. */
    function keysOf(container) {
        var keys = [];

        list(container.querySelectorAll('[data-hf-key]')).forEach(function (input) {
            var key = (input.value || '').trim();

            if (key && keys.indexOf(key) === -1) {
                keys.push(key);
            }
        });

        return keys;
    }

    /**
     * Anahtar listesinden beslenen açılır listeleri yeniler.
     *
     * Sunucu bu listeleri KAYITLI anahtarlarla basıyor; yeni eklenen bir alan
     * kaydedilmeden koşul kuramazdı. İlk seçenek ("her zaman" / "ilk e-posta
     * alanı") korunur.
     */
    function refill(select, keys, exclude) {
        var current = select.value;
        var blank = select.options.length ? select.options[0] : null;
        var blankText = blank ? blank.textContent : '';

        select.innerHTML = '';
        select.appendChild(new Option(blankText, ''));

        keys.forEach(function (key) {
            if (key !== exclude) {
                select.appendChild(new Option(key, key));
            }
        });

        select.value = current;
    }

    function syncSelects(form, container) {
        var keys = keysOf(container);

        list(container.querySelectorAll('[data-hf-row]')).forEach(function (row) {
            var select = row.querySelector('select[name$="[cond_field]"]');
            var own = row.querySelector('[data-hf-key]');

            if (select) {
                refill(select, keys, own ? (own.value || '').trim() : '');
            }
        });

        var reply = form.querySelector('select[name="yanit_alani"]');

        if (reply) {
            refill(reply, keys, '');
        }
    }

    /** Yeni alan satırı: son satır kopyalanıp boşaltılır. */
    function addRow(container) {
        var rows = list(container.querySelectorAll('[data-hf-row]'));

        if (!rows.length) {
            return;
        }

        var clone = rows[rows.length - 1].cloneNode(true);
        var index = counter++;

        clone.setAttribute('data-hf-index', String(index));

        list(clone.querySelectorAll('[name]')).forEach(function (control) {
            control.name = control.name.replace(/^alan\[\d+\]/, 'alan[' + index + ']');

            if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = false;
            } else if (control.tagName === 'SELECT') {
                control.selectedIndex = 0;
            } else {
                control.value = '';
            }
        });

        list(clone.querySelectorAll('[id]')).forEach(function (control) {
            var previous = control.id;
            var label = clone.querySelector('label[for="' + previous + '"]');

            control.id = previous.replace(/-\d+-/, '-' + index + '-');

            if (label) {
                label.setAttribute('for', control.id);
            }
        });

        var order = clone.querySelector('[data-hf-order]');

        if (order) {
            order.value = index;
        }

        /*
         * Yeni satır BOŞ SATIRLARIN ÖNÜNE girer. Sona eklenirse üç boş satırın
         * arkasında kalıyor ve kullanıcı yazdığı alanı listenin dibinde arıyor.
         */
        var blank = null;

        rows.forEach(function (row) {
            var key = row.querySelector('[data-hf-key]');

            if (!blank && key && (key.value || '').trim() === '') {
                blank = row;
            }
        });

        if (blank) {
            container.insertBefore(clone, blank);
        } else {
            container.appendChild(clone);
        }

        renumber(container);
        syncChoices(clone);

        var key = clone.querySelector('[data-hf-key]');

        if (key) {
            key.focus();
        }
    }

    /**
     * Sürükleme yalnızca TUTAMAKTAN başlar.
     *
     * Satırın kendisi draggable kalırsa metin alanında seçim yapmak sürüklemeyi
     * tetikliyor ve satır elden kaçıyor. Çekirdeğin sıralayıcısı öğenin
     * draggable olmasını istediği için işaret tutamağa basıldığında konuyor.
     */
    function handleOnly(row) {
        var grip = row.querySelector('.node-grip');

        row.setAttribute('draggable', 'false');

        if (!grip) {
            return;
        }

        grip.style.cursor = 'grab';

        grip.addEventListener('mousedown', function () {
            row.setAttribute('draggable', 'true');
        });

        row.addEventListener('dragend', function () {
            row.setAttribute('draggable', 'false');
        });
    }

    HiAdmin.onMount(function (scope) {
        var root = scope && scope.querySelectorAll ? scope : document;

        list(root.querySelectorAll('[data-hf-rows]')).forEach(function (container) {
            var form = container.closest('[data-hf-editor]') || document;

            if (HiAdmin.once(container, 'hf-rows')) {
                container.addEventListener('change', function (event) {
                    if (event.target.hasAttribute('data-hf-type')) {
                        syncChoices(event.target.closest('[data-hf-row]'));
                    }
                });

                container.addEventListener('input', function (event) {
                    if (event.target.hasAttribute('data-hf-key')) {
                        syncSelects(form, container);
                    }
                });

                // Çekirdeğin sıralayıcısı taşıma bitince bu olayı yayıyor.
                container.addEventListener('sorted', function () {
                    renumber(container);
                });
            }

            list(container.querySelectorAll('[data-hf-row]')).forEach(function (row) {
                syncChoices(row);

                if (HiAdmin.once(row, 'hf-row')) {
                    handleOnly(row);
                }
            });

            var add = form.querySelector ? form.querySelector('[data-hf-add]') : null;

            if (add && HiAdmin.once(add, 'hf-add')) {
                add.addEventListener('click', function () {
                    addRow(container);

                    list(container.querySelectorAll('[data-hf-row]')).forEach(function (row) {
                        if (HiAdmin.once(row, 'hf-row')) {
                            handleOnly(row);
                        }
                    });
                });
            }
        });
    });
})();
