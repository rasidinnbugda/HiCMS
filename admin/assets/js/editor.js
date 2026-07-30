/* =========================================================================
   HiAdmin — blok editörü
   -------------------------------------------------------------------------
   İçerik HTML yığını değil, sıralı blok dizisi olarak düzenlenir. Blok
   türlerinin alan tanımı PHP'den gelir (window.HI_EDITOR), form da bu
   tanımdan üretilir — yeni blok türü eklemek için burada kod yazmak gerekmez.

   Beklenen veri:
     window.HI_EDITOR = {
       types:  { paragraph: { label, icon, group, fields: [...] }, ... },
       blocks: [ { type: 'paragraph', data: {...} }, ... ],
       media:  { 12: { id, url, title, alt }, ... },
       icons:  { text: '<svg…>', ... }
     }
   ========================================================================= */

/*
 * KURULUM SÖZLEŞMESİ
 *
 * Editör bir kez değil, HER BÖLGE DEĞİŞİMİNDE kurulur. Anında sayfa geçişi
 * (nav.js) <main> içeriğini değiştiriyor; editör tek seferlik kurulsaydı
 * listeden editöre geçtiğinizde ölü bir form kalırdı.
 *
 * Veri de betikten değil `<script type="application/json">` öğesinden okunur:
 * bölge değişiminde gelen <script> etiketleri çalıştırılmaz, ama veri öğesi
 * DOM'a girdiği için okunabilir.
 */
