/* =========================================================================
   HiAdmin — satır içi zengin metin editörü
   -------------------------------------------------------------------------
   0.2.0'da `richtext` alanları düpedüz <textarea> olarak basılıyordu:
   kullanıcı kalın yazmak için elle <strong> yazmak zorundaydı ve editörde ham
   etiket görüyordu. Bu dosya o alanları contenteditable bir yüzeye çevirir.

   TASARIM KARARLARI

   1. Klasik betik, ESM DEĞİL.
      Panel varlıkları içerik damgasıyla sürümlenir (admin_asset()). Native ESM
      kullanılırsa yalnızca giriş noktası damgalanır; `import './x.js'`
      belirteçleri damgasız kalır ve güncellemeden sonra tarayıcı eski modülü
      önbellekten servis eder. Tek dosya + damga bu sorunu tamamen ortadan
      kaldırır.

   2. document.execCommand kullanılır — kullanımdan kaldırılmış olmasına rağmen.
      Yerine geçen bir standart YOK. Range/Selection ile elle biçimlendirme
      yazmak mümkün ama tarayıcının GERİ ALMA YIĞINI'nı bozuyor: kullanıcı
      Ctrl+Z yaptığında biçimlendirme geri alınmıyor ya da tüm blok siliniyor.
      execCommand'in desteklediği komutlar (bold, italic, strikeThrough,
      superscript, subscript, createLink) yerel geri almayı korur. Yalnızca
      execCommand'de karşılığı olmayan iki biçim — satır içi kod ve
      işaretleyici — elle sarılır.

   3. Sunucu son sözü söyler.
      Buradaki temizleme kolaylık içindir, güvenlik sınırı DEĞİL. Gerçek sınır
      src/Support/Html.php (Html::clean) ve o sınır sunucuda. İstemci
      temizleyicisi yalnızca kullanıcıya makul bir yapıştırma deneyimi verir.

   4. IME ve Türkçe klavye.
      Kompozisyon sürerken (ölü tuş, IME) senkronizasyon yapılmaz; aksi hâlde
      yarım karakter veriye yazılır ve imleç zıplar.

   API
     HiRichText.attach(host, { value, onChange, placeholder, onSlash })
       → { destroy, focus, getValue, setValue, isEmpty }
   ========================================================================= */

