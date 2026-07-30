<?php

declare(strict_types=1);

/**
 * HiCMS — veritabanısız duman testi
 *
 * MySQL gerektirmeyen her şeyi gerçekten çalıştırır: önyükleme, blok işleme,
 * rota eşleştirme, içerik türü kaydı, kaçış, şema SQL üretimi ve gereksinim
 * denetimi.
 *
 * Kullanım: php build/smoke.php
 */

$root = dirname(__DIR__);

require $root . '/src/Autoloader.php';

HiCMS\Autoloader::register($root . '/src');

use HiCMS\Content\BlockRegistry;
use HiCMS\Content\ContentType;
use HiCMS\Content\TypeRegistry;
use HiCMS\Database\Blueprint;
use HiCMS\Events\Dispatcher;
use HiCMS\Events\Render\BlockRendering;
use HiCMS\Http\Request;
use HiCMS\Http\Router;
use HiCMS\Install\Installer;
use HiCMS\Install\Requirements;
use HiCMS\Kernel;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Html;
use HiCMS\Support\Str;

$passed = 0;
$failed = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        printf("  ok   %s\n", $label);

        return;
    }

    $failed[] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  FAIL %s%s\n", $label, $detail !== '' ? ' — ' . $detail : '');
}

echo "HiCMS duman testi\n" . str_repeat('-', 58) . "\n\n";

/* -------------------------------------------------------------------------
 * 1. Kaçış ve metin yardımcıları
 * ---------------------------------------------------------------------- */

echo "Metin ve kaçış\n";

check('HTML kaçışı', Str::html('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;');
check('javascript: şeması reddedilir', Str::url('javascript:alert(1)') === '');
check('https adresi korunur', Str::url('https://a.test/x?y=1&z=2') === 'https://a.test/x?y=1&amp;z=2');
check('Türkçe slug', Str::slug('Çığır Açan Şeyler') === 'cigir-acan-seyler',
    Str::slug('Çığır Açan Şeyler'));
check('Yüzde işareti bozulmaz', Str::format('%%100 hazır: %d adet', 5) === '%100 hazır: 5 adet',
    Str::format('%%100 hazır: %d adet', 5));
check('Tek yüzde kaçırılır', Str::format('%100 hazır') === '%100 hazır', Str::format('%100 hazır'));
check('safeHtml betiği siler',
    !str_contains(Str::safeHtml('<p onclick="x()">a</p><script>b()</script>'), 'script'));
check('safeHtml olay özniteliğini siler',
    !str_contains(Str::safeHtml('<p onclick="x()">a</p>'), 'onclick'));
check('Baş harfler', Str::initials('raşidin buğda') === 'RB', Str::initials('raşidin buğda'));
check('Bayt biçimi', Str::bytes(1536) === '1.5 KB', Str::bytes(1536));
check('Türkçe tarih', Dates::format('2026-07-24 10:00:00', 'j F Y') === '24 Temmuz 2026',
    Dates::format('2026-07-24 10:00:00', 'j F Y'));

/* -------------------------------------------------------------------------
 * 2. HTML temizleyici
 *
 * Bu bölüm ÇEKİRDEK YÜKLENMEDEN ÖNCE çalışır (Kernel::boot() 8. bölümde) ve
 * VERİTABANI GEREKTİRMEZ: temizleyicinin çekirdekten bağımsız olması bir
 * tasarım şartıdır, aşağıda hem kaynak hem çalışma zamanı denetimiyle
 * doğrulanır.
 *
 * Denetimlerin çoğu, üç bağımsız saldırgan taramasının bulduğu belirli bir
 * ATLATMAYI ya da BOZULAN MEŞRU İÇERİĞİ kilitler. Bir denetim düşerse
 * src/Support/Html.php içindeki "ELEŞTİRMEN BULGUSU" yorumlu satırlardan biri
 * kaldırılmış demektir.
 * ---------------------------------------------------------------------- */

echo "\nHTML temizleyici\n";

$clean = static fn(string $html): string => Html::clean($html);

/* ---- çekirdekten bağımsızlık ---- */

check('Temizleyici Kernel yüklemeden çalışır',
    $clean('<p>a</p>') === '<p>a</p>' && !class_exists('HiCMS\Kernel', false));

// Kaynakta da bağımlılık olmamalı: yorumlar ayıklanıp gerçek kod taranır.
$htmlCode = '';

foreach (token_get_all((string) file_get_contents($root . '/src/Support/Html.php')) as $token) {
    if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
        continue;
    }

    $htmlCode .= is_array($token) ? $token[1] : $token;
}

check('Temizleyici kaynağında çekirdek çağrısı yok',
    preg_match('/\b(?:Kernel|Dispatcher|hi_filter)\b|(?<![\w$>])hi\s*\(/', $htmlCode) !== 1);

/* ---- kapatılan atlatmalar ---- */

check('URL başındaki U+00A0 ile javascript: gizlenemez',
    $clean('<a href="&nbsp;javascript:alert(1)">Fatura</a>') === 'Fatura',
    $clean('<a href="&nbsp;javascript:alert(1)">Fatura</a>'));
check('URL başındaki U+00A0 ile vbscript: gizlenemez',
    $clean('<a href="&#160;vbscript:msgbox(1)">x</a>') === 'x');
check('URL başındaki U+200B ile javascript: gizlenemez',
    $clean("<a href=\"\u{200B}javascript:alert(1)\">x</a>") === 'x');
check('Görünmez önekli data: img etiketi düşer',
    $clean('<img src="&nbsp;data:image/svg+xml,<svg onload=alert(1)>" alt="x">') === '');
check('Str::safeHtml aynı yolu kullanır',
    Str::safeHtml('<a href="&nbsp;javascript:alert(1)">x</a>') === 'x');