(function () {
    'use strict';

    function boot() {
        const root = document.getElementById('block-editor');
        const store = document.getElementById('blocks-json');

        if (!root || !store) return;

        // Aynı DOM'a ikinci kez kurulmasın.
        if (window.HiAdmin && !window.HiAdmin.once(root, 'block-editor')) return;

        const holder = document.getElementById('hi-editor-data');
        let cfg = null;

        if (holder) {
            try {
                cfg = JSON.parse(holder.textContent || '{}');
            } catch (error) {
                if (window.console) console.error('Editör verisi okunamadı', error);
            }
        }

        if (!cfg) return;

        // Sayfadaki diğer betikler (öne çıkan görsel seçici) buradan okuyor.
        window.HI_EDITOR = cfg;

        setup(root, store, cfg);
    }

    function setup(root, store, cfg) {
    const types = cfg.types || {};
    const media = cfg.media || {};
    const icons = cfg.icons || {};

    let blocks = Array.isArray(cfg.blocks) ? cfg.blocks.slice() : [];

    /* --------------------------------------------------------------------
     * Yardımcılar
     * ----------------------------------------------------------------- */

    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    const icon = (name) => icons[name] || icons.block || '';

    function blankData(type) {
        const data = {};

        (types[type]?.fields || []).forEach((field) => {
            switch (field.type) {
                case 'media-list': data[field.key] = []; break;
                case 'number': data[field.key] = 0; break;
                case 'switch': data[field.key] = false; break;
                case 'media': data[field.key] = 0; break;
                case 'select': data[field.key] = field.default ?? Object.keys(field.options || {})[0] ?? ''; break;
                default: data[field.key] = field.default ?? '';
            }
        });

        return data;
    }

    function sync() {
        store.value = JSON.stringify(blocks);
        if (window.hiRecount) window.hiRecount();
    }

    /* --------------------------------------------------------------------
     * Alan üretimi
     * ----------------------------------------------------------------- */

    function buildField(field, data, onChange) {
        const wrap = el('div', 'field');
        const id = 'f-' + Math.random().toString(36).slice(2, 9);

        if (field.label) {
            const label = el('label', 'label', field.label);
            label.setAttribute('for', id);
            wrap.appendChild(label);
        }

        let control;

        switch (field.type) {
            /*
             * Zengin metin: contenteditable yüzey + balon araç çubuğu.
             *
             * 0.2.0'da bu tür de <textarea> olarak basılıyordu; kullanıcı kalın
             * yazmak için elle <strong> yazmak zorundaydı ve editörde ham etiket
             * görüyordu. Yüzey HiRichText'ten gelir (richtext.js).
             *
             * richtext.js yüklenmemişse — bir eklenti hata verdiyse ya da dosya
             * önbellekten gelmediyse — textarea'ya düşülür. Biçimlendirme
             * kaybolur ama İÇERİK DÜZENLENEBİLİR kalır; sessizce kullanılamaz
             * bir alan bırakmak en kötü sonuç olurdu.
             */
            case 'richtext': {
                if (window.HiRichText) {
                    control = el('div', 'rt-host');

                    const rich = window.HiRichText.attach(control, {
                        value: data[field.key] ?? '',
                        placeholder: field.placeholder || 'Yazmaya başlayın…',
                        onChange: (html) => {
                            data[field.key] = html;
                            onChange();
                        },
                    });

                    // Blok kaldırıldığında dinleyiciler sökülsün.
                    control._hiRich = rich;
                    break;
                }

                control = el('textarea', 'input');
                control.rows = field.rows || 4;
                control.value = data[field.key] ?? '';
                control.setAttribute('data-count-from', '');
                control.addEventListener('input', () => {
                    data[field.key] = control.value;
                    onChange();
                });
                break;
            }

            case 'textarea':
            case 'lines':
            case 'code': {
                control = el('textarea', 'input' + (field.type === 'code' ? ' mono' : ''));
                control.rows = field.rows || (field.type === 'code' ? 8 : 4);
                control.value = field.type === 'lines' && Array.isArray(data[field.key])
                    ? data[field.key].join('\n')
                    : (data[field.key] ?? '');
                control.setAttribute('data-count-from', '');
                control.addEventListener('input', () => {
                    data[field.key] = field.type === 'lines'
                        ? control.value.split('\n').map((l) => l.trim()).filter(Boolean)
                        : control.value;
                    onChange();
                });
                break;
            }

            case 'select': {
                control = el('select', 'input select');
                Object.entries(field.options || {}).forEach(([value, label]) => {
                    const option = el('option', null, label);
                    option.value = value;
                    if (String(data[field.key]) === value) option.selected = true;
                    control.appendChild(option);
                });
                control.addEventListener('change', () => {
                    data[field.key] = control.value;
                    onChange();
                });
                break;
            }

            case 'switch': {
                const label = el('label', 'switch');
                control = el('input');
                control.type = 'checkbox';
                control.checked = Boolean(data[field.key]);
                control.addEventListener('change', () => {
                    data[field.key] = control.checked;
                    onChange();
                });
                const body = el('span', 'switch-body');
                body.appendChild(el('strong', null, field.label || ''));
                label.append(control, body);
                // Anahtar kendi etiketini taşır; üstteki label gereksiz.
                wrap.innerHTML = '';
                wrap.appendChild(label);
                if (field.help) wrap.appendChild(el('p', 'hint', field.help));
                return wrap;
            }

            case 'media': {
                control = buildMediaPicker(field, data, onChange);
                wrap.appendChild(control);
                if (field.help) wrap.appendChild(el('p', 'hint', field.help));
                return wrap;
            }

            case 'media-list': {
                control = buildMediaList(field, data, onChange);
                wrap.appendChild(control);
                if (field.help) wrap.appendChild(el('p', 'hint', field.help));
                return wrap;
            }

            case 'number': {
                control = el('input', 'input');
                control.type = 'number';
                control.value = data[field.key] ?? 0;
                control.addEventListener('input', () => {
                    data[field.key] = Number(control.value) || 0;
                    onChange();
                });
                break;
            }

            default: {
                control = el('input', 'input');
                control.type = field.type === 'url' ? 'url' : 'text';
                control.value = data[field.key] ?? '';
                if (field.placeholder) control.placeholder = field.placeholder;
                control.setAttribute('data-count-from', '');
                control.addEventListener('input', () => {
                    data[field.key] = control.value;
                    onChange();
                });
            }
        }

        control.id = id;
        wrap.appendChild(control);

        if (field.help) wrap.appendChild(el('p', 'hint', field.help));

        return wrap;
    }

    /* --------------------------------------------------------------------
     * Medya seçici
     * ----------------------------------------------------------------- */

    function buildMediaPicker(field, data, onChange) {
        const button = el('button', 'thumb-pick');
        button.type = 'button';

        function paint() {
            button.innerHTML = '';
            const item = media[data[field.key]];

            if (item) {
                const img = el('img');
                img.src = item.url;
                img.alt = item.alt || '';
                button.appendChild(img);
            } else {
                button.insertAdjacentHTML('beforeend', icon('image'));
                button.appendChild(el('span', null, 'Görsel seç'));
            }
        }

        button.addEventListener('click', () => {
            pickMedia((item) => {
                data[field.key] = item.id;
                paint();
                onChange();
            });
        });

        paint();

        return button;
    }

    function buildMediaList(field, data, onChange) {
        const wrap = el('div', 'col');
        const strip = el('div', 'row row-wrap');

        function paint() {
            strip.innerHTML = '';

            (data[field.key] || []).forEach((id, index) => {
                const item = media[id];
                const chip = el('span', 'chip', item ? item.title : '#' + id);
                const remove = el('button', null);
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Kaldır');
                remove.insertAdjacentHTML('beforeend', icon('x'));
                remove.addEventListener('click', () => {
                    data[field.key].splice(index, 1);
                    paint();
                    onChange();
                });
                chip.appendChild(remove);
                strip.appendChild(chip);
            });
        }

        const add = el('button', 'btn btn-sm');
        add.type = 'button';
        add.textContent = 'Görsel ekle';
        add.addEventListener('click', () => {
            pickMedia((item) => {
                if (!Array.isArray(data[field.key])) data[field.key] = [];
                data[field.key].push(item.id);
                paint();
                onChange();
            });
        });

        paint();
        wrap.append(strip, add);

        return wrap;
    }

    /** Medya kalıcı penceresini açar ve seçimi geri verir. */
    function pickMedia(callback) {
        const modal = document.getElementById('media-modal');

        if (!modal) {
            const id = prompt('Medya kimliği (Medya sayfasından kopyalayın)');
            if (id) callback({ id: Number(id) });
            return;
        }

        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        function choose(event) {
            const item = event.target.closest('[data-media-id]');
            if (!item) return;

            const id = Number(item.getAttribute('data-media-id'));
            callback({ id });

            modal.hidden = true;
            document.body.style.overflow = '';
            modal.removeEventListener('click', choose);
        }

        modal.addEventListener('click', choose);
    }

    /* --------------------------------------------------------------------
     * Blok kartı
     * ----------------------------------------------------------------- */

    function buildBlock(block, index) {
        const definition = types[block.type] || { label: block.type, fields: [], icon: 'block' };

        const card = el('article', 'block');
        card.draggable = true;
        card.dataset.index = String(index);

        /*
         * Kabuk iki parçaya ayrıldı:
         *   .block-bar   → sol oluk: sürükleme tutamağı + tür simgesi
         *   .block-acts  → sağ üst:  taşı, çoğalt, daralt, kaldır
         *
         * 0.2.0'da bunların hepsi blok üstünde 41px'lik yatay bir şeritteydi ve
         * her blokta duruyordu. Şimdi ikisi de yalnızca imleç blokta ya da
         * içindeyken görünüyor; yazarken ekranda yalnızca metin var.
         */
        const bar = el('div', 'block-bar');

        const grip = el('span', 'grip');
        grip.setAttribute('aria-hidden', 'true');
        grip.title = 'Sürükleyerek taşı';
        grip.insertAdjacentHTML('beforeend', icon('drag'));

        const kind = el('span', 'block-kind');
        kind.title = definition.label;
        kind.insertAdjacentHTML('beforeend', icon(definition.icon || 'block'));
        kind.appendChild(el('span', null, definition.label));

        bar.append(grip, kind);

        const acts = el('div', 'block-acts');

        const collapse = iconButton('chevron-down', 'Daralt/genişlet');
        const duplicate = iconButton('grid', 'Çoğalt');
        const up = iconButton('chevron-left', 'Yukarı taşı');
        const down = iconButton('chevron-right', 'Aşağı taşı');
        const remove = iconButton('trash', 'Bloğu kaldır');

        acts.append(up, down, duplicate, collapse, remove);

        /*
         * Gövde iki katmana ayrılır:
         *
         *   BİRİNCİL alan  → doğrudan, ETİKETSİZ basılır.
         *   İkincil alanlar → istendiğinde açılan .block-more içinde.
         *
         * Neden: bir paragraf bloğunun tek önemli şeyi metnin kendisi. 0.2.0'da
         * metnin üstünde "Metin" etiketi, altında `lead` anahtarı duruyordu ve
         * blok 275px'e çıkıyordu. Etiket bilgi taşımıyor — bloğun türü zaten
         * sol oluktaki simgede yazıyor. İkincil alanlar da her zaman görünmek
         * zorunda değil; yazarken değil, ayarlarken gerekiyorlar.
         *
         * Birincil alan, tanımın İLK zengin metin ya da metin alanı sayılır.
         * Blok türü `primary` anahtarıyla bunu açıkça belirtebilir.
         */
        const body = el('div', 'block-body');
        const fields = definition.fields || [];

        /*
         * Birincil alan arayışı SIRALI: metin taşıyan ilk alan.
         *
         * `text` de aranmak zorunda — başlık bloğunun metni `richtext` değil
         * `text` türünde. Bu atlandığında başlık bloğunun TÜM alanları ikincil
         * sayılıp gizleniyor ve blok 2px'e çöküyordu.
         */
        let primaryKey = definition.primary || null;

        if (!primaryKey) {
            const order = ['richtext', 'text', 'textarea', 'code'];

            for (const type of order) {
                const candidate = fields.find((f) => f.type === type);

                if (candidate) {
                    primaryKey = candidate.key;
                    break;
                }
            }
        }

        /*
         * Metin alanı olmayan bloklar (ayıraç, galeri, gömme) için gizleme
         * YAPILMAZ: birincil alan yoksa geriye gizlenecek "ikincil" alan da
         * kalmaz, yoksa blok tamamen boş görünür.
         */
        const secondary = [];

        fields.forEach((field) => {
            if (primaryKey === null) {
                body.appendChild(buildField(field, block.data, sync));
                return;
            }

            if (field.key === primaryKey) {
                // Etiketi bastırmak için alanın kopyası kullanılır; tanım
                // paylaşıldığı için orijinali değiştirmek diğer blokları etkiler.
                body.appendChild(buildField({ ...field, label: '' }, block.data, sync));
                return;
            }

            secondary.push(field);
        });

        if (!fields.length) {
            body.appendChild(el('p', 'muted small', 'Bu bloğun ayarlanacak alanı yok.'));
        }

        let more = null;

        if (secondary.length) {
            more = el('div', 'block-more');
            more.hidden = true;

            secondary.forEach((field) => {
                more.appendChild(buildField(field, block.data, sync));
            });

            body.appendChild(more);
        }

        /*
         * Daralt düğmesi iki iş yapıyordu. Artık ayrıştı: ikincil alanı olan
         * blokta o alanları açıp kapatır (asıl ihtiyaç), olmayan blokta gövdeyi
         * daraltır.
         */
        collapse.title = more ? 'Blok ayarları' : 'Daralt/genişlet';
        collapse.setAttribute('aria-label', collapse.title);
        collapse.setAttribute('aria-expanded', 'false');

        collapse.addEventListener('click', () => {
            if (more) {
                more.hidden = !more.hidden;
                collapse.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
                return;
            }

            body.classList.toggle('is-collapsed');
        });

        remove.addEventListener('click', () => {
            if (!confirm('Bu bloğu kaldırmak istiyor musunuz?')) return;
            blocks.splice(Number(card.dataset.index), 1);
            render();
            sync();
        });

        duplicate.addEventListener('click', () => {
            const at = Number(card.dataset.index);
            blocks.splice(at + 1, 0, JSON.parse(JSON.stringify(blocks[at])));
            render();
            sync();
        });

        up.addEventListener('click', () => move(Number(card.dataset.index), -1));
        down.addEventListener('click', () => move(Number(card.dataset.index), 1));

        card.append(bar, acts, body);

        return card;
    }

    function iconButton(name, label) {
        const button = el('button', 'icon-btn');
        button.type = 'button';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.insertAdjacentHTML('beforeend', icon(name));
        return button;
    }

    function move(index, delta) {
        const target = index + delta;
        if (target < 0 || target >= blocks.length) return;

        const [item] = blocks.splice(index, 1);
        blocks.splice(target, 0, item);
        render();
        sync();
    }

    /* --------------------------------------------------------------------
     * Blok ekleme
     * ----------------------------------------------------------------- */

    function buildPicker() {
        const wrap = el('div', 'block');
        const bar = el('div', 'block-bar');
        bar.appendChild(el('span', 'block-kind', 'Blok ekle'));
        const close = iconButton('x', 'Kapat');
        bar.append(el('span', 'spacer'), close);

        const grid = el('div', 'block-picker');

        Object.entries(types).forEach(([type, definition]) => {
            const button = el('button', 'block-pick');
            button.type = 'button';
            button.insertAdjacentHTML('beforeend', icon(definition.icon || 'block'));
            button.appendChild(el('span', null, definition.label));

            button.addEventListener('click', () => {
                blocks.push({ type, data: blankData(type) });
                render();
                sync();
            });

            grid.appendChild(button);
        });

        wrap.append(bar, grid);
        close.addEventListener('click', () => { wrap.remove(); addButton().hidden = false; });

        return wrap;
    }

    let addBtn = null;

    function addButton() {
        if (addBtn) return addBtn;

        addBtn = el('button', 'block-add');
        addBtn.type = 'button';
        addBtn.insertAdjacentHTML('beforeend', icon('plus'));
        addBtn.appendChild(el('span', null, 'Blok ekle'));

        addBtn.addEventListener('click', () => {
            addBtn.hidden = true;
            list.appendChild(buildPicker());
        });

        return addBtn;
    }

    /* --------------------------------------------------------------------
     * Çizim
     * ----------------------------------------------------------------- */

    const list = el('div', 'blocks');
    list.setAttribute('data-sortable', '');
    root.appendChild(list);

    function render() {
        list.innerHTML = '';

        if (!blocks.length) {
            const hint = el('div', 'empty');
            hint.style.padding = '32px 20px';
            hint.appendChild(el('h3', null, 'İçerik boş'));
            hint.appendChild(el('p', null, 'Aşağıdan ilk bloğu ekleyerek yazmaya başlayın.'));
            list.appendChild(hint);
        }

        blocks.forEach((block, index) => list.appendChild(buildBlock(block, index)));

        const button = addButton();
        button.hidden = false;
        list.appendChild(button);
    }

    // Sürükle-bırak sonrası sırayı veriye yansıt.
    list.addEventListener('sorted', () => {
        const order = Array.from(list.querySelectorAll('.block[data-index]'))
            .map((card) => Number(card.dataset.index));

        blocks = order.map((index) => blocks[index]).filter(Boolean);
        render();
        sync();
    });

    render();
    sync();

    if (window.HiAdmin) window.HiAdmin.initSortable();
    }

    /*
     * Bölge değişiminde yeniden kurulur. HiAdmin.onMount kancayı hemen bir kez
     * de çağırdığı için ilk yükleme ayrıca ele alınmıyor.
     *
     * HiAdmin yoksa (admin.js yüklenmemişse) editör yine kurulur — tek başına
     * çalışabilmesi gerekiyor.
     */
    if (window.HiAdmin && window.HiAdmin.onMount) {
        window.HiAdmin.onMount(boot);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
