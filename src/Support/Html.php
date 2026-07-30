<?php

declare(strict_types=1);

namespace HiCMS\Support;

/**
 * Kullanıcı HTML'ini izin listesine indirger.
 *
 * Panelin satır içi (contenteditable) editöründen, blok alanlarından ve bileşen
 * ayarlarından gelen HTML sunucuda buradan geçer. Tarayıcıya asla ham girdi
 * gitmez: girdi çözümlenir, izin listesine uymayan her şey atılır ve çıktı
 * SIFIRDAN yeniden yazılır.
 *
 * ---------------------------------------------------------------------------
 * NEDEN DOMDocument DEĞİL, KENDİ TARAYICISI (tokenizer)?
 * ---------------------------------------------------------------------------
 * 1. Bağımlılık: `dom` eklentisi HiCMS'in zorunlu gereksinim listesinde yok
 *    (bkz. src/Install/Requirements.php — pdo_mysql, mbstring, json, fileinfo).
 *    Paylaşımlı barındırmada `dom` kapatılabiliyor. Temizleyici her içerik
 *    basımında çalıştığı için kapatılabilir bir eklentiye bağlanamaz.
 * 2. UTF-8: bu tarayıcı yalnızca ASCII sınırlayıcıları (`<`, `>`, `=`, tırnak,
 *    boşluk) arar. UTF-8 kendini senkronize eden bir kodlamadır; çok baytlı
 *    dizilerin hiçbir baytı bu sınırlayıcılarla çakışmaz. Bu yüzden ç ğ ı İ ö
 *    ş ü karakterleri TANIM GEREĞİ bozulmaz. DOMDocument yolunda ise
 *    `<meta charset>` enjekte etmek ya da `mb_convert_encoding($h,
 *    'HTML-ENTITIES', 'UTF-8')` kullanmak gerekir; ikincisi PHP 8.2'de
 *    kullanımdan kaldırıldı, birincisi unutulduğunda libxml girdiyi
 *    ISO-8859-1 sanıp Türkçe karakterleri mojibake'e çevirir.
 * 3. Sürüm kararlılığı: libxml'in HTML çözümleyicisi HTML4'tür; `<mark>` gibi
 *    HTML5 etiketlerine "invalid tag" uyarısı üretir, LIBXML_HTML_NOIMPLIED
 *    metinle başlayan parçalarda içerik düşürür ve PHP 8.4'te gelen
 *    `Dom\HTMLDocument` ayrı bir davranış kümesi getirir. PHP 8.2–8.4 arasında
 *    aynı çıktıyı vermek için libxml sürüm farklarına hiç girmemek en ucuz yol.
 * 4. Güvenlik dayanağı çözümleyici sadakati DEĞİL, yeniden yazımdır: hiçbir
 *    girdi baytı çıktıya olduğu gibi geçmez. Metin `htmlspecialchars()` ile,
 *    öznitelik değerleri doğrulandıktan sonra yine `htmlspecialchars()` ile
 *    basılır. Dolayısıyla tarayıcı ile aramızdaki olası bir çözümleme farkı en
 *    kötü durumda içerik kaybına yol açar, betik çalıştırmaya yol açamaz.
 *
 * ---------------------------------------------------------------------------
 * ÇEKİRDEKTEN BAĞIMSIZLIK
 * ---------------------------------------------------------------------------
 * Bu sınıf `hi()`, `Kernel` ya da olay dağıtıcısına DOKUNMAZ. Kurulum
 * sırasında (henüz çekirdek yokken) ve build/smoke.php içinde tek başına
 * çalışmak zorundadır. İzin listesini genişletmek isteyen eklenti küresel bir
 * kancaya değil kurucuya (`new Html([...])`) başvurur.
 *
 * ---------------------------------------------------------------------------
 * SERTLEŞTİRME NOTU (0.3.0)
 * ---------------------------------------------------------------------------
 * Aşağıdaki kod üç bağımsız saldırgan taramasından sonra sertleştirildi. Kodda
 * "ELEŞTİRMEN BULGUSU" ile başlayan yorumların yanındaki satırlar belirli bir
 * atlatmayı ya da içerik bozulmasını kapatıyor; her biri build/smoke.php'nin
 * "HTML temizleyici" bölümünde ayrı bir denetimle korunuyor. O satırları
 * "gereksiz" diye silmek testi düşürür.
 *
 * @see \HiCMS\Support\Str::safeHtml() Geriye uyumluluk sarmalayıcısı
 */
final class Html
{
    /**
     * Etiket → izinli öznitelik tablosu.
     *
     * Tabloda olmayan etiket SOYULUR (içeriği kalır), tabloda olmayan
     * öznitelik DÜŞER. class, style, id, data-*, srcset, sizes, on* — hiçbiri
     * listede olmadığı için ayrı bir kara listeye gerek yoktur.
     */
    private const TAGS = [
        /* ---- satır içi ---- */
        'a'      => ['href', 'title', 'rel', 'target'],
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'i'      => [],
        'u'      => [],
        's'      => [],
        'del'    => [],
        'ins'    => [],
        'code'   => [],
        'mark'   => [],
        'sub'    => [],
        'sup'    => [],
        'small'  => [], // HTML'de satır içidir; blok listesinde anılsa da buraya aittir
        'span'   => [],
        'br'     => [],

        /* ELEŞTİRMEN BULGUSU (sessizce silinen biçim): abbr, cite, q, kbd,
         * samp, var, time, dfn izin listesinde olmadığı için yazarın verdiği
         * anlam kayboluyordu. Hiçbiri betik taşımaz, hiçbiri blok bağlamını
         * değiştirmez; yalnızca metne anlam katar. `strike`/`tt`/`acronym`
         * ise ALIAS tablosuyla modern karşılığına çevrilir. */
        'abbr'   => ['title'],
        'dfn'    => ['title'],
        'cite'   => [],
        'q'      => [],
        'kbd'    => [],
        'samp'   => [],
        'var'    => [],
        'time'   => ['datetime'],

        /* ---- blok ---- */
        'p'          => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'h5'         => [],
        'h6'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'pre'        => [],
        'figure'     => [],
        'figcaption' => [],
        'hr'         => [],
        'table'      => [],
        'thead'      => [],
        'tbody'      => [],
        'tr'         => [],
        'th'         => [],
        'td'         => [],

        /* ELEŞTİRMEN BULGUSU (tablo başlığı tablodan kopuyordu): `caption`
         * izin listesinde olmadığı için soyuluyor, metni `<table>` ile `<tr>`
         * arasında kalıyor ve tarayıcının foster parenting kuralı onu tablonun
         * ÖNÜNE taşıyordu. `tfoot` da aynı gruptan; ikisi de yalnızca yapı
         * taşır. `dl/dt/dd` ise soyulunca tanım listesi tek satıra
         * yapışıyordu. */
        'caption'    => [],
        'tfoot'      => [],
        'dl'         => [],
        'dt'         => [],
        'dd'         => [],

        'img'        => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    ];

    /**
     * Eski/eşdeğer etiketlerin modern karşılığı.
     *
     * ELEŞTİRMEN BULGUSU: `<strike>` ve `<tt>` izin listesinde olmadığı için
     * biçim sessizce siliniyordu. Silmek yerine kanonik karşılığa çevrilir;
     * çıktı yine yalnızca izin listesindeki etiketlerden oluşur.
     */
    private const ALIAS = [
        'strike'  => 's',
        'tt'      => 'code',
        'acronym' => 'abbr',
    ];

    /** İçeriği olmayan (void) etiketler: yığına girmez, kapanışı basılmaz. */
    private const VOID = ['br' => true, 'hr' => true, 'img' => true];

    /**
     * Satır içi biçim öğeleri.
     *
     * İki yerde kullanılır: (1) blok başlarken açık kalanlar kapatılır,
     * (2) boş kalanlar çıktıdan silinir. `a` BİLEREK yoktur — HTML5'te blok
     * içeren bağlantı geçerlidir, kapatmak meşru yapıyı bozar.
     */
    private const INLINE = [
        'strong' => true, 'b' => true, 'em' => true, 'i' => true, 'u' => true,
        's' => true, 'del' => true, 'ins' => true, 'code' => true, 'mark' => true,
        'sub' => true, 'sup' => true, 'small' => true, 'span' => true,
        'abbr' => true, 'dfn' => true, 'cite' => true, 'q' => true,
        'kbd' => true, 'samp' => true, 'var' => true, 'time' => true,
    ];

    /** İzin listesindeki blok öğeleri (satır içi biçimi kapatan bağlam). */
    private const BLOCKS = [
        'p' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true,
        'h6' => true, 'ul' => true, 'ol' => true, 'li' => true,
        'blockquote' => true, 'pre' => true, 'figure' => true,
        'figcaption' => true, 'hr' => true, 'table' => true, 'caption' => true,
        'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true,
        'th' => true, 'td' => true, 'dl' => true, 'dt' => true, 'dd' => true,
    ];

    /**
     * Yığının EN ÜSTÜ buradaysa "tablo bağlamındayız": hücre dışında metin ya
     * da satır içi öğe duramaz.
     */
    private const TABLE_CONTEXT = [
        'table' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true,
    ];

    /** Tablo bağlamında meşru duran etiketler (örtük hücre açtırmazlar). */
    private const TABLE_PARTS = [
        'caption' => true, 'colgroup' => true, 'col' => true, 'thead' => true,
        'tbody' => true, 'tfoot' => true, 'tr' => true, 'td' => true, 'th' => true,
    ];