check('Sayısal denetim başvurusuyla şema gizlenemez',
    $clean('<a href="&#14;javascript:alert(1)">x</a>') === 'x');
check('Ters bölülü şema-göreli adres reddedilir',
    $clean('<a href="https:/\evil.test/x">x</a>') === 'x',
    $clean('<a href="https:/\evil.test/x">x</a>'));
check('Çift ters bölülü adres reddedilir',
    $clean('<a href="http:\\\\evil.test/x">x</a>') === 'x');
check('Ters bölülü kullanıcı adı hilesi reddedilir',
    $clean('<a href="http://evil.test\@ok.test/">x</a>') === 'x');
check('Eğik çizgisiz http şeması kanonikleşir',
    $clean('<a href="http:evil.test/x">x</a>') === '<a href="http://evil.test/x">x</a>',
    $clean('<a href="http:evil.test/x">x</a>'));
check('Fazla eğik çizgi kanonikleşir',
    $clean('<a href="///evil.test/x">x</a>') === '<a href="https://evil.test/x">x</a>',
    $clean('<a href="///evil.test/x">x</a>'));

check('</style=1> ham metni kapatmaz',
    $clean('<style>x{}</style=1><a href="/giris">Hesabinizi dogrulayin</a></style>') === '',
    $clean('<style>x{}</style=1><a href="/giris">Hesabinizi dogrulayin</a></style>'));
check('</script=1> ham metni kapatmaz',
    $clean('<script>var a=1;</script=1>alert(9)+document.cookie</script>') === '');
check('</title=1> ham metni kapatmaz',
    $clean('<title>Gizli</title=1>SIZAN BASLIK</title>') === '');
check('</svg=1> yabancı içeriği kapatmaz',
    $clean('<svg><circle/></svg=1><b>SIZAN</b></svg>') === '');
check('NUL ile ham metin kapanışı taklit edilemez',
    $clean("<style>x{}</style\0>SIZAN{}</style>") === '',
    $clean("<style>x{}</style\0>SIZAN{}</style>"));
check('Bitiş etiketi öznitelikleri çözümlenir (ham metin)',
    $clean('<style>a{}</style a=">">devam') === 'devam',
    $clean('<style>a{}</style a=">">devam'));
check('Bitiş etiketi öznitelikleri çözümlenir (blok)',
    $clean('<p>bir</p attr="x>y">iki') === '<p>bir</p>iki',
    $clean('<p>bir</p attr="x>y">iki'));
check('Kapanış etiketinden metin sızmaz',
    $clean('<p>a</p x="onclick=alert(1)>sizan metin">son') === '<p>a</p>son',
    $clean('<p>a</p x="onclick=alert(1)>sizan metin">son'));

check('Atasız tablo satırı table ile sarılır',
    $clean('<tr><td>Ocak</td><td>1500</td></tr><tr><td>Subat</td><td>1700</td></tr>')
        === '<table><tr><td>Ocak</td><td>1500</td></tr><tr><td>Subat</td><td>1700</td></tr></table>',
    $clean('<tr><td>Ocak</td><td>1500</td></tr>'));
check('Atasız hücre tam zincirle sarılır',
    $clean('<th>Baslik</th>') === '<table><tr><th>Baslik</th></tr></table>',
    $clean('<th>Baslik</th>'));
check('Tablo bağlamındaki metin hücreye alınır',
    $clean('<table>Onemli aciklama<tr><td>A</td></tr></table>')
        === '<table><tr><td>Onemli aciklama</td></tr><tr><td>A</td></tr></table>',
    $clean('<table>Onemli aciklama<tr><td>A</td></tr></table>'));
check('Tablo bağlamındaki satır içi öğe hücreye alınır',
    $clean('<table><b>foster</b><tr><td>h</td></tr></table>')
        === '<table><tr><td><b>foster</b></td></tr><tr><td>h</td></tr></table>',
    $clean('<table><b>foster</b><tr><td>h</td></tr></table>'));
check('İç içe bağlantı kardeşe ayrılır',
    $clean('<a href="/1">bir<a href="/2">iki</a></a>')
        === '<a href="/1">bir</a><a href="/2">iki</a>',
    $clean('<a href="/1">bir<a href="/2">iki</a></a>'));

// Genişletme yolu: URL taşıyan her öznitelik ad ne olursa olsun doğrulanır.
$extended = new Html(
    ['button' => ['formaction'], 'video' => ['src', 'poster', 'onended', 'style']],
    ['javascript', 'data', 'ftp']
);

check('formaction şeması denetlenir',
    $extended->sanitize('<button formaction="javascript:alert(1)">g</button>') === '<button>g</button>',
    $extended->sanitize('<button formaction="javascript:alert(1)">g</button>'));
check('poster şeması denetlenir',
    $extended->sanitize('<video poster="javascript:alert(1)" src="/v.mp4">v</video>')
        === '<video src="/v.mp4">v</video>',
    $extended->sanitize('<video poster="javascript:alert(1)" src="/v.mp4">v</video>'));
check('Genişletme on* ve style açamaz',
    $extended->sanitize('<video src="/v.mp4" onended="x" style="y">v</video>')
        === '<video src="/v.mp4">v</video>');
check('Tehlikeli şema genişletmeyle eklenemez',
    $extended->sanitize('<a href="javascript:alert(1)">x</a>') === 'x');
check('Meşru ek şema eklenebilir',
    $extended->sanitize('<a href="ftp://a.test/x">f</a>') === '<a href="ftp://a.test/x">f</a>');

// Paragrafa bölme temizleyicinin dengeli çıktı güvencesini bozmamalı.
$richRenderer = new HiCMS\Content\BlockRenderer(
    new BlockRegistry(),
    new HiCMS\Repository\MediaRepository(new HiCMS\Database\Connection([])),
    new HiCMS\Http\Url('https://site.test'),
    new Dispatcher()
);

