/* =========================================================================
   HiAdmin — panel davranışları
   -------------------------------------------------------------------------
   Bağımlılık yok. Davranışlar veri öznitelikleriyle bağlanır:
     data-toggle="scheme|sidebar"     data-menu / data-menu-trigger
     data-dismiss                     data-confirm="Emin misiniz?"
     data-modal="#id" / data-close    data-check-all="tablo-id"
     data-sortable                    data-slug-from / data-slug-to
     data-count-from / data-count-words / data-count-minutes
   ========================================================================= */

(function () {
    'use strict';

    const $ = (sel, scope) => (scope || document).querySelector(sel);
    const $$ = (sel, scope) => Array.from((scope || document).querySelectorAll(sel));

    /* --------------------------------------------------------------------
     * Renk şeması
     * ----------------------------------------------------------------- */

    const SCHEME_KEY = 'hicms-scheme';

    function applyScheme(scheme) {
        document.documentElement.setAttribute('data-scheme', scheme);

        try {
            localStorage.setItem(SCHEME_KEY, scheme);
        } catch (e) { /* gizli sekme */ }

        $$('[data-toggle="scheme"]').forEach((btn) => {
            const dark = scheme === 'dark';
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? 'Açık temaya geç' : 'Karanlık temaya geç');

            const sun = $('[data-ico="sun"]', btn);
            const moon = $('[data-ico="moon"]', btn);
            if (sun && moon) {
                sun.hidden = !dark;
                moon.hidden = dark;
            }
        });
    }

    function initScheme() {
        let stored = null;
        try { stored = localStorage.getItem(SCHEME_KEY); } catch (e) { /* yok */ }

        applyScheme(stored || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));

        $$('[data-toggle="scheme"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const now = document.documentElement.getAttribute('data-scheme');
                applyScheme(now === 'dark' ? 'light' : 'dark');
            });
        });
    }

    /* --------------------------------------------------------------------
     * Kenar çubuğu (mobil)
     * ----------------------------------------------------------------- */

    function initSidebar() {
        const scrim = $('[data-scrim]');

        function close() {
            document.body.classList.remove('nav-open');
            if (scrim) scrim.hidden = true;
        }

        $$('[data-toggle="sidebar"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const open = document.body.classList.toggle('nav-open');
                if (scrim) scrim.hidden = !open;
            });
        });

        if (scrim) scrim.addEventListener('click', close);

        $$('.side-nav a').forEach((a) => a.addEventListener('click', close));
    }

    /* --------------------------------------------------------------------
     * Açılır menüler
     * ----------------------------------------------------------------- */

    function closeMenus(except) {
        $$('[data-menu]').forEach((menu) => {
            if (menu === except) return;
            const pop = $('[data-menu-pop]', menu);
            const trigger = $('[data-menu-trigger]', menu);
            if (pop) pop.hidden = true;
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    function initMenus() {
        $$('[data-menu]').forEach((menu) => {
            const trigger = $('[data-menu-trigger]', menu);
            const pop = $('[data-menu-pop]', menu);
            if (!trigger || !pop) return;

            trigger.addEventListener('click', (event) => {
                event.stopPropagation();
                const willOpen = pop.hidden;
                closeMenus(menu);
                pop.hidden = !willOpen;
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        document.addEventListener('click', () => closeMenus());
    }

    /* --------------------------------------------------------------------
     * Bildirim kapatma ve onay
     * ----------------------------------------------------------------- */

    function initDismiss() {
        document.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-dismiss]');
            if (!btn) return;
            const box = btn.closest('.notice, .toast');
            if (box) box.remove();
        });
    }

    function initConfirm() {
        document.addEventListener('click', (event) => {
            const el = event.target.closest('[data-confirm]');
            if (!el) return;

            if (!confirm(el.getAttribute('data-confirm'))) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    }

    /* --------------------------------------------------------------------
     * Kalıcı pencereler
     * ----------------------------------------------------------------- */

    function openModal(modal) {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const focusable = $('input:not([type=hidden]), select, textarea, button', modal);
        if (focusable) focusable.focus();
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    function initModals() {
        $$('[data-modal]').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                openModal($(btn.getAttribute('data-modal')));
            });
        });

        $$('[data-close]').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                closeModal(btn.closest('.modal'));
            });
        });

        $$('.modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal);
            });
        });
    }

    /* --------------------------------------------------------------------
     * Tablo seçimi
     * ----------------------------------------------------------------- */

    function initCheckAll() {
        $$('[data-check-all]').forEach((master) => {
            const table = document.getElementById(master.getAttribute('data-check-all'));
            if (!table) return;

            const boxes = () => $$('tbody input[type="checkbox"]', table);
            const bulk = $('[data-bulk="' + table.id + '"]');

            function sync() {
                const all = boxes();
                const picked = all.filter((b) => b.checked);
                master.checked = picked.length > 0 && picked.length === all.length;
                master.indeterminate = picked.length > 0 && picked.length < all.length;

                if (bulk) {
                    const counter = $('[data-bulk-count]', bulk);
                    if (counter) counter.textContent = String(picked.length);
                    $$('[data-bulk-action]', bulk).forEach((el) => {
                        el.classList.toggle('is-off', picked.length === 0);
                    });
                }
            }

            master.addEventListener('change', () => {
                boxes().forEach((b) => { b.checked = master.checked; });
                sync();
            });

            boxes().forEach((b) => b.addEventListener('change', sync));
            sync();
        });
    }

    /* --------------------------------------------------------------------
     * Kısa ad ve kelime sayısı
     * ----------------------------------------------------------------- */

    const TR = { 'ç': 'c', 'ğ': 'g', 'ı': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u', 'İ': 'i' };

    function slugify(text) {
        return text.toLowerCase()
            .replace(/[çğıöşüİ]/g, (c) => TR[c] || c)
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function initSlug() {
        const source = $('[data-slug-from]');
        const target = $('[data-slug-to]');
        if (!source || !target) return;

        let manual = target.value.trim() !== '';

        target.addEventListener('input', () => { manual = true; });

        source.addEventListener('input', () => {
            if (manual) return;
            target.value = slugify(source.value);
        });
    }

    function initWordCount() {
        const output = $('[data-count-words]');
        if (!output) return;

        const minutes = $('[data-count-minutes]');

        function update() {
            let words = 0;

            $$('[data-count-from]').forEach((field) => {
                const text = field.value.replace(/<[^>]*>/g, ' ').trim();
                if (text) words += text.split(/\s+/).length;
            });

            output.textContent = String(words);
            if (minutes) minutes.textContent = String(Math.max(1, Math.ceil(words / 200)));
        }

        document.addEventListener('input', (event) => {
            if (event.target.matches('[data-count-from]')) update();
        });

        window.hiRecount = update;
        update();
    }

    /* --------------------------------------------------------------------
     * Sürükle-bırak sıralama
     * ----------------------------------------------------------------- */

    function initSortable() {
        $$('[data-sortable]').forEach((list) => {
            let dragged = null;

            list.addEventListener('dragstart', (event) => {
                const item = event.target.closest('[draggable="true"]');
                if (!item || !list.contains(item)) return;
                dragged = item;
                item.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                // Firefox sürüklemeyi başlatmak için veri ister.
                event.dataTransfer.setData('text/plain', '');
            });

            list.addEventListener('dragend', () => {
                if (dragged) dragged.classList.remove('is-dragging');
                $$('.is-over', list).forEach((el) => el.classList.remove('is-over'));
                dragged = null;
                list.dispatchEvent(new CustomEvent('sorted'));
            });

            list.addEventListener('dragover', (event) => {
                if (!dragged) return;
                event.preventDefault();

                const over = event.target.closest('[draggable="true"]');
                if (!over || over === dragged || !list.contains(over)) return;

                $$('.is-over', list).forEach((el) => el.classList.remove('is-over'));
                over.classList.add('is-over');

                const box = over.getBoundingClientRect();
                const after = (event.clientY - box.top) / box.height > 0.5;
                over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
            });

            list.addEventListener('drop', (event) => event.preventDefault());
        });
    }

    /* --------------------------------------------------------------------
     * Dosya bırakma alanı
     * ----------------------------------------------------------------- */

    function initDrop() {
        $$('.drop').forEach((zone) => {
            const input = $('input[type="file"]', zone);

            zone.addEventListener('click', () => { if (input) input.click(); });

            ['dragenter', 'dragover'].forEach((name) => {
                zone.addEventListener(name, (event) => {
                    event.preventDefault();
                    zone.classList.add('is-over');
                });
            });

            ['dragleave', 'drop'].forEach((name) => {
                zone.addEventListener(name, (event) => {
                    event.preventDefault();
                    zone.classList.remove('is-over');
                });
            });

            zone.addEventListener('drop', (event) => {
                if (!input || !event.dataTransfer.files.length) return;
                input.files = event.dataTransfer.files;
                const form = zone.closest('form');
                if (form) form.submit();
            });

            if (input) {
                input.addEventListener('change', () => {
                    const form = zone.closest('form');
                    if (form && input.files.length) form.submit();
                });
            }
        });
    }

    /* --------------------------------------------------------------------
     * Bildirim balonu
     * ----------------------------------------------------------------- */

    function toast(message) {
        let holder = $('.toasts');

        if (!holder) {
            holder = document.createElement('div');
            holder.className = 'toasts';
            holder.setAttribute('role', 'status');
            holder.setAttribute('aria-live', 'polite');
            document.body.appendChild(holder);
        }

        const el = document.createElement('div');
        el.className = 'toast';
        el.textContent = message;
        holder.appendChild(el);

        setTimeout(() => {
            el.classList.add('is-out');
            setTimeout(() => el.remove(), 200);
        }, 3200);
    }

    /* --------------------------------------------------------------------
     * Klavye
     * ----------------------------------------------------------------- */

    function initKeys() {
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenus();
                const modal = $('.modal:not([hidden])');
                if (modal) closeModal(modal);
                document.body.classList.remove('nav-open');
                const scrim = $('[data-scrim]');
                if (scrim) scrim.hidden = true;
                return;
            }

            const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);

            if (event.key === '/' && !typing) {
                const search = $('.bar-search input');
                if (search) {
                    event.preventDefault();
                    search.focus();
                }
            }

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                const save = $('[data-primary-save]');
                if (save) {
                    event.preventDefault();
                    save.click();
                }
            }
        });
    }

    /* --------------------------------------------------------------------
     * Değişiklik uyarısı
     * ----------------------------------------------------------------- */

    function initDirtyGuard() {
        const form = $('[data-guard]');
        if (!form) return;

        let dirty = false;

        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });

        window.addEventListener('beforeunload', (event) => {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }

    /* --------------------------------------------------------------------
     * Başlat
     * ----------------------------------------------------------------- */

    function init() {
        initScheme();
        initSidebar();
        initMenus();
        initDismiss();
        initConfirm();
        initModals();
        initCheckAll();
        initSlug();
        initWordCount();
        initSortable();
        initDrop();
        initKeys();
        initDirtyGuard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.HiAdmin = { toast, slugify, openModal, closeModal, initSortable, initCheckAll };
})();