    /**
     * İçeriğiyle birlikte TAMAMEN silinen etiketler ve silme biçimi.
     *
     * Neden etiketi soyup içeriği bırakmak yetmiyor — her biri için gerekçe:
     *
     * 'raw'  → İçeriği tarayıcı tarafından işaretleme olarak ÇÖZÜMLENMEZ (ham
     *          metin / kaçırılabilir ham metin içerik modeli) VE kullanıcıya
     *          da görünmez. Hem etiket hem içerik atılır.
     *          - script: içerik JavaScript kaynağıdır. Soyulsa `alert(1)`
     *            ekranda düz metin olarak görünürdü; düzyazı olarak hiçbir
     *            anlamı yok, atılır.
     *          - style: içerik CSS'tir. Soyulsa CSS gövdeye metin olarak
     *            SIZAR (kullanıcı sayfada `.a{color:red}` görür) ve eski
     *            tarayıcılarda `expression()` yüzeyi açılır. Atılır.
     *          - iframe: HTML5'te içeriği zaten yok sayılır, ekranda hiç
     *            görünmez. Soymak görünmeyen metni birden görünür kılardı.
     *          - title: belge üstverisidir, gövdede gösterilmez.
     *          - noembed / noframes: modern tarayıcıda gösterilmez.
     * 'text' → İçeriği ham metindir AMA tarayıcı onu KULLANICIYA GÖSTERİR.
     *          ELEŞTİRMEN BULGUSU: bunları atmak sessiz metin kaybıydı
     *          (`<textarea>KAYBOLAN METIN</textarea>` → boş dizge). Etiket
     *          düşer, içerik METİN olarak (kaçırılarak) basılır; işaretleme
     *          olarak yorumlanmadığı için yeni bir saldırı yüzeyi açmaz.
     *          - textarea: içerik form alanının değeri olarak görünür.
     *          - xmp / plaintext: içerik önbiçimli metin olarak basılır.
     * 'nest' → İçeriği HTML'dir ama bağlam kuralları farklıdır; iç içe
     *          geçebildiği için derinlik sayılır.
     *          - svg / math: yabancı içerik (foreign content). İçinde
     *            çözümleme kuralları değişir; `<svg><style><!--</style>
     *            <img src=x onerror=1>-->` gibi mXSS zincirleri buradan
     *            çıkar. Kökü atmak tek güvenli davranıştır.
     *          - template: içeriği DOM'da etkisizdir; template bağlamından
     *            çıkarıp yeniden yazmak onu ETKİNLEŞTİRİR.
     *          - noscript: betik açık/kapalı durumuna göre farklı çözümlenir,
     *            klasik mXSS kaynağıdır.
     *          - form: düzyazı editöründe meşru bir form yoktur; etiketi
     *            soyup içeriği bıraksak arayüz sahteciliği (kimlik avı
     *            görünümü) metni sayfada kalırdı.
     *          - object: içeriği yedek (fallback) gösterimdir, `<param>` ve
     *            gömülü `<embed>` taşır.
     * 'void' → Kapanışı yoktur; yalnızca etiketin kendisi düşer. `input` bu
     *          gruptadır: "içeriğiyle silinir" ifadesi onda boşta kalır.
     */
    private const DROP = [
        'script'   => 'raw',
        'style'    => 'raw',
        'iframe'   => 'raw',
        'title'    => 'raw',
        'noembed'  => 'raw',
        'noframes' => 'raw',

        'textarea'  => 'text',
        'xmp'       => 'text',
        'plaintext' => 'text',

        'svg'      => 'nest',
        'math'     => 'nest',
        'template' => 'nest',
        'noscript' => 'nest',
        'form'     => 'nest',
        'object'   => 'nest',

        'input'    => 'void',
        'embed'    => 'void',
        'link'     => 'void',
        'meta'     => 'void',
        'base'     => 'void',
        'source'   => 'void',
        'track'    => 'void',
        'param'    => 'void',
        'area'     => 'void',
        'col'      => 'void',
        'frame'    => 'void',
        'keygen'   => 'void',
        'basefont' => 'void',
    ];

    /**
     * Kapsam (scope) engelleri — HTML5'in "have an element in ... scope"
     * kuralları.
     *
     * ELEŞTİRMEN BULGUSU: eski `closeTo()` YIĞININ TAMAMINI tarıyordu. Bu
     * yüzden iç içe listeler ve iç içe tablolar yıkılıyordu: `<ul><li>a<ul>
     * <li>b` girdisinde iç `<li>`, DIŞ `<li>`yi bulup aradaki `<ul>`ü
     * kapatıyordu. HTML5 aramayı kapsamla sınırlar; aşağıdaki üç engel kümesi
     * o sınırı verir.
     */
    private const SCOPE_BASE  = ['table', 'td', 'th', 'caption'];
    private const SCOPE_LIST  = ['table', 'td', 'th', 'caption', 'ul', 'ol'];
    private const SCOPE_TABLE = ['table'];

    /**
     * Örtük kapanış tablosu: soldaki etiket açılırken sağdaki ADIMLAR SIRAYLA
     * uygulanır. Her adım `[adlar, kapsam engelleri]` biçimindedir; yığında o
     * adlardan biri KAPSAM İÇİNDE varsa ona kadar (o dahil) geri sarılır.
     *
     * Adımların SIRALI olması şart. HTML5 "in body" kuralları önce açık
     * paragrafı kapatır, sonra aynı türden düğümü açar: `<p>a<li>b` girdisinde
     * tek geçişli "en üstteki eşleşme" araması `<li>`yi paragrafın İÇİNDE
     * bırakırdı (geçersiz HTML). İki adım bunu engeller.
     *
     * Kötü biçimli HTML'i normalize eden yer burasıdır: `<p>a<p>b` iki
     * paragrafa, `<li>a<li>b` iki maddeye, `<td>a<td>b` iki hücreye ayrılır.
     *
     * Başlıklar (h2..h6) burada YOK: HTML5 yeni başlık açılırken kapsam
     * araması yapmaz, yalnızca GEÇERLİ DÜĞÜMÜ denetler (bkz. walk()).
     *
     * @var array<string, list<array{0: list<string>, 1: list<string>}>>
     */
    private const IMPLIED_END = [
        'p'          => [[['p'], self::SCOPE_BASE]],
        'h2'         => [[['p'], self::SCOPE_BASE]],
        'h3'         => [[['p'], self::SCOPE_BASE]],
        'h4'         => [[['p'], self::SCOPE_BASE]],
        'h5'         => [[['p'], self::SCOPE_BASE]],
        'h6'         => [[['p'], self::SCOPE_BASE]],
        'ul'         => [[['p'], self::SCOPE_BASE]],
        'ol'         => [[['p'], self::SCOPE_BASE]],
        'li'         => [[['p'], self::SCOPE_BASE], [['li'], self::SCOPE_LIST]],
        'dl'         => [[['p'], self::SCOPE_BASE]],
        'dt'         => [[['p'], self::SCOPE_BASE], [['dt', 'dd'], self::SCOPE_BASE]],
        'dd'         => [[['p'], self::SCOPE_BASE], [['dt', 'dd'], self::SCOPE_BASE]],
        'blockquote' => [[['p'], self::SCOPE_BASE]],
        'pre'        => [[['p'], self::SCOPE_BASE]],
        'figure'     => [[['p'], self::SCOPE_BASE]],
        'figcaption' => [[['p'], self::SCOPE_BASE]],
        'hr'         => [[['p'], self::SCOPE_BASE]],
        'table'      => [[['p'], self::SCOPE_BASE]],
        'caption'    => [[['p'], self::SCOPE_BASE]],
        'thead'      => [[['p'], self::SCOPE_BASE], [['thead', 'tbody', 'tfoot'], self::SCOPE_TABLE]],
        'tbody'      => [[['p'], self::SCOPE_BASE], [['thead', 'tbody', 'tfoot'], self::SCOPE_TABLE]],
        'tfoot'      => [[['p'], self::SCOPE_BASE], [['thead', 'tbody', 'tfoot'], self::SCOPE_TABLE]],
        'tr'         => [[['p'], self::SCOPE_BASE], [['tr'], self::SCOPE_TABLE]],
        'th'         => [[['p'], self::SCOPE_BASE], [['th', 'td'], self::SCOPE_TABLE]],
        'td'         => [[['p'], self::SCOPE_BASE], [['th', 'td'], self::SCOPE_TABLE]],

        /* ELEŞTİRMEN BULGUSU: iç içe `<a>` geçerli HTML değildir; HTML5 yeni
         * bağlantı açılırken açık olanı kapatır. Normalize etmediğimiz için
         * ürettiğimiz ağaç ile tarayıcının kurduğu ağaç ayrışıyordu (ve
         * `target=_blank` ile ikinci bağlantı noopener'sız kalıyordu). */
        'a'          => [[['a'], self::SCOPE_BASE]],
    ];

    /**
     * Açık bitiş etiketlerinin kapsamı. Tabloda olmayan ad SCOPE_BASE kullanır.
     *
     * @var array<string, list<string>>
     */
    private const END_SCOPE = [
        'li'      => self::SCOPE_LIST,
        'table'   => self::SCOPE_TABLE,
        'caption' => self::SCOPE_TABLE,
        'thead'   => self::SCOPE_TABLE,
        'tbody'   => self::SCOPE_TABLE,
        'tfoot'   => self::SCOPE_TABLE,
        'tr'      => self::SCOPE_TABLE,
        'td'      => self::SCOPE_TABLE,
        'th'      => self::SCOPE_TABLE,
    ];