check('rich() paragrafa bölerken dengeyi korur',
    $richRenderer->rich("<mark>ilk paragraf\n\nikinci paragraf</mark>")
        === '<p><mark>ilk paragraf</mark></p><p>ikinci paragraf</p>',
    $richRenderer->rich("<mark>ilk paragraf\n\nikinci paragraf</mark>"));

/* ---- onarılan meşru içerik ---- */

$pastedImage = '<p>Ekran:</p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==" alt="ekran">';

check('Panodan yapıştırılan gömülü görsel korunur', $clean($pastedImage) === $pastedImage,
    $clean($pastedImage));
check('data:image/svg+xml reddedilir',
    $clean('<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" alt="x">') === '');
check('Bozuk base64 gövdesi reddedilir',
    $clean('<img src="data:image/png;base64,iV<script>" alt="a">') === '');
check('Şema-göreli görsel https\'e yükseltilir',
    $clean('<img src="//cdn.example.test/logo.png" alt="Logo" width="200">')
        === '<img src="https://cdn.example.test/logo.png" alt="Logo" width="200">',
    $clean('<img src="//cdn.example.test/logo.png" alt="Logo" width="200">'));
check('Şema-göreli bağlantı https\'e yükseltilir',
    $clean('<a href="//cdn.example.test/belge.pdf">Belge</a>')
        === '<a href="https://cdn.example.test/belge.pdf">Belge</a>');
check('Kaçırılmış &#10; URL\'de bozulmaz',
    $clean('<a href="/x?a=1&amp;#10;b">x</a>') === '<a href="/x?a=1&amp;#10;b">x</a>',
    $clean('<a href="/x?a=1&amp;#10;b">x</a>'));
check('Kaçırılmış &#9; URL\'de bozulmaz',
    $clean('<a href="/onizleme?html=%3Cp%3E&amp;#9;son">x</a>')
        === '<a href="/onizleme?html=%3Cp%3E&amp;#9;son">x</a>');
check('İzinsiz şemada ölü bağlantı bırakılmaz',
    $clean('<p>Arsiv: <a href="ftp://a.test/2026.zip">indir</a> <a href="sms:+9055">SMS</a></p>')
        === '<p>Arsiv: indir SMS</p>',
    $clean('<p>Arsiv: <a href="ftp://a.test/2026.zip">indir</a> <a href="sms:+9055">SMS</a></p>'));
check('Soyulan blok kelimeleri yapıştırmaz',
    $clean('<div>Birinci paragraf.</div><div>Ikinci paragraf.</div>')
        === "Birinci paragraf.\nIkinci paragraf.",
    $clean('<div>Birinci paragraf.</div><div>Ikinci paragraf.</div>'));
check('Soyulan h1 kelimeleri yapıştırmaz',
    $clean('<h1>Ana Baslik</h1><h1>Ikinci Baslik</h1>') === "Ana Baslik\nIkinci Baslik");
check('Çıktının sonunda sarkan ayırıcı kalmaz',
    $clean('<div>tek</div>') === 'tek', $clean('<div>tek</div>'));

/* Tablo bağlamında ayırıcı borcu hücre DIŞINA basılmamalı: hücre dışındaki
 * boşluk ikinci geçişte düştüğü için çıktı değişmez (idempotent) olmazdı.
 * Bu denetim 120.000 turluk bulaşık taramasında bulunan gerçek bir hatayı
 * kilitler. */
check('Tablo bağlamındaki ayırıcı borcu değişmezliği bozmaz',
    $clean('<table><div><b>x</b>') === '<table><tr><td><b>x</b></td></tr></table>',
    $clean('<table><div><b>x</b>'));

$definitionList = '<dl><dt>HTML</dt><dd>Isaretleme dili</dd><dt>CSS</dt><dd>Bicem dili</dd></dl>';

check('Tanım listesi korunur', $clean($definitionList) === $definitionList, $clean($definitionList));

$tableWithCaption = '<table><caption>Aylik gelir</caption><tr><td>A</td></tr></table>';

check('Tablo başlığı korunur', $clean($tableWithCaption) === $tableWithCaption,
    $clean($tableWithCaption));
check('CSS içindeki <!-- belgenin kalanını yutmaz',
    $clean('<style>/*<!--*/ p{}</style><b>gorunur</b>') === '<b>gorunur</b>',
    $clean('<style>/*<!--*/ p{}</style><b>gorunur</b>'));
check('--!> yorum kapanışı tanınır',
    $clean('<p>bir</p><!-- yorum --!><p>iki</p>') === '<p>bir</p><p>iki</p>',
    $clean('<p>bir</p><!-- yorum --!><p>iki</p>'));
check('Kapanmamış form içeriği silmez',
    $clean('<p>bir</p><form action=x><p>iki</p><p>uc</p>') === '<p>bir</p><p>iki</p><p>uc</p>',
    $clean('<p>bir</p><form action=x><p>iki</p><p>uc</p>'));
check('Kapanmamış noscript içeriği silmez',
    $clean('<p>a</p><noscript><p>b</p>') === '<p>a</p><p>b</p>');
check('Kapanan form içeriğiyle silinir',
    $clean('<p>a</p><form><input name=x><p>gizli</p></form><p>b</p>') === '<p>a</p><p>b</p>',
    $clean('<p>a</p><form><input name=x><p>gizli</p></form><p>b</p>'));
check('Özel öğe style sanılmaz',
    $clean('<style-x>ONEMLI METIN</style-x><p>devam</p>') === 'ONEMLI METIN<p>devam</p>',
    $clean('<style-x>ONEMLI METIN</style-x><p>devam</p>'));
check('İki nokta içeren ad script sanılmaz',
    $clean('<script:x>METIN</script:x>') === 'METIN', $clean('<script:x>METIN</script:x>'));
