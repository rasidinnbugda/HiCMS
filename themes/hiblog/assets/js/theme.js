/* =========================================================================
   HiBlog — tema betiği
   -------------------------------------------------------------------------
   Üç iş yapar: açık/karanlık geçişi, mobil gezinti, yorum yanıtlama.
   Bağımlılık yoktur; JavaScript kapalıyken tema tümüyle kullanılabilir kalır.
   ========================================================================= */

(function () {
    'use strict';

    var KEY = 'hiblog-scheme';

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function $$(sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); }

    /* --------------------------------------------------------------------
     * Renk şeması
     * ----------------------------------------------------------------- */

    function apply(scheme) {
        document.documentElement.setAttribute('data-scheme', scheme);

        try { localStorage.setItem(KEY, scheme); } catch (e) { /* gizli sekme */ }

        $$('[data-scheme-toggle]').forEach(function (btn) {
            var dark = scheme === 'dark';
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? 'Açık temaya geç' : 'Karanlık temaya geç');

            var sun = $('[data-icon="sun"]', btn);
            var moon = $('[data-icon="moon"]', btn);
            if (sun && moon) { sun.hidden = !dark; moon.hidden = dark; }
        });
    }

    function initScheme() {
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) { stored = null; }

        var meta = $('meta[name="hiblog-default-scheme"]');
        var fallback = meta ? meta.getAttribute('content') : null;

        if (fallback !== 'light' && fallback !== 'dark') {
            fallback = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        apply(stored || fallback);

        $$('[data-scheme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                apply(document.documentElement.getAttribute('data-scheme') === 'dark' ? 'light' : 'dark');
            });
        });
    }

    /* --------------------------------------------------------------------
     * Mobil gezinti
     * ----------------------------------------------------------------- */

    function initNav() {
        var toggle = $('[data-nav-toggle]');
        if (!toggle) return;

        function setState(open) {
            document.body.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');

            var openIcon = $('[data-icon="open"]', toggle);
            var closeIcon = $('[data-icon="close"]', toggle);
            if (openIcon && closeIcon) { openIcon.hidden = open; closeIcon.hidden = !open; }
        }

        toggle.addEventListener('click', function () {
            setState(!document.body.classList.contains('nav-open'));
        });

        $$('.site-nav a').forEach(function (link) {
            link.addEventListener('click', function () { setState(false); });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && document.body.classList.contains('nav-open')) {
                setState(false);
                toggle.focus();
            }
        });
    }

    /* --------------------------------------------------------------------
     * Yorum yanıtlama
     * ----------------------------------------------------------------- */

    function initReply() {
        var parent = $('[data-comment-parent]');
        var form = $('#yorum-formu');
        var label = $('[data-replying-to]');
        var cancel = $('[data-cancel-reply]');

        $$('[data-reply-to]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();

                if (parent) parent.value = link.getAttribute('data-reply-to');

                if (label) {
                    label.hidden = false;
                    label.textContent = link.getAttribute('data-reply-name') + ' adlı kişiye yanıt veriyorsunuz.';
                }

                if (cancel) cancel.hidden = false;

                if (form) {
                    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    var area = $('textarea', form);
                    if (area) area.focus({ preventScroll: true });
                }
            });
        });

        if (cancel) {
            cancel.addEventListener('click', function (event) {
                event.preventDefault();
                if (parent) parent.value = '0';
                if (label) label.hidden = true;
                cancel.hidden = true;
            });
        }
    }

    function init() {
        initScheme();
        initNav();
        initReply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