    /**
     * Zorunlu ata bağlamı: etiket → dıştan içe doğru gereken ata kümeleri.
     * Kümedeki adlardan hiçbiri kapsam içinde açık değilse İLKİ açılır.
     *
     * ELEŞTİRMEN BULGUSU: `<tr><td>Ocak</td></tr>` gibi kısmi tablo
     * yapıştırmaları hiçbir `<table>` atası olmadan basılıyordu; tarayıcı bu
     * belirteçleri "in body" kipinde çözümleme hatası sayıp TAMAMEN atıyor ve
     * satırlar tek bir metin akışına yapışıyordu. Aynı sorun `<li>` için de
     * geçerli. Eksik ata artık örtük olarak açılır.
     *
     * @var array<string, list<list<string>>>
     */
    private const IMPLIED_OPEN = [
        'li'      => [['ul', 'ol']],
        'dt'      => [['dl']],
        'dd'      => [['dl']],
        'tr'      => [['table']],
        'td'      => [['table'], ['tr']],
        'th'      => [['table'], ['tr']],
        'thead'   => [['table']],
        'tbody'   => [['table']],
        'tfoot'   => [['table']],
        'caption' => [['table']],
    ];

    /** Başlıklar — "geçerli düğüm" kuralı için. */
    private const HEADINGS = [
        'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
    ];

    /**
     * Blok sınırı sayılan etiketler.
     *
     * İki iş yapar: düz metne çevirirken satır sonu bırakır (`<p>a</p><p>b</p>`
     * → "a\nb") ve HTML kipinde İZİN LİSTESİ DIŞI bir blok soyulurken araya
     * ayırıcı koyar.
     *
     * ELEŞTİRMEN BULGUSU: HTML kipinde bu tablo hiç kullanılmıyordu, bu yüzden
     * `<div>a</div><div>b</div>` çıktısı "ab" oluyordu — iki görsel satır tek
     * kelimeye yapışıyordu. Projenin kendi istemci tarafı temizleyicisi
     * (admin/assets/js/richtext.js) araya boşluk koyuyordu; sunucu tarafı
     * artık aynı kuralı uyguluyor.
     */
    private const BREAKS = [
        'br' => true, 'p' => true, 'div' => true, 'section' => true, 'article' => true,
        'header' => true, 'footer' => true, 'main' => true, 'aside' => true, 'nav' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'ul' => true, 'ol' => true, 'li' => true, 'blockquote' => true, 'pre' => true,
        'figure' => true, 'figcaption' => true, 'hr' => true, 'table' => true,
        'tr' => true, 'th' => true, 'td' => true, 'caption' => true,
        'dl' => true, 'dt' => true, 'dd' => true, 'address' => true,
        'form' => true, 'fieldset' => true, 'legend' => true, 'details' => true,
        'summary' => true, 'noscript' => true,
    ];

    /** `href` / `src` için izinli şemalar. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * URL taşıyan öznitelik adları.
     *
     * ELEŞTİRMEN BULGUSU: değer doğrulaması yalnızca `href` ve `src` adlarına
     * bağlıydı. Kurucudan gelen `formaction`, `poster`, `ping`, `background`,
     * `action` gibi adlar "düz metin" dalına düşüyor ve `javascript:` değeri
     * AYNEN basılıyordu — sanksiyonlu genişletme yolu sessiz bir XSS lavabosu
     * oluyordu. Ad ne olursa olsun bu listedeki her öznitelik safeUrl()'den
     * geçer.
     */
    private const URL_ATTRS = [
        'href', 'src', 'cite', 'action', 'formaction', 'poster', 'background',
        'ping', 'data', 'longdesc', 'usemap', 'profile', 'codebase', 'lowsrc',
        'dynsrc', 'manifest', 'xlink:href',
    ];

    /**
     * `rel` için izinli belirteçler. Bilinmeyen belirteç düşer: `rel`
     * değerleri tarayıcı davranışı tetikleyebiliyor, serbest metin olamaz.
     */
    private const REL = [
        'alternate', 'author', 'bookmark', 'external', 'help', 'license', 'me',
        'next', 'nofollow', 'noopener', 'noreferrer', 'prev', 'search',
        'sponsored', 'tag', 'ugc',
    ];

    /**
     * `<img src>` için izinli gömülü veri adresi.
     *
     * ELEŞTİRMEN BULGUSU: contenteditable editörüne pano görüntüsü
     * yapıştırmak Chrome/Firefox'ta tam olarak bu biçimi üretiyor
     * (`data:image/png;base64,...`); `data:` şeması reddedildiği için görsel
     * kaydedince SESSİZCE yok oluyordu.
     *
     * `image/svg+xml` BİLEREK yok: SVG betik taşıyabilir (`<svg onload=...>`)
     * ve aynı köken altında açılır. Yalnızca raster biçimler ve yalnızca
     * base64 gövdesi kabul edilir; base64 alfabesi `<`, `>`, `"` ve boşluk
     * içermediği için gövde işaretlemeye dönüşemez.
     */
    private const IMAGE_DATA = '#^data:image/(?:png|jpeg|jpg|gif|webp|avif|bmp);base64,[A-Za-z0-9+/]{8,}={0,2}$#';

    /**
     * URL değerinden atılan ASCII DIŞI görünmez/boşluk karakterleri.
     *
     * ELEŞTİRMEN BULGUSU: safeUrl() URL'nin başını yalnızca ASCII denetim
     * karakterlerine karşı temizliyordu. `<a href="&nbsp;javascript:alert(1)">`
     * girdisinde başa gelen U+00A0 yüzünden şema kalıbı eşleşmiyor, değer
     * "göreli yol" sayılıp AYNEN basılıyordu. Güncel tarayıcı bunu
     * çalıştırmaz (URL çözümleyici yalnızca C0 + boşluk kırpar) ama kayıtlı
     * içerikte görünür bir `javascript:` dizgesi bırakmak da kabul edilemez.
     */
    private const URL_BLANKS = '/[\x{0085}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}\x{2029}'
        . '\x{202F}\x{205F}\x{2060}-\x{2064}\x{3000}\x{FEFF}\x{FFF9}-\x{FFFB}\x{FFFE}\x{FFFF}]+/u';

    /** Hiçbir öznitelikte basılmayacak şemalar. */
    private const BAD_SCHEMES = 'javascript|vbscript|data|file|blob|filesystem|view-source';

    /** HTML'de anlamlı boşluk karakterleri (etiket içi ayırıcılar). */
    private const SPACE = " \t\n\r\f";

    /**
     * Etiket adını bitiren karakterler.
     *
     * ELEŞTİRMEN BULGUSU: ad yalnızca harf/rakamdan okunuyordu. Bu yüzden
     * `<style-x>` özel öğesi `style` sanılıp içeriğiyle siliniyor,
     * `</style=1>` ise `style`i KAPATIYORDU (tarayıcı kapatmaz). HTML5 etiket
     * adı durumu yalnızca boşluk, `/` ve `>` ile biter; aynısını yapıyoruz.
     */
    private const NAME_STOP = self::SPACE . '/>';

    /** İç içelik sınırı — özyinelemeli girdiye karşı ucuz bir sigorta. */
    private const MAX_DEPTH = 64;

    /** Öznitelik değeri üst sınırı (karakter). */
    private const MAX_ATTR = 500;

    /**
     * Görünmez ve yön değiştirme karakterleri.
     *
     * DİKKAT: U+0000–U+001F aralığı TOPTAN SİLİNMEZ. O aralıkta \n, \r ve \t
     * var; toptan silmek mevcut tüm içeriği bozar (BlockRenderer::rich()
     * paragrafları `\n{2,}` ile ayırır, `<pre>` girintisi \t ile durur).
     * Yalnızca gerçekten zararlı olanlar hedeflenir:
     *   U+FEFF          → sıfır genişlikli gizleme
     *   U+202A..U+202E  → eski yön değiştirme (bidi override)
     *   U+2066..U+2069  → yön yalıtımı; görünen metni ters çevirip sahte
     *                     bağlantı metni üretmeye yarar (Trojan Source)
     *
     * U+0000 bu listede DEĞİL: silinmez, U+FFFD'ye çevrilir (bkz. normalize()).
     */
    private const INVISIBLE = [
        "\u{FEFF}",
        "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}",
    ];

    private static ?self $default = null;

    /** @var array<string, list<string>> */
    private readonly array $tags;

    /** @var list<string> */
    private readonly array $schemes;