check('textarea metni kaybolmaz',
    $clean('<textarea>KAYBOLAN METIN</textarea>') === 'KAYBOLAN METIN');
check('xmp içeriği metin olarak korunur',
    $clean('<xmp><b>kalin</b></xmp>') === '&lt;b&gt;kalin&lt;/b&gt;',
    $clean('<xmp><b>kalin</b></xmp>'));

$nestedList = '<ul><li>Birinci<ul><li>Alt madde A</li><li>Alt madde B</li></ul></li><li>İkinci</li></ul>';
$mixedList  = '<ul><li>a<ol><li>1</li><li>2</li></ol></li></ul>';
$deepList   = '<ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li></ul>';
$nestedTable = '<table><tbody><tr><td><table><tbody><tr><td>ic</td></tr></tbody></table></td></tr></tbody></table>';
$headingTree = '<h2>a<blockquote><h3>b</h3></blockquote>c</h2>';

check('İç içe liste korunur', $clean($nestedList) === $nestedList, $clean($nestedList));
check('Liste içinde numaralı liste korunur', $clean($mixedList) === $mixedList, $clean($mixedList));
check('Üç seviyeli liste korunur', $clean($deepList) === $deepList, $clean($deepList));
check('İç içe tablo korunur', $clean($nestedTable) === $nestedTable, $clean($nestedTable));
check('Başlık içindeki blok yıkılmaz', $clean($headingTree) === $headingTree, $clean($headingTree));
check('Aynı düzey başlık örtük kapanır',
    $clean('<h2>bir<h2>iki') === '<h2>bir</h2><h2>iki</h2>', $clean('<h2>bir<h2>iki'));

check('Google Docs sarmalayıcısı her şeyi kalın yapmaz',
    $clean('<b style="font-weight:normal" id="docs-internal-guid-x"><p dir="ltr">'
        . '<span style="font-weight:700">Kalın</span></p></b>') === '<p><span>Kalın</span></p>',
    $clean('<b style="font-weight:normal"><p><span>Kalın</span></p></b>'));
check('strike ve tt modern karşılığına çevrilir',
    $clean('<p><strike>eski</strike> <tt>kod</tt></p>') === '<p><s>eski</s> <code>kod</code></p>',
    $clean('<p><strike>eski</strike> <tt>kod</tt></p>'));
check('abbr/cite/q/kbd/time biçimi korunur',
    $clean('<p><abbr title="HyperText">HTML</abbr> <cite>Kitap</cite> <q>alinti</q> '
        . '<kbd>Ctrl</kbd> <time datetime="2026-07-30">bugun</time></p>')
        === '<p><abbr title="HyperText">HTML</abbr> <cite>Kitap</cite> <q>alinti</q> '
        . '<kbd>Ctrl</kbd> <time datetime="2026-07-30">bugun</time></p>');
check('Kod örneğindeki < yutulmaz',
    $clean('<code>if (a<b) { return; }</code>') === '<code>if (a&lt;b) { return; }</code>',
    $clean('<code>if (a<b) { return; }</code>'));
check('Kod örneğindeki jenerik tür korunur',
    $clean('<pre><code>List<String> x = new ArrayList<>();</code></pre>')
        === '<pre><code>List&lt;String&gt; x = new ArrayList&lt;&gt;();</code></pre>',
    $clean('<pre><code>List<String> x = new ArrayList<>();</code></pre>'));
check('Kod içindeki <script> metne dönüşür ve etkisizdir',
    $clean('<pre><code><script>alert(1)</script></code></pre>')
        === '<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>',
    $clean('<pre><code><script>alert(1)</script></code></pre>'));
check('Geçersiz bayt soru işaretine dönüşmez',
    $clean("<p>a\xFFc</p>") === '<p>aÿc</p>', $clean("<p>a\xFFc</p>"));

$cutTitle = $clean('<a href="/x" title="' . str_repeat('ç', 498)
    . "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F466}son\">y</a>");

check('Kırpılan öznitelikte sarkan ZWJ kalmaz',
    !str_contains($cutTitle, "\u{200D}\"") && str_contains($cutTitle, "\u{1F468}"), $cutTitle);

/* ---- temel güvenceler ---- */

$inline = '<strong>a</strong><em>b</em><a href="/x">c</a><code>d</code><s>e</s>'
    . '<sup>f</sup><sub>g</sub><mark>h</mark>';

check('İzinli satır içi etiketler geçer', $clean($inline) === $inline, $clean($inline));
check('class/style/id/data-* düşer',
    $clean('<p class="x" style="color:red" id="y" data-z="1">a</p>') === '<p>a</p>',
    $clean('<p class="x" style="color:red" id="y" data-z="1">a</p>'));
check('srcset ve sizes düşer',
    $clean('<img src="/a.png" alt="A" srcset="/b.png 2x" sizes="100vw">')
        === '<img src="/a.png" alt="A">');
check('Olay öznitelikleri düşer',
    $clean('<img src="/x.png" onerror="alert(1)" alt="a">') === '<img src="/x.png" alt="a">');
check('target=_blank rel\'e noopener ekler',
    $clean('<a href="https://a.test" target="_blank">x</a>')
        === '<a href="https://a.test" rel="noopener" target="_blank">x</a>',
    $clean('<a href="https://a.test" target="_blank">x</a>'));
check('Var olan rel korunur, noopener eklenir',
    $clean('<a href="https://a.test" target="_blank" rel="nofollow">x</a>')
        === '<a href="https://a.test" rel="nofollow noopener" target="_blank">x</a>');
check('Bilinmeyen rel belirteci düşer',
    $clean('<a href="/x" rel="alert(1) nofollow">x</a>') === '<a href="/x" rel="nofollow">x</a>');
check('target=_top düşer', $clean('<a href="/x" target="_top">x</a>') === '<a href="/x">x</a>');

