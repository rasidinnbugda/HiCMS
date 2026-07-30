/* =========================================================================
   HiAdmin — anında gezinme ve kısmi güncelleme
   -------------------------------------------------------------------------
   Panel "ağır" hissettiriyordu: her tıklama tam sayfa yüklemesi, her kaydetme
   tam form gönderimi. Tarayıcı CSS'i yeniden ayrıştırıyor, çerçeveyi yeniden
   yerleştiriyor, kaydırma konumu ve odak kayboluyordu.

   MİMARİ KARARI — sunucuya kısmi render sözleşmesi EKLENMEDİ.

   İlk düşünce şuydu: `X-HiCMS-Partial` başlığı gönder, sunucu yalnızca istenen
   bölgeyi bassın. Ama envanter şunu gösterdi: admin_head() açık <main> ve açık
   div'lerle dönüyor, admin_foot() onları kapatıyor. Bu ikisini bölmek 18
   çekirdek sayfayı ve dört eklentinin screen() metodunu AYNI ANDA kırar.

   Bunun yerine sayfa normal biçimde istenir ve İSTEMCİDE ayrıştırılıp yalnızca
   değişen bölge değiştirilir. Sunucu tarafında sıfır değişiklik demek:
   eklentilerin panel sayfaları hiçbir şey yapmadan bu kazancı alıyor.

   Sunucu render maliyeti aynı kalıyor; kazanç tarayıcı tarafında: CSS ve JS
   yeniden ayrıştırılmıyor, çerçeve yeniden yerleşmiyor, kaydırma ve odak
   korunuyor, geçiş animasyonu mümkün oluyor.

   JS KAPALIYKEN: bu dosya hiçbir şey yapmaz. Bağlantılar bağlantı, formlar
   formdur; panel 0.2.0'daki gibi tam sayfa yüklemesiyle çalışır. Hiçbir işlev
   bu dosyaya bağımlı değil.
   ========================================================================= */

