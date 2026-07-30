/* =========================================================================
   HiAdmin — komut paleti
   -------------------------------------------------------------------------
   Panelin İMZA ÖĞESİ ve klavye önceliğinin merkezi.

   Tasarım kararı: bu gizli bir ⌘K penceresi DEĞİL. Komut şeridindeki arama
   alanı sürekli görünür ve yazmaya başlamak paleti açar. Keşfedilmeyen bir
   kısayol kısayol değildir; kullanıcı önce alanı görür, sonra kısayolu öğrenir.

   Komut kaynakları:
     1. Gezinti — kenar çubuğundaki bağlantılar. Ayrı bir kayıt tutulmuyor;
        DOM'daki menü tek gerçek kaynak, dolayısıyla eklentilerin eklediği
        sayfalar da otomatik olarak palete giriyor.
     2. Sayfa eylemleri — .page-actions ve .panel-actions içindeki düğmeler.
     3. Eklenti komutları — HiPalette.register().
     4. İçerik arama — sunucuya gitmeden yapılamaz; Enter'a basıldığında
        liste sayfasına arama parametresiyle gidilir.

   JS KAPALIYKEN: arama alanı normal bir GET formu olarak çalışır ve
   content.php'ye gider. Palet bir kolaylık katmanı, tek yol değil.
   ========================================================================= */