$turkish = "<p>Çığır açan şeyler: ĞÜŞİÖÇ\tsekme\nsatır sonu</p>";

check('Türkçe karakter, sekme ve satır sonu korunur', $clean($turkish) === $turkish, $clean($turkish));
check('pre girintisi korunur',
    $clean("<pre>satir1\n\tgirintili\n\nson</pre>") === "<pre>satir1\n\tgirintili\n\nson</pre>");
check('Boş olmayan boşluk (nbsp) korunur', $clean('<p>a&nbsp;b</p>') === '<p>a&nbsp;b</p>');
check('Entity çıktısı çift kaçışa uğramaz',
    $clean('<p>&amp; &lt;script&gt;</p>') === '<p>&amp; &lt;script&gt;</p>');
check('Emoji ve matematik işaretleri korunur',
    $clean('<p>👨‍👩‍👦 ≤ ≥ — “ ”</p>') === '<p>👨‍👩‍👦 ≤ ≥ — “ ”</p>');

check('script etiketi içeriğiyle silinir',
    $clean('<p>a</p><script>alert(1)</script><p>b</p>') === '<p>a</p><p>b</p>',
    $clean('<p>a</p><script>alert(1)</script><p>b</p>'));
check('style etiketi içeriğiyle silinir (CSS sızmaz)',
    $clean('<p>a</p><style>.a{color:red}</style><p>b</p>') === '<p>a</p><p>b</p>',
    $clean('<p>a</p><style>.a{color:red}</style><p>b</p>'));
check('iframe/object/embed/input silinir',
    $clean('<iframe src="https://e.test">y</iframe><object data="x"><embed src="y"></object><input>')
        === '', $clean('<iframe src="https://e.test">y</iframe>'));
check('Kendini kapatan script açılış sayılır',
    $clean('<script/>alert(1)') === '', $clean('<script/>alert(1)'));
check('Yorum silinir', $clean('<p>a</p><!-- gizli --><p>b</p>') === '<p>a</p><p>b</p>');
check('Kapanmamış etiket dengelenir',
    $clean('<p><strong>a') === '<p><strong>a</strong></p>');
check('Sahte kapanış yok sayılır', $clean('</p></div></b><p>a</p>') === '<p>a</p>');
check('Çözümleme sigortası derinlikte devrede',
    str_contains($clean(str_repeat('<b>', 200) . 'derin'), 'derin'));

$corpus = [
    $inline, $nestedList, $nestedTable, $headingTree, $definitionList, $pastedImage,
    '<table>Onemli<tr><td>A</td></tr></table>', '<div>a</div><div>b</div>',
    '<pre><code>List<String> x;</code></pre>', '<textarea>x</textarea>',
    '<a href="//cdn.test/x">y</a>', '<b style="x"><p>a</p></b>',
    '<table><div><b>x</b>', '<table><tr><div>a</div><b>x</b>', '<h2>bir<h2>iki',
    '<style>a{}</style a=">">devam', '<xmp><b>k</b></xmp>', '<tr><td>a</td></tr>',
];

$stable = true;

foreach ($corpus as $sample) {
    $once = $clean($sample);

    if ($clean($once) !== $once) {
        $stable = false;
        break;
    }
}

check('clean() değişmezdir (idempotent)', $stable);

check('Html::allowed() tabloyu döndürür',
    array_key_exists('a', Html::allowed()) && Html::allowed()['a'] === ['href', 'title', 'rel', 'target']
        && Html::allowed()['p'] === []);
check('Html::text() etiketsiz metin döndürür',
    trim(Html::text('<p>bir</p><p>iki</p>')) === "bir\n\niki",
    json_encode(Html::text('<p>bir</p><p>iki</p>')));
check('Html::text() betik gövdesini almaz',
    trim(Html::text('<script>alert(1)</script>metin')) === 'metin');
check('Str::excerpt betik gövdesini almaz',
    Str::excerpt('<script>alert(1)</script><p>Gerçek özet</p>') === 'Gerçek özet',
    Str::excerpt('<script>alert(1)</script><p>Gerçek özet</p>'));

/* -------------------------------------------------------------------------
 * 3. Rota tablosu
 * ---------------------------------------------------------------------- */

echo "\nYönlendirme\n";

$router = new Router();
$hit    = [];

$router->get('/', static fn(): string => 'home', 'home', 10);
$router->get('/sayfa/{page:\d+}', static fn(array $p): string => 'home:' . $p['page'], 'home.paged', 10);
$router->get('/yazi/{slug}', static fn(array $p): string => 'single:' . $p['slug'], 'single', 50);
$router->get('/kategori/{slug}/sayfa/{page:\d+}', static fn(array $p): string => 'tax', 'tax.paged', 30);
$router->get('/sitemap.xml', static fn(): string => 'sitemap', 'sitemap', 1);
$router->get('/{slug}', static fn(array $p): string => 'page:' . $p['slug'], 'root', 90);

$make = static fn(string $path, string $method = 'GET'): Request
    => new Request($method, $path, [], [], [], ['REQUEST_METHOD' => $method]);

$match = $router->match($make('/'));
check('Kök yol eşleşir', $match !== null && ($match['handler'])([]) === 'home');

$match = $router->match($make('/yazi/merhaba-dunya'));
check('Tek yazı yolu', $match !== null && ($match['handler'])($match['params']) === 'single:merhaba-dunya');

$match = $router->match($make('/sayfa/3'));
check('Sayısal kısıt', $match !== null && ($match['handler'])($match['params']) === 'home:3');

// /sayfa/abc iki segmentlidir; yakala-hepsini deseni ({slug}) tek segment
// eşleştirir. Dolayısıyla hiçbir rotaya uymaz ve 404 verilir — istenen davranış.
$match = $router->match($make('/sayfa/abc'));
check('Sayısal olmayan sayfa numarası eşleşmez (404)', $match === null);