(function () {
    'use strict';

    if (!window.history || !window.fetch || !window.DOMParser) {
        return; // eski tarayıcı: normal gezinme
    }

    /*
     * Değiştirilen bölgeler. Sıra önemli değil; her biri varsa değiştirilir.
     *
     * <main> içerik, .bar-crumb konum yolu. Kenar çubuğu ve komut şeridinin
     * kendisi DEĞİŞTİRİLMEZ — zaten aynı; değişmemesi de "anında" hissinin
     * kaynağı, çünkü çerçeve hiç titremiyor.
     */
    /*
     * `#hi-plugin-slot` eklentilerin `hi_admin_data()` ve `hi_admin_script()`
     * ile bastığı düğümleri taşır ve <main> DIŞINDA durur.
     *
     * Neden bölge listesinde: bu iki yardımcı `admin.footer` kancasına basıyor,
     * yani çıktı </main>'den sonra geliyor. Bölge yalnızca main.content olsaydı
     * eklenti verisi ve betiği anında geçişte HİÇ gelmezdi — hedef sayfaya
     * doğrudan (tam yükleme ile) girildiğinde çalışıp, listeden geçilerek
     * gelindiğinde çalışmayan bir eklenti ekranı, teşhis edilmesi en zor
     * hata türü.
     */
    const REGIONS = ['main.content', '.bar-crumb', '#hi-plugin-slot'];

    const parser = new DOMParser();
    let inFlight = null;
    let token = 0;

    /* ----------------------------------------------------------- durum ışığı */

    function activity(state, text) {
        const box = document.getElementById('hi-activity');

        if (!box) return;

        box.classList.toggle('is-busy', state === 'busy');
        box.classList.toggle('is-err', state === 'error');

        const label = box.querySelector('span:last-child');

        if (label && text !== undefined) {
            label.textContent = text;
        }
    }

    /** Saat:dakika — otomatik kaydetme ve gezinme geri bildirimi için. */
    function clock() {
        const now = new Date();

        return String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    }

    /* --------------------------------------------------------------- yardımcı */

    /** Bu adres panel içinde mi ve biz mi ele almalıyız? */
    function handled(url, anchor) {
        if (!url) return false;

        let target;

        try {
            target = new URL(url, window.location.href);
        } catch (error) {
            return false;
        }

        if (target.origin !== window.location.origin) return false;

        // Panel dizini dışına çıkan her şey normal gezinsin (ön yüz, çıkış vb.).
        const base = window.location.pathname.replace(/[^/]*$/, '');

        if (target.pathname.indexOf(base) !== 0) return false;

        // Yalnızca .php sayfaları; varlık ve indirme bağlantıları değil.
        if (!/\.php$/.test(target.pathname)) return false;

        if (anchor) {
            if (anchor.target && anchor.target !== '_self') return false;
            if (anchor.hasAttribute('download')) return false;
            if (anchor.getAttribute('rel') === 'external') return false;
            if (anchor.hasAttribute('data-no-nav')) return false;
        }

        return true;
    }

    /* ------------------------------------------------------------- değiştirme */

    function swap(html, url) {
        const doc = parser.parseFromString(html, 'text/html');

        // Sunucu bizi başka bir sayfaya yönlendirdiyse (oturum düştü, izin yok)
        // bölge değiştirmek yanlış olur.
        const incomingMain = doc.querySelector('main.content');

        if (!incomingMain) {
            window.location.href = url;

            return false;
        }

        REGIONS.forEach(function (selector) {
            const from = doc.querySelector(selector);
            const to = document.querySelector(selector);

            if (!from || !to) return;

            to.replaceChildren.apply(to, Array.from(from.childNodes));

            // Sınıf farkları da taşınsın (.content.is-narrow gibi).
            to.className = from.className;
        });

        if (doc.title) {
            document.title = doc.title;
        }

        // Gezinti vurgusu: hangi bağlantı etkin.
        const incomingNav = doc.querySelector('.side-nav');
        const currentNav = document.querySelector('.side-nav');

        if (incomingNav && currentNav) {
            currentNav.replaceChildren.apply(currentNav, Array.from(incomingNav.childNodes));
        }

        // Gövde sınıfı sayfa kimliğini taşıyor (body.page-content:post).
        document.body.className = doc.body.className;

        return true;
    }

    /*
     * BÖLGEDEKİ BETİKLERİ ÇALIŞTIR
     *
     * Bölge değişiminde gelen işaretlemedeki <script> etiketleri ÇALIŞMAZ —
     * HTML standardı böyle. Bu, sayfaya özgü betiği olan her ekranı sessizce
     * bozar: içerik düzenleyici (editor.js, richtext.js) ve kendi betiğini
     * taşıyan eklenti panelleri.
     *
     * Kural:
     *   - Dış betik (src): daha önce yüklenmemişse yüklenir ve BEKLENİR.
     *     Yüklenmişse atlanır — aynı dosyayı iki kez çalıştırmak dinleyicileri
     *     ikiye katlar. Zaten yüklüyse kurulumu HiAdmin.onMount yapar.
     *   - Satır içi betik: yeniden çalıştırılır. Sayfaya özgü kurulum kodudur
     *     ve az önce değiştirilen öğelere bağlanması gerekir.
     *   - type="application/json": veri, çalıştırılmaz.
     */
    const loadedScripts = new Set();

    // İlk yüklemede sayfada olan dış betikler zaten çalıştı.
    Array.from(document.querySelectorAll('script[src]')).forEach(function (node) {
        loadedScripts.add(new URL(node.src, window.location.href).href);
    });

    function runScripts(container) {
        const nodes = Array.from(container.querySelectorAll('script'));
        let chain = Promise.resolve();

        nodes.forEach(function (old) {
            const type = (old.getAttribute('type') || '').toLowerCase();

            // Veri taşıyıcıları ve modül olmayan bilinmeyen türler atlanır.
            if (type && type !== 'text/javascript' && type !== 'module') {
                return;
            }

            const src = old.getAttribute('src');

            if (src) {
                const url = new URL(src, window.location.href).href;

                if (loadedScripts.has(url)) return;

                loadedScripts.add(url);

                chain = chain.then(function () {
                    return new Promise(function (resolve) {
                        const fresh = document.createElement('script');
                        fresh.src = url;
                        if (type === 'module') fresh.type = 'module';
                        fresh.onload = resolve;
                        fresh.onerror = function () {
                            if (window.console) console.error('Betik yüklenemedi: ' + url);
                            resolve();
                        };
                        document.head.appendChild(fresh);
                    });
                });

                return;
            }

            chain = chain.then(function () {
                const fresh = document.createElement('script');
                fresh.textContent = old.textContent;

                // Eski düğümün yerine koyulur ki document.currentScript'e
                // dayanan kod aynı konumda kalsın.
                old.parentNode.replaceChild(fresh, old);
            });
        });

        return chain;
    }

    /**
     * Erişilebilirlik: bölge değişimi tarayıcı için gezinme değil, o yüzden
     * odak ve duyuru elle yapılmalı. Yoksa ekran okuyucu kullanıcısı sayfanın
     * değiştiğini hiç bilmez ve klavye odağı eski, artık var olmayan öğede kalır.
     */
    function afterSwap(pushed) {
        const main = document.querySelector('main.content');

        if (main) {
            main.setAttribute('tabindex', '-1');
            main.focus({ preventScroll: true });
            main.removeAttribute('tabindex');
        }

        if (pushed) {
            window.scrollTo({ top: 0, behavior: 'auto' });
        }

        /*
         * Sıra önemli: önce sayfaya özgü betikler çalışır (kendi onMount
         * kancalarını kaydedebilirler), sonra kancalar koşar. Betik yükleme
         * eşzamansız olduğu için mount() zincirin sonuna bağlanıyor.
         */
        runScripts(main || document).then(function () {
            if (window.HiAdmin && window.HiAdmin.mount) {
                window.HiAdmin.mount(main || document);
            }

            activity('idle', 'Hazır');
        });
    }

    /* ------------------------------------------------------------------ gidiş */

    /**
     * @param {string} url
     * @param {{push?: boolean, method?: string, body?: FormData|null}} options
     */
    function go(url, options) {
        const opts = options || {};
        const mine = ++token;

        if (inFlight) {
            inFlight.abort();
        }

        const controller = new AbortController();
        inFlight = controller;

        activity('busy', 'Yükleniyor…');

        const request = {
            method: opts.method || 'GET',
            signal: controller.signal,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'HiCMS' },
            redirect: 'follow',
        };

        if (opts.body) {
            request.body = opts.body;
        }

        return fetch(url, request)
            .then(function (response) {
                if (!response.ok && response.status !== 404) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text().then(function (html) {
                    return { html: html, url: response.url || url };
                });
            })
            .then(function (result) {
                // Bu istek eskidi (kullanıcı başka yere tıkladı).
                if (mine !== token) return;

                const apply = function () {
                    if (!swap(result.html, result.url)) return;

                    if (opts.push !== false) {
                        window.history.pushState({ hi: true }, '', result.url);
                    } else {
                        window.history.replaceState({ hi: true }, '', result.url);
                    }

                    afterSwap(opts.push !== false);
                };

                /*
                 * View Transitions varsa geçiş yumuşak olur. Yoksa aynı iş
                 * animasyonsuz yapılır — özellik değil süs.
                 */
                if (document.startViewTransition && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    document.startViewTransition(apply);
                } else {
                    apply();
                }
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;

                // Ağ hatası ya da beklenmeyen yanıt: normal gezinmeye düş.
                // Kullanıcı bozuk bir sayfada kalmaz.
                window.location.href = url;
            })
            .finally(function () {
                if (inFlight === controller) {
                    inFlight = null;
                }
            });
    }

    /* ------------------------------------------------------------- olay bağı */

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented) return;
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const anchor = event.target.closest('a[href]');

        if (!anchor) return;

        const href = anchor.getAttribute('href');

        // Çapa ve javascript: bağlantıları bize ait değil.
        if (!href || href.charAt(0) === '#' || /^[a-z]+:/i.test(href) && !/^https?:/i.test(href)) return;

        if (!handled(href, anchor)) return;

        event.preventDefault();
        go(new URL(href, window.location.href).href, { push: true });
    });

    /*
     * FORMLAR
     *
     * Yalnızca GET formları ele alınır: filtre, arama, sayfalama. Bunlar
     * yan etkisiz ve tekrar edilebilir.
     *
     * POST formlarına DOKUNULMAZ. Sebep: panelin tamamı 303 + flash mesajı
     * kalıbıyla çalışıyor, dosya yüklemesi var, ve yarıda kesilen bir POST'un
     * sunucuda ne kadar iş yaptığı belirsiz. Kaydetme akışını fetch'e taşımak
     * gerçek bir veri kaybı riski taşır; kazancı ise küçük. Kaydetme hâlâ tam
     * gönderim yapıyor ve bu bilinçli bir karar.
     */
    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;

        const form = event.target;

        if (!(form instanceof HTMLFormElement)) return;
        if ((form.method || 'get').toLowerCase() !== 'get') return;
        if (form.hasAttribute('data-no-nav')) return;

        const action = form.getAttribute('action') || window.location.pathname;

        if (!handled(action, null)) return;

        const query = new URLSearchParams(new FormData(form)).toString();
        const url = new URL(action, window.location.href);

        url.search = query;

        event.preventDefault();
        go(url.href, { push: true });
    });

    /* Geri / ileri düğmesi. */
    window.addEventListener('popstate', function (event) {
        if (!event.state || !event.state.hi) {
            // Bizim yazmadığımız bir kayıt: tarayıcıya bırak.
            return;
        }

        go(window.location.href, { push: false });
    });

    /* İlk kaydı işaretle, yoksa ilk geri tuşu bizim olmayan bir duruma düşer. */
    window.history.replaceState({ hi: true }, '', window.location.href);

    window.HiNav = {
        go: go,
        activity: activity,
        clock: clock,
        handled: handled,
    };
})();