(function () {
    'use strict';

    const MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');

    /** Eklentilerin kaydettiği komutlar. */
    const extra = [];

    let box = null;
    let input = null;
    let list = null;
    let items = [];
    let cursor = 0;
    let open = false;
    let restoreFocus = null;

    /* ------------------------------------------------------------ toplama */

    function collect() {
        const found = [];

        // 1. Gezinti
        document.querySelectorAll('.side-nav a[href]').forEach(function (link) {
            const label = (link.querySelector('span') || link).textContent.trim();

            if (!label) return;

            found.push({
                group: 'Gezinti',
                label: label,
                hint: link.getAttribute('aria-current') ? 'şu an burada' : '',
                run: function () { navigate(link.href); },
            });
        });

        // 2. Bu sayfadaki eylemler
        document.querySelectorAll('.page-actions a[href], .page-actions button').forEach(function (el) {
            const label = el.textContent.trim();

            if (!label) return;

            found.push({
                group: 'Bu sayfa',
                label: label,
                run: function () {
                    if (el.tagName === 'A') navigate(el.href);
                    else el.click();
                },
            });
        });

        // 3. Sekmeler (ayarlar ve sistem bölümleri)
        document.querySelectorAll('.tabs a[href]').forEach(function (link) {
            const label = link.textContent.trim();

            if (!label) return;

            found.push({
                group: 'Bölümler',
                label: label,
                run: function () { navigate(link.href); },
            });
        });

        // 4. Eklenti komutları
        extra.forEach(function (command) {
            found.push({
                group: command.group || 'Komut',
                label: command.label,
                hint: command.hint || '',
                run: command.run,
            });
        });

        // 5. Tema
        found.push({
            group: 'Komut',
            label: document.documentElement.getAttribute('data-scheme') === 'dark'
                ? 'Açık temaya geç'
                : 'Karanlık temaya geç',
            run: function () {
                const toggle = document.querySelector('[data-toggle="scheme"]');
                if (toggle) toggle.click();
            },
        });

        return found;
    }

    function navigate(url) {
        if (window.HiNav && window.HiNav.handled(url, null)) {
            window.HiNav.go(url, { push: true });

            return;
        }

        window.location.href = url;
    }

    /* --------------------------------------------------------------- süzme */

    /**
     * Sıralı harf eşleşmesi (fuzzy). "ayrl" → "Ayarlar".
     * Puan: baştan eşleşme ve bitişik harfler daha değerli.
     */
    function score(label, query) {
        if (query === '') return 1;

        const text = label.toLocaleLowerCase('tr');
        const term = query.toLocaleLowerCase('tr');

        let at = 0;
        let points = 0;
        let streak = 0;

        for (let i = 0; i < term.length; i++) {
            const found = text.indexOf(term.charAt(i), at);

            if (found === -1) return 0;

            streak = found === at ? streak + 1 : 0;
            points += found === 0 ? 5 : 1 + streak;
            at = found + 1;
        }

        return points;
    }

    function filter(query) {
        return collect()
            .map(function (command) { return { command: command, points: score(command.label, query) }; })
            .filter(function (row) { return row.points > 0; })
            .sort(function (a, b) { return b.points - a.points; })
            .map(function (row) { return row.command; });
    }

    /* --------------------------------------------------------------- çizim */

    function build() {
        if (box) return;

        box = document.createElement('div');
        box.className = 'pal';
        box.hidden = true;
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', 'Komut paleti');

        box.innerHTML =
            '<div class="pal-box">'
            + '<div class="pal-in">'
            + '<span class="pal-sign" aria-hidden="true">' + (MAC ? '⌘' : '›') + '</span>'
            + '<input type="text" autocomplete="off" spellcheck="false" '
            + 'placeholder="Komut, sayfa ya da içerik ara" aria-label="Komut ara">'
            + '</div>'
            + '<div class="pal-list" role="listbox"></div>'
            + '<div class="pal-foot">'
            + '<span><b>↑↓</b> gez</span><span><b>↵</b> aç</span><span><b>esc</b> kapat</span>'
            + '</div>'
            + '</div>';

        document.body.appendChild(box);

        input = box.querySelector('input');
        list = box.querySelector('.pal-list');

        box.addEventListener('click', function (event) {
            if (event.target === box) hide();
        });

        input.addEventListener('input', function () { draw(input.value); });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                move(-1);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                pick();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                hide();
            }
        });

        list.addEventListener('mousedown', function (event) {
            const row = event.target.closest('[data-index]');

            if (!row) return;

            event.preventDefault();
            cursor = Number(row.dataset.index);
            pick();
        });
    }

    function draw(query) {
        items = filter(query);
        cursor = 0;
        list.innerHTML = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'pal-empty';
            empty.textContent = query.trim() === ''
                ? 'Komut yok.'
                : '"' + query + '" için komut yok — İçerikte aramak için Enter.';
            list.appendChild(empty);

            return;
        }

        let group = '';

        items.forEach(function (command, index) {
            if (command.group !== group) {
                group = command.group;

                const head = document.createElement('div');
                head.className = 'pal-group';
                head.textContent = group;
                list.appendChild(head);
            }

            const row = document.createElement('div');
            row.className = 'pal-row';
            row.dataset.index = String(index);
            row.setAttribute('role', 'option');
            row.textContent = command.label;

            if (command.hint) {
                const hint = document.createElement('span');
                hint.className = 'pal-hint';
                hint.textContent = command.hint;
                row.appendChild(hint);
            }

            list.appendChild(row);
        });

        highlight();
    }

    function highlight() {
        list.querySelectorAll('.pal-row').forEach(function (row) {
            const on = Number(row.dataset.index) === cursor;
            row.setAttribute('aria-selected', on ? 'true' : 'false');

            if (on) row.scrollIntoView({ block: 'nearest' });
        });
    }

    function move(delta) {
        if (!items.length) return;

        cursor = (cursor + delta + items.length) % items.length;
        highlight();
    }

    function pick() {
        const command = items[cursor];

        if (!command) {
            /*
             * Eşleşen komut yok: yazılanı içerik aramasına çevir. Palet böylece
             * "bulamadım" demek yerine bir sonraki makul adımı atıyor.
             */
            const query = input.value.trim();

            hide();

            if (query !== '') {
                navigate('content.php?ara=' + encodeURIComponent(query));
            }

            return;
        }

        hide();
        command.run();
    }

    function show(seed) {
        build();

        restoreFocus = document.activeElement;
        open = true;
        box.hidden = false;
        input.value = seed || '';
        draw(input.value);
        input.focus();
        input.select();
    }

    function hide() {
        if (!box || !open) return;

        open = false;
        box.hidden = true;

        // Odak nereden geldiyse oraya döner: klavye kullanıcısı kaybolmaz.
        if (restoreFocus && document.contains(restoreFocus)) {
            restoreFocus.focus();
        }

        restoreFocus = null;
    }

    /* ------------------------------------------------------------- bağlama */

    document.addEventListener('keydown', function (event) {
        const meta = MAC ? event.metaKey : event.ctrlKey;

        if (meta && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            open ? hide() : show('');

            return;
        }

        if (event.key === 'Escape' && open) {
            event.preventDefault();
            hide();
        }
    });

    /*
     * Komut şeridindeki alan paleti açar ama FORM OLARAK DA çalışır: JS
     * kapalıyken normal bir GET gönderimi. Burada yalnızca odaklanınca paleti
     * açıyoruz; kullanıcı yazdığını kaybetmesin diye yazılan metin aktarılıyor.
     */
    document.addEventListener('focusin', function (event) {
        const field = event.target.closest('.bar-search input');

        if (!field || open) return;

        show(field.value);
        field.blur();
    });

    window.HiPalette = {
        show: show,
        hide: hide,
        /**
         * Eklenti komutu kaydeder.
         * @param {{label: string, run: function, group?: string, hint?: string}} command
         */
        register: function (command) {
            if (!command || typeof command.run !== 'function' || !command.label) return;

            extra.push(command);
        },
    };
})();