$match = $router->match($make('/sitemap.xml'));
check('Noktalı sabit yol kaçırılır', $match !== null && $match['name'] === 'sitemap');

$match = $router->match($make('/kategori/yazilim/sayfa/2'));
check('Çok parçalı desen', $match !== null && $match['name'] === 'tax.paged');

$match = $router->match($make('/hakkinda'));
check('Yakala-hepsini en sonda', $match !== null && $match['name'] === 'root');

$match = $router->match($make('/yorum', 'POST'));
check('Tanımsız POST eşleşmez', $match === null);

check('Adlandırılmış rotadan yol', $router->path('single', ['slug' => 'x-y']) === '/yazi/x-y',
    (string) $router->path('single', ['slug' => 'x-y']));

/* -------------------------------------------------------------------------
 * 4. Blok işleme
 * ---------------------------------------------------------------------- */

echo "\nBlok sistemi\n";

$blocks = new BlockRegistry();
$blocks->registerDefaults();

check('Yerleşik blok sayısı', count($blocks->all()) >= 12, (string) count($blocks->all()));
check('Paragraf kayıtlı', $blocks->has('paragraph'));
check('Boş veri iskeleti', array_key_exists('text', $blocks->blank('paragraph')));
check('Gruplama çalışır', count($blocks->grouped()) >= 3);

// Blok işleyici, medya deposu olmadan da metin bloklarını basabilmeli.
// Connection kurucusu bağlantı açmaz (tembeldir), bu yüzden MySQL gerekmez.
$events = new Dispatcher();

$renderer = new HiCMS\Content\BlockRenderer(
    $blocks,
    new HiCMS\Repository\MediaRepository(new HiCMS\Database\Connection([])),
    new HiCMS\Http\Url('https://site.test'),
    $events
);

$html = $renderer->renderBlocks([
    ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => 'Başlık Örneği']],
    ['type' => 'paragraph', 'data' => ['text' => 'İlk paragraf.', 'lead' => true]],
    ['type' => 'list', 'data' => ['style' => 'bullet', 'items' => ['a', 'b']]],
    ['type' => 'quote', 'data' => ['text' => 'Alıntı', 'cite' => 'Kaynak']],
    ['type' => 'code', 'data' => ['language' => 'php', 'code' => '<?php echo 1;']],
    ['type' => 'embed', 'data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
    ['type' => 'divider', 'data' => ['style' => 'dots']],
    ['type' => 'bilinmeyen', 'data' => ['x' => 1]],
]);