    /**
     * Genişletilmiş bir temizleyici kurar.
     *
     * Küresel kanca (hi_filter gibi) BİLEREK yoktur: o zincir
     * `hi()` → `Kernel::instance()` bağımlılığı doğurur ve temizleyici
     * kurulum sırasında çekirdek olmadan da çalışmak zorundadır. Genişletme
     * yalnızca buradan yapılır:
     *
     *     $temizleyici = new Html(['abbr' => ['title']], ['ftp']);
     *     echo $temizleyici->sanitize($html);
     *
     * @param array<string, list<string>> $extraTags Ek etiket → öznitelik
     * @param list<string>                $extraSchemes Ek URL şeması
     */
    public function __construct(array $extraTags = [], array $extraSchemes = [])
    {
        $tags = self::TAGS;

        foreach ($extraTags as $tag => $attributes) {
            $tag = strtolower(trim((string) $tag));

            // Silinen etiketler genişletmeyle geri getirilemez: `script`
            // eklemek isteyen bir eklenti hatadır, sessizce yok sayılır.
            if (preg_match('/^[a-z][a-z0-9-]*$/', $tag) !== 1 || isset(self::DROP[$tag])) {
                continue;
            }

            $clean = [];

            foreach ((array) $attributes as $attribute) {
                $attribute = strtolower(trim((string) $attribute));

                if (preg_match('/^[a-z][a-z0-9:-]*$/', $attribute) !== 1) {
                    continue;
                }

                /* Olay öznitelikleri, stil ve içine HTML ya da URL LİSTESİ
                 * alan öznitelikler hiçbir koşulda açılmaz: `srcdoc` tam bir
                 * HTML belgesi taşır, `srcset`/`sizes` içinde URL listesi
                 * ayrıştırmak ayrı bir saldırı yüzeyidir.
                 * NOT: URL taşıyan öteki adlar (formaction, poster, ping...)
                 * yasaklanmaz; URL_ATTRS listesi sayesinde değerleri
                 * safeUrl()'den geçer. */
                if (str_starts_with($attribute, 'on')
                    || in_array($attribute, ['style', 'srcset', 'sizes', 'srcdoc'], true)) {
                    continue;
                }

                $clean[] = $attribute;
            }

            $tags[$tag] = array_values(array_unique($clean));
        }

        $schemes = self::SCHEMES;

        foreach ($extraSchemes as $scheme) {
            $scheme = strtolower(trim((string) $scheme));

            // javascript:, vbscript:, data: ve file: asla açılmaz.
            if (preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) !== 1
                || preg_match('/^(?:' . self::BAD_SCHEMES . ')$/', $scheme) === 1) {
                continue;
            }

            $schemes[] = $scheme;
        }

