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
        'img'        => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    ];

    /** İçeriği olmayan (void) etiketler: yığına girmez, kapanışı basılmaz. */
    private const VOID = ['br' => true, 'hr' => true, 'img' => true];

    /**
     * İçeriğiyle birlikte TAMAMEN silinen etiketler ve silme biçimi.
     *
     * Neden etiketi soyup içeriği bırakmak yetmiyor — her biri için gerekçe:
     *
     * 'raw'  → İçeriği tarayıcı tarafından işaretleme olarak ÇÖZÜMLENMEZ (ham
     *          metin / kaçırılabilir ham metin içerik modeli). Bizim
     *          tarayıcımız ise `<` gördüğünde etiket arar; bu bir çözümleme
     *          farkıdır. Farkı kapatmanın doğru yolu, tarayıcı gibi davranıp
     *          kapanışa kadar her şeyi ham metin saymak ve atmaktır.
     *          - script: içerik JavaScript kaynağıdır. Soyulsa `alert(1)`
     *            ekranda düz metin olarak görünürdü; düzyazı olarak hiçbir
     *            anlamı yok, atılır.
     *          - style: içerik CSS'tir. Soyulsa CSS gövdeye metin olarak
     *            SIZAR (kullanıcı sayfada `.a{color:red}` görür) ve eski
     *            tarayıcılarda `expression()` yüzeyi açılır. Atılır.
     *          - iframe: HTML5'te içeriği zaten yok sayılır, ekranda hiç
     *            görünmez. Soymak görünmeyen metni birden görünür kılardı.
     *          - textarea / title / xmp / noembed / noframes / plaintext:
     *            hepsi ham metin taşır, düzyazıda yeri yoktur.
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
        'script'    => 'raw',
        'style'     => 'raw',
        'iframe'    => 'raw',
        'textarea'  => 'raw',
        'title'     => 'raw',
        'xmp'       => 'raw',
        'noembed'   => 'raw',
        'noframes'  => 'raw',
        'plaintext' => 'raw',

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
     * Örtük kapanış tablosu: soldaki etiket açılırken sağdaki ADIMLAR SIRAYLA
     * uygulanır. Her adım bir ad kümesidir; yığında o kümeden biri varsa ona
     * kadar (o dahil) geri sarılır.
     *
     * Adımların SIRALI olması şart. HTML5 "in body" kuralları önce açık
     * paragrafı kapatır, sonra aynı türden düğümü açar: `<p>a<li>b` girdisinde
     * tek geçişli "en üstteki eşleşme" araması `<li>`yi paragrafın İÇİNDE
     * bırakırdı (geçersiz HTML). İki adım bunu engeller.
     *
     * Kötü biçimli HTML'i normalize eden yer burasıdır: `<p>a<p>b` iki
     * paragrafa, `<li>a<li>b` iki maddeye, `<td>a<td>b` iki hücreye ayrılır.
     *
     * @var array<string, list<list<string>>>
     */
    private const IMPLIED_END = [
        'p'          => [['p']],
        'h2'         => [['p'], ['h2', 'h3', 'h4', 'h5', 'h6']],
        'h3'         => [['p'], ['h2', 'h3', 'h4', 'h5', 'h6']],
        'h4'         => [['p'], ['h2', 'h3', 'h4', 'h5', 'h6']],
        'h5'         => [['p'], ['h2', 'h3', 'h4', 'h5', 'h6']],
        'h6'         => [['p'], ['h2', 'h3', 'h4', 'h5', 'h6']],
        'ul'         => [['p']],
        'ol'         => [['p']],
        'li'         => [['p'], ['li']],
        'blockquote' => [['p']],
        'pre'        => [['p']],
        'figure'     => [['p']],
        'figcaption' => [['p']],
        'hr'         => [['p']],
        'table'      => [['p']],
        'thead'      => [['p']],
        'tbody'      => [['p'], ['thead', 'tbody']],
        'tr'         => [['p'], ['tr']],
        'th'         => [['p'], ['th', 'td']],
        'td'         => [['p'], ['th', 'td']],
    ];

    /**
     * Düz metne çevirirken satır sonu bırakılacak etiketler.
     * `<p>a</p><p>b</p>` çıktısı "ab" değil "a\nb" olsun diye.
     */
    private const BREAKS = [
        'br' => true, 'p' => true, 'div' => true, 'section' => true, 'article' => true,
        'header' => true, 'footer' => true, 'main' => true, 'aside' => true, 'nav' => true,
        'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
        'ul' => true, 'ol' => true, 'li' => true, 'blockquote' => true, 'pre' => true,
        'figure' => true, 'figcaption' => true, 'hr' => true, 'table' => true,
        'tr' => true, 'th' => true, 'td' => true, 'caption' => true,
        'dl' => true, 'dt' => true, 'dd' => true, 'address' => true,
    ];

    /** `href` / `src` için izinli şemalar. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * `rel` için izinli belirteçler. Bilinmeyen belirteç düşer: `rel`
     * değerleri tarayıcı davranışı tetikleyebiliyor, serbest metin olamaz.
     */
    private const REL = [
        'alternate', 'author', 'bookmark', 'external', 'help', 'license', 'me',
        'next', 'nofollow', 'noopener', 'noreferrer', 'prev', 'search',
        'sponsored', 'tag', 'ugc',
    ];

    /** HTML'de anlamlı boşluk karakterleri (etiket içi ayırıcılar). */
    private const SPACE = " \t\n\r\f";

    /** Etiket adı karakterleri. */
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

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
     *   U+0000          → etiket adı gizleme (`<scr\0ipt>`) ve dizge kesme
     *   U+FEFF          → sıfır genişlikli gizleme
     *   U+202A..U+202E  → eski yön değiştirme (bidi override)
     *   U+2066..U+2069  → yön yalıtımı; görünen metni ters çevirip sahte
     *                     bağlantı metni üretmeye yarar (Trojan Source)
     */
    private const INVISIBLE = [
        "\0",
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
            if (preg_match('/^[a-z][a-z0-9]*$/', $tag) !== 1 || isset(self::DROP[$tag])) {
                continue;
            }

            $clean = [];

            foreach ((array) $attributes as $attribute) {
                $attribute = strtolower(trim((string) $attribute));

                if (preg_match('/^[a-z][a-z0-9-]*$/', $attribute) !== 1) {
                    continue;
                }

                // Olay öznitelikleri ve stil hiçbir koşulda açılmaz.
                if (str_starts_with($attribute, 'on') || $attribute === 'style'
                    || $attribute === 'srcset' || $attribute === 'sizes') {
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
                || in_array($scheme, ['javascript', 'vbscript', 'data', 'file', 'blob'], true)) {
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

        // Bastırma durumu: içeriğiyle silinen bir etiketin içindeyiz.
        $hidden      = '';
        $hiddenKind  = '';
        $hiddenDepth = 0;

        $i = 0;

        while ($i < $length) {
            $lt = strpos($html, '<', $i);

            if ($lt === false) {
                if ($hidden === '') {
                    $out .= $this->textRun(substr($html, $i), $textOnly);
                }

                break;
            }

            if ($lt > $i && $hidden === '') {
                $out .= $this->textRun(substr($html, $i, $lt - $i), $textOnly);
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
                $name    = $this->readName($html, $lt + 2, $nameEnd);

                $close = strpos($html, '>', $nameEnd);
                $i     = $close === false ? $length : $close + 1;

                if ($name === '') {
                    // `</>` ya da `</ x>` → bozuk yorum, atılır.
                    continue;
                }

                if ($hidden !== '') {
                    if ($name === $hidden && --$hiddenDepth <= 0) {
                        $hidden     = '';
                        $hiddenKind = '';
                    }

                    continue;
                }

                if ($textOnly) {
                    if (isset(self::BREAKS[$name])) {
                        $out .= "\n";
                    }

                    continue;
                }

                // Void etiketin kapanışı yoktur; izin listesi dışı kapanış da
                // yığında olmadığı için zaten yok sayılır.
                $out .= $this->closeTo($stack, [$name]);
                continue;
            }

            if (!$this->isLetter($next)) {
                // `< 5` gibi bir durum: `<` düz metindir.
                if ($hidden === '') {
                    $out .= $textOnly ? '<' : '&lt;';
                }

                $i = $lt + 1;
                continue;
            }

            /* ---- açılış etiketi ---- */

            $nameEnd = 0;
            $name    = $this->readName($html, $lt + 1, $nameEnd);

            $tagEnd    = 0;
            $selfClose = false;
            $attrs     = $this->readAttributes($html, $nameEnd, $tagEnd, $selfClose);
            $i         = $tagEnd;

            /* `/>` işareti HTML öğelerinde YOK SAYILIR: `<script/>alert(1)`
             * tarayıcıda bir script AÇAR, kendini kapatmaz. XML tarzı kendini
             * kapatma yalnızca yabancı içerikte (svg, math) geçerlidir. Bu
             * ayrım olmadan `<style/>.x{}</style>` gibi bir girdide CSS metne
             * dönüp gövdeye sızardı. */
            $selfClosed = $selfClose && ($name === 'svg' || $name === 'math');

            if ($hidden !== '') {
                // Ham metin içinde iç içelik yoktur (tarayıcı da saymaz):
                // yalnızca 'nest' türünde derinlik artar.
                if ($hiddenKind === 'nest' && $name === $hidden && !$selfClosed) {
                    $hiddenDepth++;
                }

                continue;
            }

            $drop = self::DROP[$name] ?? '';

            if ($drop !== '') {
                if ($drop !== 'void' && !$selfClosed) {
                    $hidden      = $name;
                    $hiddenKind  = $drop;
                    $hiddenDepth = 1;
                }

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
                continue;
            }

            // Örtük kapanışlar — kötü biçimli iç içeliği normalize eder.
            // closeTo() yığında eşleşme yoksa hiçbir şey yapmaz.
            foreach (self::IMPLIED_END[$name] ?? [] as $step) {
                $out .= $this->closeTo($stack, $step);
            }

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

        return $out;
    }

    /**
     * Yığını, verilen adlardan yığında EN ÜSTTE bulunana kadar (o dahil)
     * kapatır. Ad yığında yoksa hiçbir şey yapmaz — sahte kapanışlar
     * (`</div>` gibi hiç açılmamış etiketler) sessizce yok sayılır.
     *
     * @param list<string> $stack
     * @param list<string> $names
     */
    private function closeTo(array &$stack, array $names): string
    {
        $found = null;

        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (in_array($stack[$index], $names, true)) {
                $found = $index;
                break;
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
     * `<!` ile başlayan yapıyı atlar ve bittiği konumu döndürür.
     *
     * `-->` araması `$start + 2`den başlar: HTML5'e göre `<!-->` ve `<!--->`
     * de tamamlanmış yorumlardır. `$start + 4`ten arasak bu iki girdide
     * kapanış bulunamaz ve belgenin kalanı sessizce yutulurdu.
     */
    private function skipComment(string $html, int $start, int $length): int
    {
        if (substr($html, $start, 4) === '<!--') {
            $close = strpos($html, '-->', $start + 2);

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
        $count = strspn($html, self::NAME_CHARS, $position);
        $end   = $position + $count;

        return $count === 0 ? '' : strtolower(substr($html, $position, $count));
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
     * @return array<string, string> Ham (kaçırılmamış, çözülmemiş) değerler
     */
    private function readAttributes(string $html, int $position, int &$end, bool &$selfClose): array
    {
        $length    = strlen($html);
        $attrs     = [];
        $selfClose = false;

        while ($position < $length) {
            $position += strspn($html, self::SPACE, $position);

            if ($position >= $length) {
                break;
            }

            $char = $html[$position];

            if ($char === '>') {
                $end = $position + 1;

                return $attrs;
            }

            if ($char === '/') {
                if (($html[$position + 1] ?? '') === '>') {
                    $selfClose = true;
                    $end       = $position + 2;

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

            $value = $this->attributeValue($attribute, $this->decode($attrs[$attribute]));

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
     * Değer buraya ÇÖZÜLMÜŞ (entity'leri açılmış) gelir. Bu şart:
     * `&#106;avascript:alert(1)` ham hâliyle bakıldığında zararsız görünür,
     * tarayıcı ise onu çözerek `javascript:` olarak okur.
     *
     * @return string|null null → öznitelik düşer
     */
    private function attributeValue(string $attribute, string $value): ?string
    {
        switch ($attribute) {
            case 'href':
            case 'src':
                $url = $this->safeUrl($value);

                return $url === '' ? null : $url;

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

            default:
                // title, alt ve genişletmeyle gelen öznitelikler: düz metin.
                // Satır sonları boşluğa çevrilir (öznitelikte anlamı yok).
                $text = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');

                if ($text === '') {
                    return null;
                }

                return mb_substr($text, 0, self::MAX_ATTR, 'UTF-8');
        }
    }

    /**
     * URL doğrulaması.
     *
     * Kabul edilenler:
     *   - http, https, mailto, tel şemaları (kurucuyla genişletilebilir)
     *   - `/` ile başlayan aynı site yolları (`//` ile BAŞLAMAYAN)
     *   - `#çıpa`, `?sorgu` ve şema içermeyen göreli yollar
     *
     * @return string Boş dizge → değer reddedildi
     */
    private function safeUrl(string $value): string
    {
        $value = $this->decodeControlRefs($value);

        /* Tarayıcılar URL'nin içindeki C0 denetim karakterlerini ve DEL'i yok
         * sayar; şemayı gizlemek için kullanılırlar: "java\tscript:alert(1)"
         * tarayıcıda javascript: olarak okunur. Bu yüzden şema denetiminden
         * ÖNCE atılırlar. (Buradaki silme yalnızca öznitelik değerine özeldir;
         * metin gövdesindeki \n ve \t korunur.) */
        $url = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '');

        if ($url === '') {
            return '';
        }

        /* `//evil.test` şema-göreli adrestir, dış siteye çıkar. Tarayıcılar
         * ters bölüyü bölüye çevirdiği için `/\evil.test` ve `\\evil.test`
         * de aynı kapıya çıkar; üçü birden reddedilir. */
        if (preg_match('#^[/\\\\]{2}#', $url) === 1 || $url[0] === '\\') {
            return '';
        }

        // Şema varsa izin listesinde olmalı. Göreli yolda ilk bölütte iki
        // nokta bulunması tarayıcı için de şemadır; o da buraya düşer.
        if (preg_match('#^([a-z][a-z0-9+.\-]*)\s*:#i', $url, $match) === 1
            && !in_array(strtolower($match[1]), $this->schemes, true)) {
            return '';
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
     * Bu yüzden başvuru şema denetiminden önce gerçek karaktere çevrilir;
     * ardından safeUrl() denetim karakterlerini atar ve şemayı görüp reddeder.
     *
     * Yalnızca `safeUrl()` içinde çağrılır. Metin gövdesinde çağrılmaz: orada
     * bir şema yoktur ve çıktı kaçışı `&`yi zaten `&amp;` yapar. Buradaki tek
     * yan etki, `&amp;#14;...` gibi çift kodlanmış tuhaf adreslerin de
     * reddedilmesidir — URL'de fazla reddetmek güvenli yöndür.
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
            $html = (string) mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        return str_replace(self::INVISIBLE, '', $html);
    }

    /** ASCII harf mi (etiket adı başlangıcı). */
    private function isLetter(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z');
    }
}