(function () {
    'use strict';

    /* ---------------------------------------------------------------- ayarlar */

    /**
     * İzin verilen satır içi etiketler ve normalleştirme hedefleri.
     * Sunucudaki allowlist ile aynı kümede tutulmalı (src/Support/Html.php).
     */
    const INLINE = {
        strong: 'strong', b: 'strong',
        em: 'em', i: 'em',
        s: 's', strike: 's', del: 's',
        code: 'code',
        mark: 'mark',
        sup: 'sup',
        sub: 'sub',
        a: 'a',
        br: 'br',
    };

    /** Etiket başına korunan öznitelikler. Geri kalan her şey düşer. */
    const KEEP_ATTR = {
        a: ['href', 'title', 'target', 'rel'],
    };

    const MAC = /Mac|iPhone|iPad/.test(navigator.platform || '');

    /* -------------------------------------------------------------- yardımcılar */

    function el(tag, className) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        return node;
    }

    /** Bir URL panelin kabul ettiği şemalardan mı? Sunucu da aynısını uygular. */
    function safeUrl(raw) {
        const value = String(raw || '').trim();

        if (value === '') return '';

        // Protokol-göreli adres (//evil.com) reddedilir: aynı site sanılıp
        // başka bir alan adına gidiyor.
        if (value.startsWith('//')) return '';

        // Aynı site göreli yolu ve çapa serbest.
        if (value.startsWith('/') || value.startsWith('#') || value.startsWith('?')) return value;

        return /^(https?:|mailto:|tel:)/i.test(value) ? value : '';
    }

    /**
     * Bir DOM ağacını izinli satır içi kümeye indirger.
     *
     * Yapıştırmada ve serileştirmede kullanılır. İzinsiz bir etiket SİLİNMEZ,
     * yerine çocukları geçer — böylece Word'den yapıştırılan
     * <span style="…">metin</span> içindeki metin kaybolmaz.
     */
    function reduce(node) {
        const out = document.createDocumentFragment();

        node.childNodes.forEach(function (child) {
            if (child.nodeType === Node.TEXT_NODE) {
                out.appendChild(document.createTextNode(child.nodeValue));
                return;
            }

            if (child.nodeType !== Node.ELEMENT_NODE) {
                return; // yorum, işlem talimatı: atılır
            }

            const tag = child.tagName.toLowerCase();

            // Betik ve stil: içeriğiyle birlikte tamamen atılır. Etiketi soyup
            // içeriği bırakmak <style> için CSS metnini sayfaya sızdırır.
            if (tag === 'script' || tag === 'style' || tag === 'noscript') {
                return;
            }

            const target = INLINE[tag];

            if (!target) {
                // Blok düzeyi etiketler (div, p, h2…) satır içi bağlamda
                // anlamsız: çocuklarını devral, kendisini at. Paragraf sınırını
                // korumak için araya boşluk konur.
                out.appendChild(reduce(child));

                if (/^(p|div|li|h[1-6]|blockquote|tr)$/.test(tag)) {
                    out.appendChild(document.createTextNode(' '));
                }

                return;
            }

            if (target === 'br') {
                out.appendChild(document.createElement('br'));
                return;
            }

            const clean = document.createElement(target);
            const keep = KEEP_ATTR[target] || [];

            keep.forEach(function (name) {
                if (!child.hasAttribute(name)) return;

                let value = child.getAttribute(name);

                if (name === 'href') {
                    value = safeUrl(value);
                    if (value === '') return;
                }

                clean.setAttribute(name, value);
            });

            if (target === 'a') {
                if (!clean.getAttribute('href')) {
                    // Hedefi olmayan bağlantı bağlantı değil: metni kalsın.
                    out.appendChild(reduce(child));
                    return;
                }

                if (clean.getAttribute('target') === '_blank') {
                    // Sekme hırsızlığına karşı; sunucu da bunu zorluyor.
                    clean.setAttribute('rel', 'noopener');
                } else {
                    clean.removeAttribute('target');
                    clean.removeAttribute('rel');
                }
            }

            clean.appendChild(reduce(child));
            out.appendChild(clean);
        });

        return out;
    }

    /** HTML dizesini izinli kümeye indirger. */
    function cleanHtml(html) {
        const holder = document.createElement('div');
        holder.innerHTML = String(html || '');

        const reduced = reduce(holder);
        const wrap = document.createElement('div');
        wrap.appendChild(reduced);

        return wrap.innerHTML
            // Boş biçim etiketleri yazarken kalır, veriye gitmesin.
            .replace(/<(strong|em|s|code|mark|sup|sub)>\s*<\/\1>/g, '')
            .replace(/ /g, ' ')
            .trim();
    }

    /* ------------------------------------------------- balon araç çubuğu (tek) */

    let bubble = null;
    let active = null; // o an odakta olan editör örneği

    const TOOLS = [
        { cmd: 'bold', label: 'B', title: 'Kalın', key: 'B', tag: 'strong', style: 'font-weight:700' },
        { cmd: 'italic', label: 'I', title: 'İtalik', key: 'I', tag: 'em', style: 'font-style:italic' },
        { cmd: 'strikeThrough', label: 'S', title: 'Üstü çizili', tag: 's', style: 'text-decoration:line-through' },
        { cmd: 'wrap:code', label: '‹›', title: 'Satır içi kod', key: 'E', tag: 'code', style: 'font-family:var(--mono);font-size:11px' },
        { sep: true },
        { cmd: 'link', label: 'Bağlantı', title: 'Bağlantı ekle (Ctrl+K)', key: 'K', tag: 'a' },
        { sep: true },
        { cmd: 'wrap:mark', label: '▧', title: 'İşaretle', tag: 'mark' },
        { cmd: 'superscript', label: 'x²', title: 'Üst simge', tag: 'sup' },
        { cmd: 'subscript', label: 'x₂', title: 'Alt simge', tag: 'sub' },
        { sep: true },
        { cmd: 'clear', label: '⌫', title: 'Biçimi temizle' },
    ];

    function buildBubble() {
        if (bubble) return bubble;

        bubble = el('div', 'rt-bubble');
        bubble.hidden = true;
        bubble.setAttribute('role', 'toolbar');
        bubble.setAttribute('aria-label', 'Metin biçimlendirme');

        TOOLS.forEach(function (tool) {
            if (tool.sep) {
                bubble.appendChild(el('span', 'rt-sep'));
                return;
            }

            const button = el('button');
            button.type = 'button';
            button.className = 'rt-btn';
            button.dataset.cmd = tool.cmd;
            button.title = tool.title + (tool.key ? '  ' + (MAC ? '⌘' : 'Ctrl+') + tool.key : '');
            button.setAttribute('aria-label', tool.title);
            button.textContent = tool.label;

            if (tool.style) button.setAttribute('style', tool.style);
            if (tool.tag) button.dataset.tag = tool.tag;

            // mousedown ile: click'ten önce çalışır, seçim kaybolmaz.
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                if (active) active.run(tool.cmd);
            });

            bubble.appendChild(button);
        });

        document.body.appendChild(bubble);

        return bubble;
    }

    function hideBubble() {
        if (bubble) bubble.hidden = true;
    }

    /** Balonu seçimin üstüne yerleştirir ve düğme durumlarını günceller. */
    function placeBubble(instance) {
        const selection = window.getSelection();

        if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
            hideBubble();
            return;
        }

        const range = selection.getRangeAt(0);

        if (!instance.surface.contains(range.commonAncestorContainer)) {
            hideBubble();
            return;
        }

        const box = range.getBoundingClientRect();

        if (box.width === 0 && box.height === 0) {
            hideBubble();
            return;
        }

        const bar = buildBubble();
        bar.hidden = false;

        // Durum: imleç hangi biçimlerin içinde?
        bar.querySelectorAll('.rt-btn[data-tag]').forEach(function (button) {
            const tag = button.dataset.tag;
            const inside = !!closestTag(range.startContainer, tag, instance.surface);
            button.setAttribute('aria-pressed', inside ? 'true' : 'false');
        });

        const width = bar.offsetWidth;
        const height = bar.offsetHeight;

        let left = box.left + window.scrollX + (box.width - width) / 2;
        let top = box.top + window.scrollY - height - 8;

        // Ekran dışına taşmasın.
        left = Math.max(8, Math.min(left, window.scrollX + document.documentElement.clientWidth - width - 8));

        // Üstte yer yoksa seçimin altına al.
        if (top < window.scrollY + 4) {
            top = box.bottom + window.scrollY + 8;
        }

        bar.style.left = Math.round(left) + 'px';
        bar.style.top = Math.round(top) + 'px';
    }

    function closestTag(node, tag, limit) {
        let current = node;

        while (current && current !== limit) {
            if (current.nodeType === Node.ELEMENT_NODE && current.tagName.toLowerCase() === tag) {
                return current;
            }
            current = current.parentNode;
        }

        return null;
    }

    /* ------------------------------------------------------------------ örnek */

    function attach(host, options) {
        const opts = options || {};

        const surface = el('div', 'rt');
        surface.contentEditable = 'true';
        surface.spellcheck = true;
        surface.setAttribute('role', 'textbox');
        surface.setAttribute('aria-multiline', 'false');

        if (opts.placeholder) {
            surface.dataset.placeholder = opts.placeholder;
        }

        surface.innerHTML = cleanHtml(opts.value || '');
        host.appendChild(surface);

        let composing = false;
        let lastValue = surface.innerHTML;

        const instance = {
            surface: surface,
            run: run,
            focus: function () { surface.focus(); },
            getValue: function () { return cleanHtml(surface.innerHTML); },
            setValue: function (html) {
                surface.innerHTML = cleanHtml(html || '');
                lastValue = surface.innerHTML;
            },
            isEmpty: function () { return surface.textContent.trim() === ''; },
            destroy: destroy,
        };

        function emit() {
            if (composing) return; // IME sürerken veri yazılmaz

            const value = instance.getValue();

            if (value === lastValue) return;

            lastValue = value;

            if (typeof opts.onChange === 'function') {
                opts.onChange(value);
            }
        }

        /* -------------------------------------------------------- komutlar */

        function run(cmd) {
            surface.focus();

            if (cmd === 'link') {
                applyLink();
            } else if (cmd === 'clear') {
                document.execCommand('removeFormat', false);
                unwrapAll('code');
                unwrapAll('mark');
            } else if (cmd.indexOf('wrap:') === 0) {
                toggleWrap(cmd.slice(5));
            } else {
                /*
                 * execCommand kullanımdan kaldırıldı ama yerine geçen bir
                 * standart yok ve elle Range işlemi yerel geri alma yığınını
                 * bozuyor. Desteklenen komutlarda bunu kullanmak, kullanıcının
                 * Ctrl+Z beklentisini karşılamanın tek yolu.
                 */
                document.execCommand(cmd, false);
            }

            normalizeSurface();
            emit();
            placeBubble(instance);
        }

        function applyLink() {
            const selection = window.getSelection();
            const existing = selection && selection.rangeCount
                ? closestTag(selection.getRangeAt(0).startContainer, 'a', surface)
                : null;

            if (existing) {
                // Var olan bağlantı: kaldır.
                const parent = existing.parentNode;
                while (existing.firstChild) parent.insertBefore(existing.firstChild, existing);
                parent.removeChild(existing);
                return;
            }

            if (!selection || selection.isCollapsed) {
                window.alert('Bağlantı eklemek için önce metni seçin.');
                return;
            }

            const raw = window.prompt('Bağlantı adresi', 'https://');

            if (raw === null) return;

            const url = safeUrl(raw);

            if (url === '') {
                window.alert('Bu adres kullanılamaz. http, https, mailto, tel ya da / ile başlayan bir adres girin.');
                return;
            }

            document.execCommand('createLink', false, url);

            // execCommand rel/target koymaz; dışa giden bağlantıyı işaretle.
            surface.querySelectorAll('a[href]').forEach(function (link) {
                const href = link.getAttribute('href') || '';

                if (/^https?:/i.test(href) && href.indexOf(window.location.host) === -1) {
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener');
                }
            });
        }

        /** code ve mark için: execCommand karşılığı yok, elle sarılır. */
        function toggleWrap(tag) {
            const selection = window.getSelection();

            if (!selection || selection.rangeCount === 0) return;

            const range = selection.getRangeAt(0);
            const inside = closestTag(range.startContainer, tag, surface);

            if (inside) {
                const parent = inside.parentNode;
                while (inside.firstChild) parent.insertBefore(inside.firstChild, inside);
                parent.removeChild(inside);
                return;
            }

            if (selection.isCollapsed) return;

            const wrapper = document.createElement(tag);

            try {
                wrapper.appendChild(range.extractContents());
                range.insertNode(wrapper);

                // Seçimi sarılan içerikte tut.
                selection.removeAllRanges();
                const after = document.createRange();
                after.selectNodeContents(wrapper);
                selection.addRange(after);
            } catch (error) {
                // Seçim birden çok blok sınırını kesiyorsa extractContents
                // patlar; kullanıcıyı hata mesajıyla boğmak yerine sessizce
                // vazgeçmek doğru — biçim uygulanmaz, veri bozulmaz.
            }
        }

        function unwrapAll(tag) {
            surface.querySelectorAll(tag).forEach(function (node) {
                const parent = node.parentNode;
                while (node.firstChild) parent.insertBefore(node.firstChild, node);
                parent.removeChild(node);
            });
        }

        /**
         * Tarayıcının ürettiği biçimi normalleştirir.
         *
         * execCommand tarayıcıya göre <b>/<strong>, <i>/<em> ve style
         * öznitelikli <span> üretebilir. Bunlar veriye gitmeden çevrilir,
         * yoksa aynı içerik iki farklı biçimde saklanır.
         */
        function normalizeSurface() {
            const html = surface.innerHTML;
            const cleaned = cleanHtml(html);

            if (cleaned === html) return;

            // İmleci korumak için yalnızca gerçekten değiştiyse yaz.
            const selection = window.getSelection();
            const offset = selection && selection.rangeCount ? caretOffset() : null;

            surface.innerHTML = cleaned;

            if (offset !== null) restoreCaret(offset);
        }

        /** İmlecin metin başından kaçıncı karakterde olduğunu bulur. */
        function caretOffset() {
            const selection = window.getSelection();

            if (!selection.rangeCount) return null;

            const range = selection.getRangeAt(0).cloneRange();
            range.setStart(surface, 0);

            return range.toString().length;
        }

        function restoreCaret(offset) {
            const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
            let seen = 0;
            let node;

            while ((node = walker.nextNode())) {
                const length = node.nodeValue.length;

                if (seen + length >= offset) {
                    const range = document.createRange();
                    range.setStart(node, Math.max(0, offset - seen));
                    range.collapse(true);

                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);

                    return;
                }

                seen += length;
            }
        }

        /* ------------------------------------------------------- olaylar */

        function onInput() {
            emit();
        }

        function onKeyDown(event) {
            const meta = MAC ? event.metaKey : event.ctrlKey;

            if (meta && !event.altKey) {
                const key = event.key.toLowerCase();

                if (key === 'b') { event.preventDefault(); run('bold'); return; }
                if (key === 'i') { event.preventDefault(); run('italic'); return; }
                if (key === 'k') { event.preventDefault(); run('link'); return; }
                if (key === 'e') { event.preventDefault(); run('wrap:code'); return; }
            }

            // Enter satır içi alanda yeni blok açar; bloğu editor.js yönetir.
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();

                if (typeof opts.onEnter === 'function') {
                    opts.onEnter();
                }

                return;
            }

            // Boş alanda Backspace bloğu birleştirir.
            if (event.key === 'Backspace' && instance.isEmpty() && typeof opts.onEmptyBackspace === 'function') {
                event.preventDefault();
                opts.onEmptyBackspace();
                return;
            }

            // Boş alanda / blok menüsünü açar.
            if (event.key === '/' && instance.isEmpty() && typeof opts.onSlash === 'function') {
                event.preventDefault();
                opts.onSlash();
            }
        }

        function onPaste(event) {
            event.preventDefault();

            const data = event.clipboardData;

            if (!data) return;

            const html = data.getData('text/html');
            const text = data.getData('text/plain');

            let fragment;

            if (html) {
                const holder = document.createElement('div');
                holder.innerHTML = html;
                fragment = reduce(holder);
            } else {
                fragment = document.createDocumentFragment();
                fragment.appendChild(document.createTextNode(text || ''));
            }

            const selection = window.getSelection();

            if (!selection || selection.rangeCount === 0) return;

            const range = selection.getRangeAt(0);
            range.deleteContents();

            const last = fragment.lastChild;
            range.insertNode(fragment);

            if (last) {
                range.setStartAfter(last);
                range.collapse(true);
                selection.removeAllRanges();
                selection.addRange(range);
            }

            normalizeSurface();
            emit();
        }

        function onFocus() {
            active = instance;
        }

        function onBlur() {
            // Balona tıklarken blur oluyor; balon kendi mousedown'ında
            // preventDefault yaptığı için odak korunur. Yine de gecikmeli
            // gizleme, dışarı tıklamada balonun kalmasını engeller.
            window.setTimeout(function () {
                if (active === instance && !surface.contains(document.activeElement)) {
                    hideBubble();
                }
            }, 120);

            emit();
        }

        function onSelect() {
            if (active === instance) placeBubble(instance);
        }

        surface.addEventListener('input', onInput);
        surface.addEventListener('keydown', onKeyDown);
        surface.addEventListener('paste', onPaste);
        surface.addEventListener('focus', onFocus);
        surface.addEventListener('blur', onBlur);
        surface.addEventListener('mouseup', onSelect);
        surface.addEventListener('keyup', onSelect);
        surface.addEventListener('compositionstart', function () { composing = true; });
        surface.addEventListener('compositionend', function () { composing = false; emit(); });

        function destroy() {
            surface.removeEventListener('input', onInput);
            surface.removeEventListener('keydown', onKeyDown);
            surface.removeEventListener('paste', onPaste);
            surface.removeEventListener('focus', onFocus);
            surface.removeEventListener('blur', onBlur);
            surface.removeEventListener('mouseup', onSelect);
            surface.removeEventListener('keyup', onSelect);

            if (active === instance) {
                active = null;
                hideBubble();
            }

            surface.remove();
        }

        return instance;
    }

    /* Seçim penceresi dışına çıkarsa balonu gizle. */
    document.addEventListener('selectionchange', function () {
        if (!active) return;

        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0) {
            hideBubble();
            return;
        }

        if (!active.surface.contains(selection.getRangeAt(0).commonAncestorContainer)) {
            hideBubble();
        }
    });

    window.addEventListener('scroll', function () {
        if (active) placeBubble(active);
    }, true);

    window.HiRichText = {
        attach: attach,
        clean: cleanHtml,
        safeUrl: safeUrl,
    };
})();