        $this->tags    = $tags;
        $this->schemes = array_values(array_unique($schemes));
    }

    /* ---------------------------------------------------------------------
     * Duran API
     * ------------------------------------------------------------------ */

    /**
     * Kullanıcı HTML'ini güvenli hâle getirir.
     *
     * Çıktı her zaman iyi biçimli, kanonik ve DEĞİŞMEZDİR:
     * `clean(clean($x)) === clean($x)`.
     */
    public static function clean(string $html): string
    {
        return self::shared()->sanitize($html);
    }

    /**
     * HTML'i etiketsiz düz metne indirger.
     *
     * DİKKAT: dönen değer KAÇIRILMAMIŞ düz metindir (`strip_tags()` gibi).
     * HTML'e basarken `Str::html()` ile kaçırılmalıdır. Blok etiketlerinin
     * yerine satır sonu bırakılır; `Str::limit()` boşlukları kendi toplar.
     */
    public static function text(string $html): string
    {
        return self::shared()->plain($html);
    }

    /**
     * İzin listesi tablosu: etiket → izinli öznitelikler.
     *
     * Panelde editör ipucu göstermek için kullanılır.
     *
     * @return array<string, list<string>>
     */
    public static function allowed(): array
    {
        return self::shared()->tags();
    }

    /** Varsayılan (genişletilmemiş) temizleyici. */
    private static function shared(): self
    {
        return self::$default ??= new self();
    }

    /* ---------------------------------------------------------------------
     * Nesne API'si
     * ------------------------------------------------------------------ */

    public function sanitize(string $html): string
    {
        return $this->walk($html, false);
    }

    public function plain(string $html): string
    {
        return $this->walk($html, true);
    }

    /** @return array<string, list<string>> */
    public function tags(): array
    {
        return $this->tags;
    }

    /* ---------------------------------------------------------------------
     * Tarayıcı (tokenizer)
     * ------------------------------------------------------------------ */

    /**
     * Girdiyi tek geçişte çözümler ve çıktıyı sıfırdan kurar.
     *
     * @param bool $textOnly true ise etiket basılmaz, metin kaçırılmaz
     */
    private function walk(string $html, bool $textOnly): string
    {
        $html = $this->normalize($html);

        if ($html === '') {
            return '';
        }

        $length = strlen($html);
        $out    = '';

        /** @var list<string> $stack Açık kalan izinli etiketler */
        $stack = [];

        // Bastırma durumu: yalnızca 'nest' türü için (iç içelik sayılır).
        $hidden      = '';
        $hiddenDepth = 0;

        /* Kapanışı bulunamayan 'nest' adları. Arama konumu ileri gittiği için
         * bir ad bir kez "kapanışı yok" çıktıysa sonrası için de yoktur; not
         * almak `<form><form>...` gibi binlerce etiketli girdide her seferinde
         * belgeyi baştan taramayı önler. */
        $noEnd = [];

        /* Soyulmuş blok etiketinden kalan ayırıcı BORCU. Hemen basılmaz,
         * bir sonraki içerikten önce ödenir; böylece çıktının sonunda sarkan
         * boşluk kalmaz. */
        $gap = false;

        $i = 0;

        while ($i < $length) {
            $lt = strpos($html, '<', $i);

            if ($lt === false) {
                if ($hidden === '') {
                    $this->emitText(substr($html, $i), $out, $stack, $gap, $textOnly);
                }

                break;
            }

            if ($lt > $i && $hidden === '') {
                $this->emitText(substr($html, $i, $lt - $i), $out, $stack, $gap, $textOnly);
            }

            $i    = $lt;
            $next = $html[$lt + 1] ?? '';

            /* Yorum, DOCTYPE, CDATA ve bozuk yorum: tamamen silinir.
             * Yorumlar kullanıcıya görünen içerik taşımaz; buna karşılık
             * koşullu yorumlar (`<!--[if IE]>`) ve `<!-->` gibi bozuk
             * biçimler klasik mXSS kaldıraçlarıdır. */
            if ($next === '!') {
                $i = $this->skipComment($html, $lt, $length);
                continue;
            }

            // `<?...>` — işleme yönergesi / PHP kalıntısı: silinir.
            if ($next === '?') {
                $close = strpos($html, '>', $lt);
                $i     = $close === false ? $length : $close + 1;
                continue;
            }

            if ($next === '/') {
                $nameEnd = 0;
                $name    = $this->alias($this->readName($html, $lt + 2, $nameEnd));

                /* `pre`/`code` içinde bilinmeyen `</...>`: kod örneğidir,
                 * düz metin olarak basılır (aşağıdaki açılış dalıyla aynı
                 * gerekçe). */
                if ($hidden === '' && !$textOnly && !isset($this->tags[$name])
                    && $this->literal($stack)) {
                    $this->emitText('<', $out, $stack, $gap, $textOnly);
                    $i = $lt + 1;
                    continue;
                }

                /* ELEŞTİRMEN BULGUSU: eski kod burada ham `strpos('>')`
                 * yapıyordu. Bitiş etiketleri de HTML5'te öznitelik
                 * durumlarından geçer, yani tırnak içindeki `>` etiketi
                 * BİTİRMEZ: `</p attr="x>y">` TEK bir belirteçtir. Ham arama
                 * yüzünden `y">` metin akışına düşüp sayfada görünür çöp
                 * oluyordu. */
                $tagEnd    = 0;
                $selfClose = false;
                $tagClosed = false;
                $this->readAttributes($html, $nameEnd, $tagEnd, $selfClose, $tagClosed);
                $i = $tagEnd;

                if ($name === '' || !$tagClosed) {
                    // `</>`, `</ x>` ya da etiketin ortasında biten girdi.
                    continue;
                }

                if ($hidden !== '') {
                    if ($name === $hidden && --$hiddenDepth <= 0) {
                        $hidden = '';
                    }

                    continue;
                }

                if ($textOnly) {
                    if (isset(self::BREAKS[$name])) {
                        $out .= "\n";
                    }

                    continue;
                }

                if (!isset($this->tags[$name])) {
                    // İzin listesi dışı kapanış yığında yoktur; blok ise
                    // ayırıcı borcu bırakır (`</div>` kelimeleri yapıştırmasın).
                    if (isset(self::BREAKS[$name])) {
                        $gap = true;
                    }

                    continue;
                }

                // Void etiketin kapanışı yoktur; yığında olmadığı için yok sayılır.
                $out .= $this->closeTo($stack, [$name], self::END_SCOPE[$name] ?? self::SCOPE_BASE);
                continue;
            }

            if (!$this->isLetter($next)) {
                // `< 5` gibi bir durum: `<` düz metindir.
                if ($hidden === '') {
                    $this->emitText('<', $out, $stack, $gap, $textOnly);
                }

                $i = $lt + 1;
                continue;
            }

            /* ---- açılış etiketi ---- */

            $nameEnd = 0;
            $name    = $this->alias($this->readName($html, $lt + 1, $nameEnd));

            /* ELEŞTİRMEN BULGUSU: `<pre><code>List<String> x = new
             * ArrayList<>();</code></pre>` ve `<code>if (a<b) { return; }`
             * gibi kod örnekleri yutuluyordu — `<b)` ve `<String>` etiket
             * sanılıyor, `</code>` bile bozuk etiketin içinde kalıyordu.
             * `pre`/`code` bağlamında izin listesinde OLMAYAN bir ad düz
             * metindir. Bu yön her zaman güvenli: metin kaçırıldığı için en
             * kötüsü `&lt;script&gt;` dizgesini GÖSTERMEK olur, çalıştırmak
             * olamaz. */
            if (!$textOnly && $hidden === '' && !isset($this->tags[$name]) && $this->literal($stack)) {
                $this->emitText('<', $out, $stack, $gap, $textOnly);
                $i = $lt + 1;
                continue;
            }

            $tagEnd    = 0;
            $selfClose = false;
            $tagClosed = false;
            $attrs     = $this->readAttributes($html, $nameEnd, $tagEnd, $selfClose, $tagClosed);
            $i         = $tagEnd;

            /* Etiket `>` görmeden girdi bitmişse belirteç DÜŞER. Tarayıcı da
             * öyle yapar: etiket açma / öznitelik durumunda EOF bir çözümleme
             * hatasıdır ve belirteç atılır. Atmasak `<p>a</p><b` girdisi
             * çıktıya boş bir `<b></b>` eklerdi — kırpılmış çöpten görünür
             * öğe üretmek istemiyoruz. */
            if (!$tagClosed) {
                continue;
            }

            /* `/>` işareti HTML öğelerinde YOK SAYILIR: `<script/>alert(1)`
             * tarayıcıda bir script AÇAR, kendini kapatmaz. XML tarzı kendini
             * kapatma yalnızca yabancı içerikte (svg, math) geçerlidir. Bu
             * ayrım olmadan `<style/>.x{}</style>` gibi bir girdide CSS metne
             * dönüp gövdeye sızardı. */
            $selfClosed = $selfClose && ($name === 'svg' || $name === 'math');

            if ($hidden !== '') {
                // Yalnızca 'nest' türünde iç içelik sayılır (raw/text türü
                // artık doğrudan taranıyor, buraya hiç girmiyor).
                if ($name === $hidden && !$selfClosed) {
                    $hiddenDepth++;
                }

                continue;
            }

            $drop = self::DROP[$name] ?? '';

            if ($drop !== '') {
                if ($drop === 'void' || $selfClosed) {
                    continue;
                }

                if ($drop === 'raw' || $drop === 'text') {
                    /* HTML5'te `plaintext`in bitiş etiketi YOKTUR: kalan her
                     * şey metindir. */
                    if ($name === 'plaintext') {
                        $this->emitText(substr($html, $i), $out, $stack, $gap, $textOnly);
                        $i = $length;
                        continue;
                    }

                    [$contentEnd, $resume] = $this->rawText($html, $i, $name);

                    if ($drop === 'text') {
                        $this->emitText(
                            substr($html, $i, $contentEnd - $i),
                            $out,
                            $stack,
                            $gap,
                            $textOnly
                        );
                    }

                    $i = $resume;
                    continue;
                }

                /* ELEŞTİRMEN BULGUSU: kapanmamış `<form>` / `<object>` /
                 * `<template>` / `<noscript>` girdinin KALANINI siliyordu
                 * (web sayfası yapıştırmalarında arama formu ve analitik
                 * noscript bloğu sık görülür). Tarayıcı böyle bir durumda
                 * içeriği görünür bırakır. Kapanışı olmayan etiket artık
                 * yalnızca SOYULUR; içerik normal kurallardan geçtiği için
                 * güvenlik izin listesinde durmaya devam eder. */
                if (isset($noEnd[$name]) || !$this->hasEndTag($html, $i, $name)) {
                    $noEnd[$name] = true;

                    if (isset(self::BREAKS[$name])) {
                        $gap = true;
                    }

                    continue;
                }

                $hidden      = $name;
                $hiddenDepth = 1;
                continue;
            }

            if ($textOnly) {
                if (isset(self::BREAKS[$name])) {
                    $out .= "\n";
                }

                continue;
            }

            // İzin listesinde olmayan etiket soyulur, içeriği korunur.
            if (!isset($this->tags[$name])) {
                if (isset(self::BREAKS[$name])) {
                    $gap = true;
                }

                continue;
            }

            if ($gap) {
                $gap = false;

                /* Bloktan önce ve tablo bağlamında ayırıcıya gerek yok: ikisi
                 * de kendiliğinden sınır koyar. Tablo bağlamında basmak
                 * ayrıca çıktının DEĞİŞMEZLİĞİNİ bozardı: hücre dışındaki
                 * boşluk ikinci geçişte düşer (bkz. emitText). */
                if (!isset(self::BLOCKS[$name]) && !$this->inTable($stack)) {
                    $out .= $this->gap($out);
                }
            }

            /* ELEŞTİRMEN BULGUSU (Google Docs yapıştırması): pano içeriği
             * `<b style="font-weight:normal"><p>...</p></b>` sarmalayıcısıyla
             * geliyor. `style` silindiği için `<b>` ayakta kalıyor ve
             * YAPIŞTIRILAN TÜM METİN kalın oluyordu. Blok başlarken açık
             * satır içi biçim öğeleri kapatılır ve YENİDEN AÇILMAZ; boş kalan
             * sarmalayıcı tidy() ile silinir. `a` bu kuralın dışında (bkz.
             * INLINE). */
            if (isset(self::BLOCKS[$name])) {
                while ($stack !== [] && isset(self::INLINE[$stack[count($stack) - 1]])) {
                    $out .= '</' . array_pop($stack) . '>';
                }
            }

            // Örtük kapanışlar — kötü biçimli iç içeliği normalize eder.
            // closeTo() kapsam içinde eşleşme yoksa hiçbir şey yapmaz.
            foreach (self::IMPLIED_END[$name] ?? [] as $step) {
                $out .= $this->closeTo($stack, $step[0], $step[1]);
            }

            /* ELEŞTİRMEN BULGUSU: başlık kuralı kapsam araması yapmaz,
             * yalnızca GEÇERLİ DÜĞÜMÜ denetler. Kapsam araması yapmak
             * `<h2>a<blockquote><h3>b</h3></blockquote>c</h2>` girdisinde
             * h2'yi kapatıp blockquote'u yıkıyordu. */
            if (isset(self::HEADINGS[$name]) && $stack !== []
                && isset(self::HEADINGS[$stack[count($stack) - 1]])) {
                $out .= '</' . array_pop($stack) . '>';
            }

            // Tablo bağlamında hücre dışında duran öğe: örtük hücreye alınır.
            if (!isset(self::TABLE_PARTS[$name]) && $this->inTable($stack)) {
                $out .= $this->openCell($stack);
            }

            // Eksik zorunlu atalar (table, tr, ul, dl) örtük olarak açılır.
            $out .= $this->impliedAncestors($stack, $name);

            if (count($stack) >= self::MAX_DEPTH) {
                // Sigorta devrede: etiket soyulur, metin kaybolmaz.
                continue;
            }

            $tag = $this->renderTag($name, $attrs);

            if ($tag === '') {
                continue;
            }

            $out .= $tag;

            // HTML5, void olmayan etiketlerde `/>` işaretini yok sayar:
            // `<span/>` bir span AÇAR. Aynısını yapıyoruz.
            if (!isset(self::VOID[$name])) {
                $stack[] = $name;
            }
        }

        // Kapanmamış etiketler: yığın boşaltılır. Çıktı her zaman dengelidir.
        while ($stack !== []) {
            $out .= '</' . array_pop($stack) . '>';
        }

        return $textOnly ? $out : $this->tidy($out);
    }

    /**
     * Metin parçasını çıktıya ekler.
     *
     * Ayırıcı borcunu öder ve tablo bağlamında örtük hücre açar.
     *
     * @param list<string> $stack
     */
    private function emitText(
        string $text,
        string &$out,
        array &$stack,
        bool &$gap,
        bool $textOnly
    ): void {
        if ($text === '') {
            return;
        }

        if (!$textOnly && $this->inTable($stack)) {
            /* ELEŞTİRMEN BULGUSU: tablo bağlamında hücre dışında kalan metni
             * tarayıcı tablonun ÖNÜNE taşır (foster parenting), yani bizim
             * ürettiğimiz ağaç ile tarayıcının kurduğu ağaç ayrışıyordu
             * (`<table><caption>` ve `<table>Onemli aciklama<tr>` vakaları).
             * Metni örtük bir hücreye almak hem içeriği koruyor hem de
             * çıktıyı gerçekten geçerli HTML yapıyor. Yalnızca boşluktan
             * oluşan parça hücre açmaz. */
            if (trim($text, self::SPACE) === '') {
                return;
            }

            // Hücre sınırı ayırıcı borcunu zaten karşılar.
            $gap  = false;
            $out .= $this->openCell($stack);
        } elseif ($gap) {
            $gap  = false;
            $out .= $this->gap($out);
        }

        $out .= $this->textRun($text, $textOnly);
    }

    /**
     * Soyulmuş blok etiketinin bıraktığı ayırıcı.
     *
     * Satır sonu seçildi (boşluk değil): HTML'de boşlukla aynı görünür ama
     * `BlockRenderer::rich()` satır sonlarını `<br>`e çevirdiği için
     * yapıştırılan `<div>` satırları görsel olarak da korunur.
     */
    private function gap(string $out): string
    {
        if ($out === '') {
            return '';
        }

        return strpbrk(substr($out, -1), self::SPACE) === false ? "\n" : '';
    }

    /** Yığının en üstü tablo bağlamı mı (hücre dışı)? */
    private function inTable(array $stack): bool
    {
        return $stack !== [] && isset(self::TABLE_CONTEXT[$stack[count($stack) - 1]]);
    }

    /**
     * Tablo bağlamında örtük hücre açar.
     *
     * @param list<string> $stack
     */
    private function openCell(array &$stack): string
    {
        $out = $this->impliedAncestors($stack, 'td');

        if (count($stack) >= self::MAX_DEPTH) {
            return $out;
        }

        $stack[] = 'td';

        return $out . '<td>';
    }

    /**
     * Etiketin zorunlu atalarını örtük olarak açar.
     *
     * @param list<string> $stack
     */
    private function impliedAncestors(array &$stack, string $name): string
    {
        $out = '';

        foreach (self::IMPLIED_OPEN[$name] ?? [] as $needed) {
            if ($this->available($stack, $needed) || count($stack) >= self::MAX_DEPTH) {
                continue;
            }

            $out    .= '<' . $needed[0] . '>';
            $stack[] = $needed[0];
        }

        return $out;
    }

    /**
     * Verilen adlardan biri KAPSAM İÇİNDE açık mı?
     *
     * @param list<string> $stack
     * @param list<string> $names
     */
    private function available(array $stack, array $names): bool
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (in_array($stack[$index], $names, true)) {
                return true;
            }

            // Yeni bir tablo/hücre bağlamı aramayı keser.
            if (in_array($stack[$index], self::SCOPE_BASE, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Yığını, verilen adlardan KAPSAM İÇİNDE en üstte bulunana kadar (o dahil)
     * kapatır. Ad kapsamda yoksa hiçbir şey yapmaz — sahte kapanışlar
     * (`</div>` gibi hiç açılmamış etiketler) sessizce yok sayılır.
     *
     * @param list<string> $stack
     * @param list<string> $names
     * @param list<string> $barriers Kapsam engelleri (bkz. SCOPE_*)
     */
    private function closeTo(array &$stack, array $names, array $barriers): string
    {
        $found = null;

        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (in_array($stack[$index], $names, true)) {
                $found = $index;
                break;
            }

            /* Kapsam engeli. ELEŞTİRMEN BULGUSU: engelsiz arama iç içe
             * listeleri ve iç içe tabloları yıkıyordu. */
            if (in_array($stack[$index], $barriers, true)) {
                return '';
            }
        }

        if ($found === null) {
            return '';
        }

        $out = '';

        while (count($stack) > $found) {
            $out .= '</' . array_pop($stack) . '>';
        }

        return $out;
    }

    /**
     * Ham metin (RAWTEXT/RCDATA) içeriğinin sınırlarını bulur.
     *
     * "Uygun bitiş etiketi" kuralı: `</ad` ancak ardından BOŞLUK, `/` ya da
     * `>` gelirse kapatır.
     *
     * ELEŞTİRMEN BULGUSU: eski kod bitiş etiketini `readName()` + ilk `>`
     * ile arıyordu. Bu yüzden `</style=1>` bizde style'ı KAPATIYOR, tarayıcıda
     * kapatmıyordu; `<style>`/`<script>`/`<title>` gövdesinin kalanı sayfada
     * görünür metne dönüşüyor ve içindeki işaretleme HTML olarak işleniyordu.
     * Bitiş etiketinin öznitelikleri de çözümlenir: `</style a=">">` TEK bir
     * belirteçtir.
     *
     * @return array{0: int, 1: int} [içerik sonu, çözümlemenin süreceği konum]
     */
    private function rawText(string $html, int $from, string $name): array
    {
        $length = strlen($html);
        $search = $from;

        while (true) {
            $at = stripos($html, '</' . $name, $search);

            if ($at === false) {
                /* Kapanış yok: ham metin öğesinde EOF, tarayıcıda da içeriğin
                 * tamamını yutar (CSS/JS gövdesi ekranda görünmez). */
                return [$length, $length];
            }

            $after = $at + 2 + strlen($name);
            $char  = $html[$after] ?? '';

            if ($char === '' || !str_contains(self::NAME_STOP, $char)) {
                $search = $at + 2;
                continue;
            }

            $tagEnd    = 0;
            $selfClose = false;
            $closed    = false;
            $this->readAttributes($html, $after, $tagEnd, $selfClose, $closed);

            return [$at, $closed ? $tagEnd : $length];
        }
    }

    /**
     * 'nest' türü için: uygun bir kapanış etiketi girdide GERÇEKTEN var mı?
     */
    private function hasEndTag(string $html, int $from, string $name): bool
    {
        $search = $from;

        while (($at = stripos($html, '</' . $name, $search)) !== false) {
            $after = $at + 2 + strlen($name);
            $char  = $html[$after] ?? '';

            if ($char !== '' && str_contains(self::NAME_STOP, $char)) {
                return true;
            }

            $search = $at + 2;
        }

        return false;
    }

    /**
     * `<!` ile başlayan yapıyı atlar ve bittiği konumu döndürür.
     *
     * `-->` araması `$start + 2`den başlar: HTML5'e göre `<!-->` ve `<!--->`
     * de tamamlanmış yorumlardır. `$start + 4`ten arasak bu iki girdide
     * kapanış bulunamaz ve belgenin kalanı sessizce yutulurdu.
     *
     * ELEŞTİRMEN BULGUSU: HTML5'te `--!>` de geçerli bir yorum kapanışıdır.
     * Yalnızca `-->` arandığı için `<!-- yorum --!><p>iki</p>` girdisinde
     * belgenin kalanı atılıyordu.
     */
    private function skipComment(string $html, int $start, int $length): int
    {
        if (substr($html, $start, 4) === '<!--') {
            $close = strpos($html, '-->', $start + 2);
            $bang  = strpos($html, '--!>', $start + 2);

            if ($bang !== false && ($close === false || $bang < $close)) {
                return $bang + 4;
            }

            return $close === false ? $length : $close + 3;
        }

        $close = strpos($html, '>', $start);

        return $close === false ? $length : $close + 1;
    }

    /**
     * Etiket adını okur ve küçük harfe çevirir.
     *
     * @param int $end Ad bittikten sonraki konum (çıkış parametresi)
     */
    private function readName(string $html, int $position, int &$end): string
    {
        $count = strcspn($html, self::NAME_STOP, $position);
        $end   = $position + $count;

        return $count === 0 ? '' : strtolower(substr($html, $position, $count));
    }

    /** Eski etiket adını modern karşılığına çevirir. */
    private function alias(string $name): string
    {
        return self::ALIAS[$name] ?? $name;
    }

    /**
     * `pre` ya da `code` içinde miyiz (kod örneği bağlamı)?
     *
     * @param list<string> $stack
     */
    private function literal(array $stack): bool
    {
        foreach ($stack as $open) {
            if ($open === 'pre' || $open === 'code') {
                return true;
            }
        }

        return false;
    }

    /**
     * Etiketin özniteliklerini okur.
     *
     * HTML5 tarayıcı kurallarına yakın davranır: tırnaksız değer, boolean
     * öznitelik, başıboş `/`, tırnağı kapanmamış değer. Aynı ad iki kez
     * geçerse tarayıcılar gibi İLK değer kazanır.
     *
     * @param int  $position  Etiket adından sonraki konum
     * @param int  $end       Etiketin bittiği konum (çıkış parametresi)
     * @param bool $selfClose `/>` ile kapandı mı (çıkış parametresi)
     * @param bool $closed    Etiket `>` ile gerçekten bitti mi; false ise girdi
     *                        etiketin ortasında tükendi (çıkış parametresi)
     * @return array<string, string> Ham (kaçırılmamış, çözülmemiş) değerler
     */
    private function readAttributes(
        string $html,
        int $position,
        int &$end,
        bool &$selfClose,
        bool &$closed
    ): array {
        $length    = strlen($html);
        $attrs     = [];
        $selfClose = false;
        $closed    = false;

        while ($position < $length) {
            $position += strspn($html, self::SPACE, $position);

            if ($position >= $length) {
                break;
            }

            $char = $html[$position];

            if ($char === '>') {
                $end    = $position + 1;
                $closed = true;

                return $attrs;
            }

            if ($char === '/') {
                if (($html[$position + 1] ?? '') === '>') {
                    $selfClose = true;
                    $end       = $position + 2;
                    $closed    = true;

                    return $attrs;
                }

                $position++;
                continue;
            }

            $nameLength = strcspn($html, self::SPACE . '/>=', $position);

            if ($nameLength === 0) {
                // Baştaki `=` gibi bir durum: sonsuz döngüyü önlemek için yut.
                $position++;
                continue;
            }

            $name      = strtolower(substr($html, $position, $nameLength));
            $position += $nameLength;
            $value     = '';

            $probe  = $position + strspn($html, self::SPACE, $position);

            if (($html[$probe] ?? '') === '=') {
                $probe++;
                $probe += strspn($html, self::SPACE, $probe);
                $quote  = $html[$probe] ?? '';

                if ($quote === '"' || $quote === "'") {
                    $close = strpos($html, $quote, $probe + 1);

                    if ($close === false) {
                        // Tırnak kapanmamış: girdinin sonuna kadar değerdir.
                        $value    = substr($html, $probe + 1);
                        $position = $length;
                    } else {
                        $value    = substr($html, $probe + 1, $close - $probe - 1);
                        $position = $close + 1;
                    }
                } else {
                    $valueLength = strcspn($html, self::SPACE . '>', $probe);
                    $value       = substr($html, $probe, $valueLength);
                    $position    = $probe + $valueLength;
                }
            } else {
                $position = $probe;
            }

            if (!array_key_exists($name, $attrs)) {
                $attrs[$name] = $value;
            }
        }

        $end = $length;

        return $attrs;
    }

    /* ---------------------------------------------------------------------
     * Yeniden yazım
     * ------------------------------------------------------------------ */

    /**
     * Açılış etiketini kanonik biçimde yeniden yazar.
     *
     * Öznitelikler GİRDİ sırasıyla değil izin listesi sırasıyla basılır;
     * böylece çıktı aynı girdi için her zaman aynıdır ve temizleyici
     * kendi çıktısı üzerinde değişmez (idempotent) davranır.
     *
     * @param array<string, string> $attrs
     * @return string Boş dizge → etiket tamamen düşer
     */
    private function renderTag(string $name, array $attrs): string
    {
        $allowed = $this->tags[$name];

        if ($allowed === []) {
            return '<' . $name . '>';
        }

        $clean = [];

        foreach ($allowed as $attribute) {
            if (!array_key_exists($attribute, $attrs)) {
                continue;
            }

            $value = $this->attributeValue($name, $attribute, $attrs[$attribute]);

            if ($value !== null) {
                $clean[$attribute] = $value;
            }
        }

        if ($name === 'img') {
            // Kaynağı olmayan görsel anlamsızdır; şema doğrulaması src'yi
            // düşürdüyse etiketin tamamı gider.
            if (!isset($clean['src'])) {
                return '';
            }

            // Erişilebilirlik: alt her zaman basılır (boş alt "süsleme" demektir).
            $clean['alt'] ??= '';
        }

        /* ELEŞTİRMEN BULGUSU: şeması reddedilen bağlantı (ftp:, sms:, geo:,
         * whatsapp:, magnet:) ÖLÜ bir `<a>` olarak kalıyordu — kullanıcıya
         * hiçbir işaret vermeyen, tıklanabilir görünen ama çalışmayan bir
         * öğe. Artık etiket düşer, metni kalır: yazar bağlantının kabul
         * edilmediğini sayfada görür. */
        if ($name === 'a' && !isset($clean['href'])) {
            return '';
        }

        if ($name === 'a' && ($clean['target'] ?? '') === '_blank') {
            /* Sekme hırsızlığı (tabnabbing): `_blank` ile açılan sayfa
             * `window.opener` üzerinden bizi başka bir adrese yönlendirebilir.
             * Eski tarayıcılar `_blank` için noopener'ı örtük uygulamaz. */
            $clean['rel'] = $this->relTokens(($clean['rel'] ?? '') . ' noopener');
        }

        $html = '<' . $name;

        foreach ($allowed as $attribute) {
            if (!isset($clean[$attribute])) {
                continue;
            }

            $html .= ' ' . $attribute . '="'
                . htmlspecialchars($clean[$attribute], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html . '>';
    }

    /**
     * Öznitelik değerini doğrular.
     *
     * Değer buraya HAM gelir; çözme (entity açma) burada, öznitelik türüne
     * göre yapılır. Sıra kritik olduğu için tek yerde toplandı — bkz.
     * `decodeControlRefs()`.
     *
     * @return string|null null → öznitelik düşer
     */
    private function attributeValue(string $tag, string $attribute, string $raw): ?string
    {
        if (in_array($attribute, self::URL_ATTRS, true)) {
            /* ELEŞTİRMEN BULGUSU: URL doğrulaması yalnızca `href`/`src`
             * adlarına bağlıydı; kurucuyla eklenen `formaction`, `poster`,
             * `ping`, `background` gibi adlar düz metin dalına düşüp
             * `javascript:` değerini aynen basıyordu. Ad ne olursa olsun
             * URL taşıyan her öznitelik buradan geçer.
             *
             * Çözme SIRASI: sayısal denetim başvuruları entity çözmeden ÖNCE
             * açılır. Tersi sırada `&amp;#10;` gibi KAÇIRILMIŞ bir metin
             * (tarayıcı onu `&#10;` harfleri olarak görür) çözülüp gerçek
             * satır sonuna dönüşüyor ve URL sessizce bozuluyordu
             * (`/x?a=1&amp;#10;b` → `/x?a=1b`). */
            $url = $this->safeUrl(
                $this->decode($this->decodeControlRefs($raw)),
                $tag === 'img' && $attribute === 'src'
            );

            return $url === '' ? null : $url;
        }

        $value = $this->decode($raw);

        switch ($attribute) {
            case 'target':
                // `_parent` ve `_top` çerçeve kırma amacıyla kullanılabilir.
                $target = strtolower(trim($value));

                return in_array($target, ['_blank', '_self'], true) ? $target : null;

            case 'rel':
                $rel = $this->relTokens($value);

                return $rel === '' ? null : $rel;

            case 'width':
            case 'height':
                $number = trim($value);

                return preg_match('/^\d{1,5}$/', $number) === 1 && (int) $number > 0
                    ? (string) (int) $number
                    : null;

            case 'loading':
                $loading = strtolower(trim($value));

                return in_array($loading, ['lazy', 'eager'], true) ? $loading : null;

            case 'decoding':
                $decoding = strtolower(trim($value));

                return in_array($decoding, ['async', 'sync', 'auto'], true) ? $decoding : null;

            case 'title':
            case 'alt':
            case 'datetime':
                // Çekirdeğin bildiği düz metin öznitelikleri.
                return $this->plainAttribute($value);

            default:
                /* Kurucuyla gelen bilinmeyen öznitelik: düz metin. URL_ATTRS
                 * listesinde olmayan ama URL benzeri değer taşıyan bir ad
                 * bulunursa (yeni bir HTML özniteliği, satıcıya özel bir ad)
                 * tehlikeli şema yine de basılmaz. Bu denetim çekirdeğin
                 * kendi title/alt alanlarına UYGULANMAZ: orada "javascript:
                 * nedir" gibi meşru bir metin olabilir. */
                if (preg_match('/^[\s\x00-\x20]*(?:' . self::BAD_SCHEMES . ')\s*:/i', $value) === 1) {
                    return null;
                }

                return $this->plainAttribute($value);
        }
    }

    /**
     * Düz metin öznitelik değeri: satır sonları boşluğa çevrilir, uzunluk
     * sınırlanır.
     */
    private function plainAttribute(string $value): ?string
    {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') > self::MAX_ATTR) {
            $text = mb_substr($text, 0, self::MAX_ATTR, 'UTF-8');

            /* ELEŞTİRMEN BULGUSU: kırpma kod noktası bazlı olduğu için emoji
             * grafem kümesini ortadan kesip sonda sarkan bir ZWJ bırakıyordu
             * (`👨‍👩‍👦` → `👨‍`). Sarkan birleştirici karakterler atılır. */
            $text = (string) preg_replace(
                '/[\x{200D}\x{FE00}-\x{FE0F}\x{20D0}-\x{20F0}\p{Mn}\p{Me}]+$/u',
                '',
                $text
            );
        }

        return $text === '' ? null : $text;
    }

    /**
     * URL doğrulaması.
     *
     * Kabul edilenler:
     *   - http, https, mailto, tel şemaları (kurucuyla genişletilebilir)
     *   - `/` ile başlayan aynı site yolları
     *   - `#çıpa`, `?sorgu` ve şema içermeyen göreli yollar
     *   - `<img src>` için yalnızca raster `data:image/...;base64,` adresleri
     *
     * @param bool $imageData Gömülü görsel verisi kabul edilsin mi
     * @return string Boş dizge → değer reddedildi
     */
    private function safeUrl(string $value, bool $imageData = false): string
    {
        /* Tarayıcılar URL'nin içindeki C0 denetim karakterlerini ve DEL'i yok
         * sayar; şemayı gizlemek için kullanılırlar: "java\tscript:alert(1)"
         * tarayıcıda javascript: olarak okunur. Bu yüzden şema denetiminden
         * ÖNCE atılırlar. (Buradaki silme yalnızca öznitelik değerine özeldir;
         * metin gövdesindeki \n ve \t korunur.) */
        $url = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);

        // ASCII DIŞI görünmez/boşluk karakterleri (bkz. URL_BLANKS).
        $url = trim((string) (preg_replace(self::URL_BLANKS, '', $url) ?? $url));

        if ($url === '') {
            return '';
        }

        /* ELEŞTİRMEN BULGUSU: `//` reddi yalnızca şema YOKKEN çalışıyordu.
         * WHATWG çözümleyicisi özel şemalarda ters bölüyü bölüye çevirdiği
         * için `https:/\evil.test`, `http:\\evil.test` ve
         * `http://evil.test\@ok.test/` gibi değerler denetimi atlıyordu.
         * URL'de ham ters bölünün meşru kullanımı yoktur (kodlanmışı `%5C`),
         * bu yüzden içinde ters bölü geçen değer tümüyle reddedilir. */
        if (str_contains($url, '\\')) {
            return '';
        }

        // Gömülü görsel verisi: yalnızca img src'de ve yalnızca raster biçim.
        if ($imageData && stripos($url, 'data:image/') === 0) {
            /* base64 alfabesinde boşluk yoktur; e-posta/Word yapıştırmalarında
             * satır kaydırmasından kalan boşluklar temizlenir. Satır sonları
             * yukarıdaki C0 adımında zaten atıldı. Boşluk atmak bir atlatma
             * açamaz: sonuç yine katı IMAGE_DATA kalıbından geçmek zorunda. */
            $candidate = str_replace(' ', '', $url);

            if (preg_match(self::IMAGE_DATA, $candidate) === 1) {
                return $candidate;
            }
        }

        /* Son güvenlik ağı: hangi tuhaf önek kalırsa kalsın, değerin başında
         * tehlikeli bir şema DURUYORSA reddedilir. Tarayıcı bunu çalıştırmaz
         * ama kayıtlı ve görünür bir `javascript:` dizgesi bırakmak da
         * temizleyicinin kendi iddiasını çiğner. */
        if (preg_match('/^[^a-z0-9\/?#]{0,8}(?:' . self::BAD_SCHEMES . ')\s*:/iu', $url) === 1) {
            return '';
        }

        /* Şema-göreli adres (`//cdn.example.test/logo.png`).
         *
         * ELEŞTİRMEN BULGUSU: eski içerikte çok yaygın olan bu biçim
         * reddediliyor, `<img>` etiketinin TAMAMI düşüyor, `<a>` ise ölü
         * kalıyordu. Güvenlik açısından bir kayıp yok — `https://cdn...`
         * zaten izinli — bu yüzden reddetmek yerine https'e YÜKSELTİLİR.
         * Böylece görev metnindeki "çıktı `//` ile başlamaz" kuralı da
         * korunur. Fazla bölüler de aynı yere çıkar (`///host` tarayıcıda
         * `https://host`), onlar da aynı biçime indirgenir. */
        if (preg_match('#^/{2,}#', $url) === 1) {
            $rest = ltrim($url, '/');

            if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
                return '';
            }

            return 'https://' . $rest;
        }

        // Şema varsa izin listesinde olmalı. Göreli yolda ilk bölütte iki
        // nokta bulunması tarayıcı için de şemadır; o da buraya düşer.
        if (preg_match('#^([a-z][a-z0-9+.\-]*)\s*:#i', $url, $match) !== 1) {
            return $url;
        }

        $scheme = strtolower($match[1]);

        if (!in_array($scheme, $this->schemes, true)) {
            return '';
        }

        /* http/https "özel şema"dır: WHATWG çözümleyicisi şemadan sonraki
         * eğik çizgi sayısını yutar, yani `http:evil.test/x` ve
         * `https:///evil.test/x` tarayıcıda `//evil.test`e gider. Değeri
         * kanonik `şema://` biçimine indirgiyoruz; böylece kaynakta görünen
         * adres ile tarayıcının gittiği adres aynı oluyor. */
        if ($scheme === 'http' || $scheme === 'https') {
            if (preg_match('#^(https?):/*(.*)$#is', $url, $parts) !== 1) {
                return '';
            }

            $rest = $parts[2];

            if ($rest === '' || $rest[0] === '?' || $rest[0] === '#' || $rest[0] === '/') {
                return '';
            }

            return strtolower($parts[1]) . '://' . $rest;
        }

        return $url;
    }

    /**
     * PHP'nin çözmeyi REDDETTİĞİ sayısal başvuruları gerçek karaktere çevirir.
     *
     * `html_entity_decode()`, C0 denetim karakterlerine karşılık gelen sayısal
     * başvuruları (`&#14;`, `&#x0E;`) HTML'de geçersiz saydığı için OLDUĞU GİBİ
     * bırakır. Tarayıcılar ise onları çözer. Bu, bir şema gizleme yolu açar:
     *
     *     <a href="&#14;javascript:alert(1)">
     *
     * Bizim gözümüzde `&` ile başlayan göreli bir yoldur (şema yok, kabul);
     * tarayıcının gözünde `\x0Ejavascript:` yani — URL çözümleyici denetim
     * karakterini attıktan sonra — `javascript:` şemasıdır.
     *
     * DİKKAT: bu dönüşüm `html_entity_decode()`tan ÖNCE yapılır. Sonra
     * yapıldığında, kaçırılmış (`&amp;#10;`) bir metin çözülüp gerçek denetim
     * karakterine dönüşüyor ve safeUrl() onu atınca meşru URL sessizce
     * bozuluyordu — ELEŞTİRMEN BULGUSU. Önce yapıldığında `&amp;#10;` içinde
     * `&#` dizisi bulunmadığı için kalıba hiç uğramaz ve metin olarak korunur.
     *
     * Noktalı virgül isteğe bağlı bırakıldı: tarayıcılar öznitelik
     * değerlerinde noktalı virgülsüz sayısal başvuruyu da tüketir.
     */
    private function decodeControlRefs(string $value): string
    {
        if (!str_contains($value, '&#')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/&#(?:x([0-9a-f]{1,6})|([0-9]{1,7}));?/i',
            static function (array $match): string {
                $hex  = $match[1] ?? '';
                $code = $hex !== '' ? (int) hexdec($hex) : (int) ($match[2] ?? '0');

                // Sadece PHP'nin bırakıp tarayıcının çözdüğü aralık.
                if ($code < 0x20 || $code === 0x7F) {
                    return chr($code);
                }

                return $match[0];
            },
            $value
        );
    }

    /**
     * `rel` değerini izinli belirteçlere indirger, sırayı ve tekilliği korur.
     */
    private function relTokens(string $value): string
    {
        $tokens = [];

        foreach (preg_split('/\s+/', strtolower(trim($value))) ?: [] as $token) {
            if ($token !== '' && in_array($token, self::REL, true) && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * Metin parçasını basar.
     *
     * Entity'ler ÖNCE çözülür, sonra yeniden kaçırılır. Bu sıra iki işi
     * birden yapar: `&amp;` çift kaçışa uğrayıp `&amp;amp;` olmaz (çıktı
     * değişmezdir) ve `&lt;script&gt;` gibi zararsız metin olduğu gibi kalır.
     *
     * Kaçış ENT_NOQUOTES ile yapılır: metin gövdesinde tırnak kaçırmak
     * gereksizdir ve "HiCMS'in" gibi Türkçe metinleri `&#039;` çöplüğüne
     * çevirir. Öznitelik değerlerinde ise ENT_QUOTES kullanılır.
     */
    private function textRun(string $text, bool $textOnly): string
    {
        if ($text === '') {
            return '';
        }

        $text = $this->decode($text);

        if ($textOnly) {
            return $text;
        }

        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Bölünmez boşluk kaynakta görünür kalsın (editörler bolca üretir).
        return str_replace("\u{00A0}", '&nbsp;', $text);
    }

    /** Entity'leri çözer ve çözüldükten sonra görünmez karakterleri atar. */
    private function decode(string $value): string
    {
        return str_replace(
            self::INVISIBLE,
            '',
            html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    /**
     * Boş kalan satır içi biçim öğelerini siler.
     *
     * Google Docs sarmalayıcısı (`<b style="font-weight:normal">`) blok
     * başında kapatıldığı için geride `<b></b>` kalıyor. Boş biçim öğesinin
     * hiçbir görsel etkisi yok ama kaynağı kirletir ve `clean()` çıktısının
     * kanonik olmasını bozar.
     */
    private function tidy(string $html): string
    {
        if (!str_contains($html, '></')) {
            return $html;
        }

        $pattern = '#<(' . implode('|', array_keys(self::INLINE)) . ')></\1>#';

        for ($round = 0; $round < 4; $round++) {
            $next = (string) preg_replace($pattern, '', $html);

            if ($next === $html) {
                break;
            }

            $html = $next;
        }

        return $html;
    }

    /**
     * Girdiyi çözümlemeye hazırlar: geçerli UTF-8'e indirger ve görünmez
     * karakterleri atar. NUL'ün etiket adı içinde saklanmaması için bu adım
     * tarayıcıdan ÖNCE gelir.
     */
    private function normalize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Bozuk UTF-8 dizileri: çıktı geçerli UTF-8 olmak zorunda, yoksa
        // htmlspecialchars() ENT_SUBSTITUTE ile parçaları sessizce yer.
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = $this->repairUtf8($html);
        }

        /* NUL SİLİNMEZ, U+FFFD'ye çevrilir — tarayıcı çözümleyicisi de öyle
         * yapar. ELEŞTİRMEN BULGUSU: silindiğinde `<style>x{}</style\0>SIZAN{}`
         * girdisinde biz `</style>` görüp ham metni kapatıyor, tarayıcı ise
         * adı `style\u{FFFD}` okuyup kapatmıyordu; CSS gövdesi bizde görünür
         * metne dönüşüyordu. Çevirme, `<scr\0ipt>` gizlemesini de aynı şekilde
         * bozar: ad artık `scr\u{FFFD}ipt`tir, izin listesinde yoktur. */
        $html = str_replace("\0", "\u{FFFD}", $html);

        return str_replace(self::INVISIBLE, '', $html);
    }

    /**
     * Bozuk UTF-8'i onarır.
     *
     * ELEŞTİRMEN BULGUSU: `mb_convert_encoding($h, 'UTF-8', 'UTF-8')` geçersiz
     * her baytı `?` yapıyordu ve karakter geri gelmiyordu (`a\xFFc` → `a?c`).
     * Burada GEÇERLİ UTF-8 dizileri olduğu gibi bırakılır — yani ç ğ ı İ ö ş ü
     * tanım gereği korunur — yalnızca dizi kalıbına uymayan tek baytlar
     * Windows-1252 sayılıp çevrilir (`\xFF` → `ÿ`). Latin-1/CP1252
     * yapıştırmalarının kurtarılabilen kısmı böylece kurtulur.
     */
    private function repairUtf8(string $html): string
    {
        $utf8 = '/[\x00-\x7F]+'
            . '|[\xC2-\xDF][\x80-\xBF]'
            . '|\xE0[\xA0-\xBF][\x80-\xBF]'
            . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]'
            . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            . '|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2}'
            . '|(.)/s';

        return (string) preg_replace_callback(
            $utf8,
            static function (array $match): string {
                if (($match[1] ?? '') === '') {
                    return $match[0];
                }

                return (string) mb_convert_encoding($match[1], 'UTF-8', 'Windows-1252');
            },
            $html
        );
    }

    /** ASCII harf mi (etiket adı başlangıcı). */
    private function isLetter(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z');
    }
}