check('Başlık çıpası üretilir', str_contains($html, 'id="baslik-ornegi"'));
check('Giriş paragrafı sınıfı', str_contains($html, 'class="is-lead"'));
check('Liste basılır', str_contains($html, '<ul><li>a</li><li>b</li></ul>'));
check('Alıntı kaynağı', str_contains($html, '<cite>Kaynak</cite>'));
check('Kod kaçırılır', str_contains($html, '&lt;?php echo 1;'));
check('YouTube gömme dönüştürülür', str_contains($html, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'));
check('Bilinmeyen blok atlanır', !str_contains($html, 'bilinmeyen'));

// Olay dinleyicisi çıktıyı sarmalayabiliyor mu?
$events->listen(BlockRendering::class, static function (BlockRendering $event): void {
    if ($event->type() === 'paragraph') {
        $event->wrap('<div class="sarmal">', '</div>');
    }
});

$wrapped = $renderer->renderBlocks([['type' => 'paragraph', 'data' => ['text' => 'x']]]);
check('BlockRendering olayı çıktıyı sarmalar', str_contains($wrapped, '<div class="sarmal">'));

/* -------------------------------------------------------------------------
 * 5. İçerik modeli
 * ---------------------------------------------------------------------- */

echo "\nİçerik modeli\n";

$entry = Entry::fromRow([
    'id'           => 7,
    'type'         => 'post',
    'status'       => 'published',
    'title'        => 'Deneme',
    'slug'         => 'deneme',
    'blocks'       => json_encode([
        ['type' => 'paragraph', 'data' => ['text' => str_repeat('kelime ', 240)]],
        ['type' => 'bozuk'],
        'dizge değil',
    ]),
    'published_at' => '2026-01-01 00:00:00',
]);

check('Satırdan kurulur', $entry->id === 7 && $entry->slug === 'deneme');
check('Bozuk bloklar temizlenir', count($entry->blocks) === 2, (string) count($entry->blocks));
check('Yayında sayılır', $entry->isPublished());
check('Okuma süresi hesaplanır', $entry->readingTime() >= 2, (string) $entry->readingTime());
check('Özet bloklardan üretilir', str_starts_with($entry->summary(30), 'kelime'));

$future = Entry::fromRow([
    'id' => 8, 'status' => 'published', 'published_at' => date('Y-m-d H:i:s', time() + 86400),
]);

check('İleri tarihli yayın görünmez', !$future->isPublished());
check('İleri tarihli yayın zamanlanmış sayılır', $future->isScheduled());

/* -------------------------------------------------------------------------
 * 6. İçerik türleri
 * ---------------------------------------------------------------------- */

echo "\nİçerik türleri\n";

$types = new TypeRegistry();
$types->registerDefaults();

check('Kutudan yalnızca post ve page', count($types->all()) === 2, implode(',', array_keys($types->all())));
check('Kategori taksonomisi tek seçim', $types->taxonomy('category')?->single === true);
check('Etiket taksonomisi çoklu', $types->taxonomy('tag')?->single === false);

$types->registerArray([
    'name'       => 'portfolyo',
    'labels'     => ['singular' => 'Proje', 'plural' => 'Portfolyo'],
    'route'      => 'proje',
    'archive'    => 'portfolyo',
    'supports'   => ['blocks', 'image'],
    'taxonomies' => ['category'],
    'fields'     => [
        ['key' => 'musteri', 'type' => 'text', 'label' => 'Müşteri'],
        ['key' => 'yil', 'type' => 'number', 'label' => 'Yıl'],
        ['key' => 'adres', 'type' => 'url', 'label' => 'Proje adresi'],
    ],
], 'plugin:test');

$portfolio = $types->get('portfolyo');

check('JSON tanımdan tür kaydı', $portfolio instanceof ContentType);
check('Arşivi var', $portfolio?->hasArchive() === true);
check('Rota önekiyle bulunur', $types->byRoute('proje')?->name === 'portfolyo');
check('Özet desteği kapalı', $portfolio?->hasExcerpt === false);
check('Alanlar okunur', count($portfolio?->fields ?? []) === 3);

$urlField = $portfolio?->field('adres');
check('URL alanı geçersiz değeri reddeder', $urlField?->sanitize('bu bir adres değil') === '');
check('URL alanı geçerli değeri kabul eder',
    $urlField?->sanitize('https://ornek.test') === 'https://ornek.test');

$numberField = $portfolio?->field('yil');
check('Sayı alanı dönüştürür', $numberField?->sanitize('2026') === 2026.0);

$types->forgetSource('plugin:test');
check('Kaynak kaldırılınca tür düşer', !$types->has('portfolyo'));

/* -------------------------------------------------------------------------
 * 7. Şema SQL üretimi
 * ---------------------------------------------------------------------- */

echo "\nVeritabanı şeması\n";

$blueprint = new Blueprint();
$blueprint->id();
$blueprint->key('slug');
$blueprint->string('title', 255);
$blueprint->longText('blocks')->nullable();
$blueprint->boolean('featured')->default(0);
$blueprint->boolean('comments_open')->default(1);
$blueprint->timestamp('published_at')->nullable();
$blueprint->integer('views', true)->default(0);
$blueprint->unique(['slug'], 'slug_unique');
$blueprint->index('featured');

$sql = $blueprint->toSql('hi_content');

check('CREATE TABLE üretilir', str_starts_with($sql, 'CREATE TABLE `hi_content`'));
check('Birincil anahtar', str_contains($sql, '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'));
check('Tekil indeks', str_contains($sql, 'UNIQUE KEY `slug_unique` (`slug`)'));
check('InnoDB ve utf8mb4', str_contains($sql, 'ENGINE=InnoDB') && str_contains($sql, 'utf8mb4'));

/*
 * Sütun tanımları TAM eşleşmeyle denetlenir. Daha önce burada `str_contains`
 * ile parça araması yapılıyordu ve `DEFAULT 0 DEFAULT 1` gibi geçersiz SQL
 * testten geçiyordu — migration'ların hiç çalışmamasına yol açan hata buydu.
 */
$definitions = [];

foreach ($blueprint->columnDefinitions() as $definition) {
    preg_match('/^`([^`]+)`/', $definition, $m);
    $definitions[$m[1]] = $definition;
}

$expected = [
    'id'            => '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
    'slug'          => '`slug` VARCHAR(191) NOT NULL',
    'title'         => '`title` VARCHAR(255) NOT NULL',
    'blocks'        => '`blocks` LONGTEXT NULL',
    'featured'      => '`featured` TINYINT(1) NOT NULL DEFAULT 0',
    'comments_open' => '`comments_open` TINYINT(1) NOT NULL DEFAULT 1',
    'published_at'  => '`published_at` DATETIME NULL',
    'views'         => '`views` INT UNSIGNED NOT NULL DEFAULT 0',
];

foreach ($expected as $column => $want) {
    check(
        'Sütun tanımı tam eşleşir: ' . $column,
        ($definitions[$column] ?? '') === $want,
        'üretilen: ' . ($definitions[$column] ?? 'yok')
    );
}

// Hiçbir sütunda yan tümce tekrarı olmamalı.
foreach ($definitions as $column => $definition) {
    check(
        'Tek DEFAULT yan tümcesi: ' . $column,
        substr_count($definition, 'DEFAULT') <= 1,
        $definition
    );
    check(
        'NULL/NOT NULL çakışması yok: ' . $column,
        substr_count($definition, ' NULL') === 1,
        $definition
    );
}

// Çağrı sırası sonucu değiştirmemeli.
$orderA = (new Blueprint())->boolean('x')->default(1)->nullable();
$orderB = (new Blueprint())->boolean('x')->nullable()->default(1);

check('Değiştirici sırası sonucu değiştirmez',
    $orderA->columnDefinitions() === $orderB->columnDefinitions(),
    implode(' | ', array_merge($orderA->columnDefinitions(), $orderB->columnDefinitions())));

// Aynı sütunda ikinci default öncekini değiştirir.
$replaced = (new Blueprint())->integer('n')->default(1)->default(5);

check('İkinci default öncekini değiştirir',
    $replaced->columnDefinitions() === ['`n` INT NOT NULL DEFAULT 5'],
    implode('', $replaced->columnDefinitions()));

/*
 * Gerçek migration dosyalarının ürettiği SQL de denetlenir: tablo başına en
 * fazla bir DEFAULT ve dengeli parantez. Bu, migration'ları veritabanı olmadan
 * doğrulamanın tek yolu.
 */
$migrationSql = [];
$connection   = new HiCMS\Database\Connection(['prefix' => 'hi_']);
$schema       = new HiCMS\Database\Schema($connection);

$schema->beginDryRun();

$migrationFiles = array_merge(
    glob($root . '/src/Database/migrations/*.php') ?: [],
    glob($root . '/plugins/*/migrations/*.php') ?: []
);

foreach ($migrationFiles as $file) {
    $migration = require $file;
    $migration->up($schema, $connection);
}

$generated = $schema->endDryRun();

foreach ($generated as $table => $createSql) {
    $lines = array_filter(
        array_map('trim', explode("\n", $createSql)),
        static fn(string $line): bool => str_starts_with($line, '`')
    );

    $clean = true;

    foreach ($lines as $line) {
        if (substr_count($line, 'DEFAULT') > 1 || substr_count($line, ' NULL') > 1) {
            $clean = false;
            $migrationSql[] = $table . ': ' . rtrim($line, ',');
        }
    }

    check('Migration SQL geçerli: ' . $table, $clean, implode(' / ', $migrationSql));
}

check('Tüm migration tabloları üretildi', count($generated) >= 12, (string) count($generated));
check('Eklenti migration\'ları da denetlendi',
    array_key_exists('seo_redirects', $generated) && array_key_exists('form_submissions', $generated),
    implode(',', array_keys($generated)));

/* -------------------------------------------------------------------------
 * 8. Önyükleme (yapılandırma yok)
 * ---------------------------------------------------------------------- */

echo "\nÖnyükleme\n";

$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';

$app = Kernel::boot($root, false);

check('Çekirdek başlar', $app instanceof Kernel);
check('Sürüm okunur', Kernel::VERSION !== '');

/*
 * "Kurulu değil" durumu kök dizindeki config.php'nin yokluğuna bakılarak
 * denetlenirse, geliştirme makinesinde gerçek bir kurulum varken test boşuna
 * düşer. O yüzden yapılandırması olmayan geçici bir dizin kullanılır.
 */
$emptyRoot = sys_get_temp_dir() . '/hicms-bos-' . bin2hex(random_bytes(4));

check(
    'Yapılandırma yoksa kurulu sayılmaz',
    !(new Installer($emptyRoot))->isInstalled(),
    $emptyRoot
);

/*
 * MySQL ve MariaDB, SHOW / DESCRIBE deyimlerinde bağlı parametre kabul etmez;
 * hazırlanan `SHOW TABLES LIKE ?` çalışma anında 1064 verir ve bu ancak gerçek
 * bir veritabanıyla görülür. Aşağıdaki denetim o hatayı kaynak düzeyinde,
 * veritabanı olmadan yakalar.
 */
$placeholderInShow = [];
$sourceFiles       = [];

foreach (['src', 'admin', 'plugins', 'themes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getExtension() === 'php') {
            $sourceFiles[] = $item->getPathname();
        }
    }
}

foreach ($sourceFiles as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match('~[\'"]\s*(SHOW|DESCRIBE|EXPLAIN)\s[^\'"]*\?~i', $source, $hit) === 1) {
        $placeholderInShow[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . trim($hit[0]);
    }
}

check(
    'SHOW/DESCRIBE deyimlerinde bağlı parametre yok',
    $placeholderInShow === [],
    implode(' | ', $placeholderInShow)
);

check('Olay dağıtıcısı hazır', $app->events() instanceof Dispatcher);
check('Blok kaydı tembel kurulur', $app->blocks() instanceof BlockRegistry);
check('URL üretici çalışır', str_starts_with($app->urls()->to('hakkinda'), 'http'),
    $app->urls()->to('hakkinda'));
check('Panel adresi', str_ends_with($app->urls()->admin(), '/admin/'), $app->urls()->admin());
check('Küresel tema fonksiyonları yüklendi', function_exists('hi_title') && function_exists('hi_permalink'));
check('CSRF anahtarı üretilir ve doğrulanır', $app->csrf()->verify($app->csrf()->token()));
check('Bozuk CSRF reddedilir', !$app->csrf()->verify('sahte'));
check('Roller tanımlı', count($app->roles()->options()) === 5, implode(',', array_keys($app->roles()->options())));
check('Yönetici tüm izinlere sahip',
    count($app->roles()->capabilitiesOf('admin')) === count(HiCMS\Auth\Roles::CAPABILITIES));
check('Abone içerik silemez', !$app->roles()->roleCan('subscriber', 'content.delete'));
check('Editör yayınlayabilir', $app->roles()->roleCan('editor', 'content.publish'));
check('Editör eklenti kuramaz', !$app->roles()->roleCan('editor', 'plugins.manage'));
check('Tema keşfedilir', array_key_exists('hiblog', $app->themes()->available()));
check('Varsayılan tema geçerli', $app->themes()->available()['hiblog']->valid ?? false,
    $app->themes()->available()['hiblog']->error ?? '');
check('Eklentiler keşfedilir', count($app->plugins()->available()) === 5,
    implode(',', array_keys($app->plugins()->available())));

foreach ($app->plugins()->available() as $slug => $plugin) {
    check('Eklenti künyesi geçerli: ' . $slug, $plugin->valid, $plugin->error);
    check('Eklenti ana dosyası var: ' . $slug, $plugin->hasMainFile());
}

check('Çekirdek migration kaynağı kayıtlı',
    array_key_exists('core', $app->migrator()->sources()));

/* -------------------------------------------------------------------------
 * 9. Gereksinim denetimi
 * ---------------------------------------------------------------------- */

echo "\nGereksinimler\n";

$checks  = Requirements::check($root);
$summary = Requirements::summary($checks);

check('Denetim listesi dolu', count($checks) > 10, (string) count($checks));
check('Zorunlu maddeler sağlanıyor', Requirements::passes($checks),
    'eksik: ' . $summary['fail']);

printf("       %d uygun, %d uyarı, %d eksik\n", $summary['ok'], $summary['warn'], $summary['fail']);

/* -------------------------------------------------------------------------
 * Sonuç
 * ---------------------------------------------------------------------- */

echo "\n" . str_repeat('-', 58) . "\n";

if ($failed === []) {
    printf("TUM TESTLER GECTI (%d denetim)\n", $passed);
    exit(0);
}

printf("BASARISIZ: %d / %d\n\n", count($failed), $passed + count($failed));

foreach ($failed as $failure) {
    echo '  - ' . $failure . "\n";
}

exit(1);
