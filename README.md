# HiCMS

PHP tabanlı içerik yönetim sistemi. Tek bir çalışan çekirdek üzerine tema ve
eklenti geliştirerek müşteri siteleri (blog, portfolyo, tanıtım, STK) üretmek için
tasarlandı.

**Sürüm:** 0.2.0 · **Gereksinim:** PHP 8.2+, MySQL 5.7+ / MariaDB 10.3+

---

## Ne WordPress'ten farklı?

| | WordPress | HiCMS |
|---|---|---|
| Kod yapısı | Küresel fonksiyonlar | `HiCMS\` namespace, PSR-4 autoload, servis konteyneri |
| Genişletme | String kancalar (`add_action('init', …)`) | **Tipli olay sınıfları** (`Content\Saved`) + ince taneli adlandırılmış kancalar |
| İçerik | Tek HTML alanı | **JSON blok ağacı** — yapılandırılmış, yeniden kullanılabilir |
| İçerik türleri | PHP kodu gerektirir | **JSON şema** dosyası bırakmak yeterli |
| Yönlendirme | Query-var tahmini | Merkezi, öncelikli **rota tablosu** |
| Yükleme | Her istekte her şey | **Tembel servisler** — yalnızca kullanılan kurulur |
| Kurulum | 5 dakika, çok adım | Tek ZIP, üç ekran |
| Güncelleme | Karışık | **Aynı ZIP**, otomatik yedek, geri alma |

Kod tabanı bilinçli olarak küçük: bağımlılık yok, Composer yok, build adımı yok.
Paylaşımlı hostinge ZIP açıp `install.php`'yi açmak yeterli.

---

## Kurulum

### Sunucuya (Apache / XAMPP / paylaşımlı hosting)

1. `dist/hicms-<sürüm>.zip` içeriğini sunucu köküne açın.
2. Tarayıcıdan `https://siteniz.com/install.php` adresini açın.
3. Üç ekranı tamamlayın: gereksinim denetimi → veritabanı → site ve yönetici.
4. Kurulum bitince `install.php` dosyasını silin (sihirbaz kendini kilitler ama silmek en temizi).

Veritabanı yoksa ve kullanıcınızın yetkisi varsa sihirbaz onu **kendisi oluşturur**.

### Geliştirme (PHP yerleşik sunucusu)

```bash
php -S localhost:8000 router.php
```

Ön yüz `http://localhost:8000`, kurulum `/install.php`, panel `/admin/`.

---

## Güncelleme

**Aynı ZIP hem kurulum hem güncelleme için kullanılır.**

- **Otomatik:** Panel → Sistem → Güncellemeler → *Güncelle*. GitHub Releases kontrol edilir,
  paket indirilir, veritabanı yedeği alınır, dosyalar değiştirilir, migration'lar çalışır.
- **Elle:** Aynı ekrandan ZIP yükleyin.

Güncellemede **değişenler:** `src/ admin/ index.php install.php router.php hi-cron.php hicms.json`
**Dokunulmayanlar:** `config.php content/ themes/ plugins/`

Herhangi bir adım başarısız olursa dosyalar yedekten geri yüklenir ve işlem iptal edilir.

Temalar ve eklentiler **kendi depolarından** ayrı sürümlenir: künyedeki `repository`
alanı okunur, güncelleme kendi ekranından yapılır.

---

## Klasör yapısı

```
HiCMS/
├── index.php            Ön yüz giriş noktası
├── install.php          Kurulum sihirbazı (kurulumdan sonra kendini kilitler)
├── router.php           PHP yerleşik sunucusu için yönlendirici
├── hi-cron.php          Planlı görev işleyici (cron ya da HTTP)
├── hicms.json           Çekirdek künyesi — güncelleyici sürümü buradan okur
│
├── src/                 ÇEKİRDEK — müşteri kurulumunda değiştirilmez
│   ├── Kernel.php           Servis konteyneri ve önyükleme
│   ├── Autoloader.php       PSR-4 sınıf yükleyici
│   ├── functions.php        Tema API'si (hi_title, hi_content, hi_menu…)
│   ├── Auth/                Kimlik doğrulama, roller, izinler
│   ├── Content/             Bloklar, içerik türleri, alanlar, kalıcı bağlantılar
│   ├── Database/            Bağlantı, sorgu kurucu, şema, migration
│   ├── Events/              Dağıtıcı + tipli olay sınıfları
│   ├── Extension/           Künye okuma, tema/eklenti paketi kurulumu
│   ├── Http/                İstek, yanıt, rota tablosu, CSRF, URL
│   ├── Install/             Gereksinim denetimi, kurulum
│   ├── Media/               Güvenli dosya yükleme
│   ├── Model/               Entry, User, Term, Comment, MediaItem
│   ├── Plugin/              Eklenti taban sınıfı ve yükleyici
│   ├── Repository/          Veri erişimi
│   ├── Routing/             Ön yüz denetleyicisi
│   ├── Scheduler/           Planlı görevler
│   ├── Theme/               Tema yönetimi, şablonlar, menü, bileşen
│   └── Update/              Yedekleme, sürüm kontrolü, güncelleyici
│
├── admin/               YÖNETİM PANELİ
├── themes/hiblog/       Varsayılan tema
├── plugins/             HiSEO · HiLang · HiTypes · HiForms · HiMedia
├── content/             Yüklemeler, yedekler, önbellek (yazılabilir olmalı)
└── build/               lint.php · smoke.php · dbtest.php · updatetest.php
                         upgradetest.php · make-zip.php · PLAN-0.3.0.md
```

---

## Tema geliştirme

Bir tema için gereken en az iki dosya: `hicms.json` ve `index.php`.

### Şablon hiyerarşisi

| Bağlam | Aranan dosyalar |
|---|---|
| Ana sayfa | `home.php` → `index.php` |
| Tek içerik | `single-{tür}-{kısa-ad}.php` → `single-{tür}.php` → `single.php` → `index.php` |
| Sayfa | `page-{kısa-ad}.php` → `page.php` → `single.php` → `index.php` |
| Taksonomi | `taxonomy-{taksonomi}-{kısa-ad}.php` → `taxonomy-{taksonomi}.php` → `taxonomy.php` → `archive.php` |
| Tür arşivi | `archive-{tür}.php` → `archive.php` → `index.php` |
| Yazar / Arama | `author.php` / `search.php` → `archive.php` → `index.php` |
| 404 | `404.php` → `index.php` |

### Döngü

```php
<?php while (hi_has_next()) : hi_the_entry(); ?>
    <article <?= hi_entry_class() ?>>
        <h2><a href="<?= esc_url(hi_permalink()) ?>"><?= hi_title() ?></a></h2>
        <p><?= hi_excerpt() ?></p>
    </article>
<?php endwhile; ?>

<?= hi_pagination() ?>
```

### Sık kullanılan tema fonksiyonları

```
hi_header() hi_footer() hi_sidebar() hi_part() hi_head() hi_foot()
hi_title() hi_permalink() hi_content() hi_excerpt() hi_date() hi_thumbnail()
hi_author_name() hi_author_url() hi_reading_time() hi_terms() hi_the_term()
hi_body_class() hi_entry_class() hi_document_title() hi_meta_description()
hi_is_home() hi_is_single() hi_is_page() hi_is_archive() hi_is_taxonomy()
hi_menu() hi_widgets() hi_comments() hi_comments_open() hi_pagination()
hi_query() hi_related() hi_adjacent() hi_all_terms()
```

### URL yapısı

```
/                       Ana sayfa            /arama?q=…      Arama
/sayfa/2                Sayfalanmış akış     /feed           RSS
/yazi/{kısa-ad}         Tek yazı             /{kısa-ad}      Statik sayfa
/kategori/{kısa-ad}     Kategori arşivi      /yazar/{kısa-ad} Yazar arşivi
/etiket/{kısa-ad}       Etiket arşivi
```

Kalıcı bağlantı yapısı Ayarlar → Bağlantılar'dan değiştirilir; rota tablosu da
aynı ayardan üretildiği için ikisi asla ayrışmaz.

---

## Eklenti geliştirme

```
plugins/eklentim/
├── hicms.json      künye (zorunlu)
├── plugin.php      giriş noktası
└── migrations/     kendi tabloları (isteğe bağlı)
```

`hicms.json`:

```json
{
  "name": "Eklentim",
  "slug": "eklentim",
  "version": "1.0.0",
  "type": "plugin",
  "repository": "kullanici/eklentim",
  "requires": { "hicms": "0.2.0", "php": "8.2" },
  "class": "Eklentim\\Plugin",
  "namespace": "Eklentim\\",
  "autoload": "src"
}
```

`plugin.php`:

```php
use HiCMS\Events\Content\Saved;
use HiCMS\Plugin\Plugin;

final class MyPlugin extends Plugin
{
    public function boot(): void
    {
        hi_listen(Saved::class, function (Saved $event): void {
            if ($event->justPublished()) {
                // yayınlandı: önbelleği temizle, bildirim gönder…
            }
        });
    }

    public function activate(): void   { /* varsayılan ayarlar */ }
    public function deactivate(): void { /* veri SİLİNMEZ */ }
    public function uninstall(): void  { /* burada silinir */ }
}
```

### Tipli olaylar

| Olay | Ne zaman |
|---|---|
| `System\Booted` | Çekirdek hazır (rota, panel sayfası, görev kaydı) |
| `System\PluginsLoaded` | Tüm etkin eklentiler yüklendi |
| `System\ThemeLoaded` | Tema `functions.php` çalıştı |
| `Content\Saving` | Kayıt öncesi — değiştirilebilir, `cancel()` ile iptal edilebilir |
| `Content\Saved` | Kayıt sonrası — `justPublished()` ile ilk yayın ayırt edilir |
| `Content\Deleted` | Kalıcı silme sonrası |
| `Render\TemplateResolving` | Şablon aday listesi — `prepend()` ile devralınır |
| `Render\BlockRendering` | Blok HTML'i — `wrap()` ile sarmalanır |
| `Admin\MenuBuilding` | Panel menüsü — `add()` / `remove()` |
| `Auth\LoggedIn` | Başarılı giriş |
| `Media\Uploaded` | Dosya kaydedildi (HiMedia türevleri burada üretir) |
| `Extension\Toggled` | Eklenti/tema açıldı-kapandı |
| `Update\Completed` | Çekirdek/tema/eklenti güncellendi |

### Adlandırılmış kancalar

Eylem: `routing.register` · `routing.before` · `theme.head` · `theme.foot` ·
`admin.head` · `admin.notices` · `admin.footer` · `admin.page.{eklenti}` ·
`forms.submitted` · `comment.submitted`

Filtre: `content.title` · `content.body` · `theme.body_class` ·
`theme.entry_class` · `theme.document_title` · `theme.meta_description`

### Panel sayfası açmak

```php
hi_listen(MenuBuilding::class, fn($e) => $e->add('content', [
    'slug' => 'plugin:eklentim', 'label' => 'Eklentim',
    'icon' => 'inbox', 'url' => 'plugin.php?eklenti=eklentim',
]));

hi_on('admin.page.eklentim', function (): void {
    admin_head(['title' => 'Eklentim', 'slug' => 'plugin:eklentim']);
    // … ekran …
    admin_foot();
});
```

Eklentiler panele dosya kopyalamaz — bu yüzden çekirdek güncellemesi eklenti
ekranlarını etkilemez.

---

## Blok sistemi

İçerik HTML yığını değil, sıralı blok dizisidir:

```json
[
  { "type": "paragraph", "data": { "text": "…", "lead": true } },
  { "type": "image", "data": { "mediaId": 12, "width": "wide" } }
]
```

Yerleşik bloklar: paragraf, başlık, liste, alıntı, görsel, galeri, kod,
video/gömme, bilgi kutusu, eylem çağrısı, ayıraç, özel HTML.

Yeni blok eklemek tek çağrı — panel formu `fields` tanımından **otomatik** üretilir:

```php
hi_register_block('fiyat', [
    'label'  => 'Fiyat Kutusu',
    'icon'   => 'target',
    'group'  => 'düzen',
    'fields' => [
        ['key' => 'baslik', 'type' => 'text',   'label' => 'Başlık'],
        ['key' => 'tutar',  'type' => 'number', 'label' => 'Tutar'],
    ],
    'render' => fn(array $d, $r): string
        => '<div class="fiyat"><h3>' . $r->text($d['baslik']) . '</h3></div>',
]);
```

---

## İçerik türleri

Kutudan yalnızca **Yazı** ve **Sayfa** gelir. Yeni tür bir JSON dosyası bırakmakla
tanımlanır — `themes/temam/content-types/portfolyo.json`:

```json
{
  "name": "portfolyo",
  "labels": { "singular": "Proje", "plural": "Portfolyo" },
  "icon": "grid",
  "route": "proje",
  "archive": "portfolyo",
  "taxonomies": ["category"],
  "supports": ["blocks", "excerpt", "image"],
  "fields": [
    { "key": "musteri", "type": "text",   "label": "Müşteri" },
    { "key": "yil",     "type": "number", "label": "Yıl" }
  ]
}
```

Tür kaydedildiği anda panelde menüsü, listesi ve düzenleme formu; ön yüzde
`/proje/{kısa-ad}` ve `/portfolyo` rotaları oluşur. Panelden kod yazmadan tür
oluşturmak için **HiTypes** eklentisi.

---

## Eklentiler

Beşi de pakette **pasif** gelir; panelden açılır ve her biri tek başına çalışır.

| Eklenti | Ne yapar |
|---|---|
| **HiSEO** | `sitemap.xml`, `robots.txt`, JSON-LD, canonical, 301 yönlendirme yöneticisi |
| **HiTypes** | Panelden içerik türü ve özel alan oluşturma |
| **HiForms** | Form oluşturucu + gönderi kutusu, bal küpü spam koruması, e-posta bildirimi |
| **HiMedia** | Türev boyutlar + WebP üretimi, EXIF yön düzeltmesi, srcset'i doldurur |
| **HiLang** | İçerik dil sürümleri, `/en/…` önekli adresler, hreflang, dil değiştirici |

---

## Çoklu dil

Kaynak metinler Türkçedir; dil dosyaları Türkçeden hedef dile eşler.

```php
_e('Yazılar');                        // basar
echo esc_html(__('Yazılar'));         // kaçırır
echo __f('%d yazı bulundu', 12);      // yer tutuculu
```

`__f()` kullanın — çeviri metnindeki tek `%` işaretlerini kaçırdığı için
"%100 hazır" gibi metinler bozulmaz.

Yeni dil: `src/languages/en_US.php` örnek alınarak dosya eklenir, `config.php`
içinde `'locale'` değiştirilir. **İçerik** çokluluğu için HiLang.

---

## Planlı görevler

Sistem cron'u gerekmez: yanıt gönderildikten sonra süresi gelmiş **bir** görev
çalışır. Yoğun kurulumlarda gerçek cron önerilir:

```bash
*/5 * * * * php /yol/hi-cron.php
```

Çekirdek görevleri: zamanlanmış yayın, sürüm kontrolü, günlük temizliği,
geçici dosya temizliği.

---

## Geliştirme araçları

```bash
php build/lint.php     # sözdizimi + autoloader denetimi (135 dosya)
php build/smoke.php    # veritabanısız davranış testi (226 denetim)
php build/make-zip.php # dağıtım paketi üretir ve doğrular
```

### Gerçek veritabanına karşı doğrulama

`smoke.php` veritabanına dokunmaz, bu yüzden yalnızca çalışma anında görülen
hataları (geçersiz SQL, kilitli dosya, oturum akışı) kaçırır. Aşağıdaki iki
betik kurulumu ve güncellemeyi gerçekten çalıştırır.

Her ikisi de verilen veritabanını **düşürür** ve `config.php` üzerine yazar;
yalnızca geliştirme ortamında çalıştırın.

```bash
php -S 127.0.0.1:8130 router.php
php build/dbtest.php --url=http://127.0.0.1:8130 --user=root --pass=gizli --db=hicms_test
```

`dbtest.php` (92 denetim): kurulum sihirbazını çalıştırır, çekirdek tabloların
kurulduğunu doğrular, ön yüzün ve 20 panel sayfasının açıldığını görür, ardından
içerik oluşturma / düzenleme / silme, terim ekleme, ayar kaydetme, eklenti
etkinleştirme ve planlı görev çalıştırma işlemlerinin sonucunu doğrudan
veritabanından okur. Ayrıca satır içi biçim gidiş-dönüşünü (editörün ürettiği
HTML kaydedilip geri okunduğunda aynen duruyor mu, zararlısı düşüyor mu),
iyimser kilidi (eski sayaçla ve alan hiç gelmeden kayıt reddediliyor mu) ve
0.2.0'da bulunup düzeltilen altı güvenlik hatasının geri gelmediğini denetler.

Bu betik hiç JavaScript kullanmaz; tamamının geçmesi aynı zamanda "JS kapalıyken
panel çalışıyor" kanıtıdır.

```bash
php build/make-zip.php
php -S 127.0.0.1:8180 -t /tmp/hicms-site /tmp/hicms-site/router.php
php build/updatetest.php --zip=dist/hicms-0.2.0.zip --site=/tmp/hicms-site \
    --url=http://127.0.0.1:8180 --user=root --pass=gizli --db=hicms_update
```

`updatetest.php` (21 denetim): paketi temiz bir dizine açıp kurar, kullanıcı
teması / eklentisi / medyası ekler, sonra **aynı paketi** panelden güncelleme
olarak uygular. Çekirdeğin yenilendiğini (artık dosya silinir, bozulan varlık
geri gelir) ve `config.php`, `content/`, `themes/`, `plugins/` içeriğinin
dokunulmadan kaldığını doğrular. Bu akış `admin/system.php` üzerinden yürür —
güncelleme kendi çalıştığı dizini yeniden yazdığı için bazı hatalar yalnızca
burada görünür.

```bash
php build/upgradetest.php /tmp/hicms-eski http://127.0.0.1:8196 3306 hicms_upgrade \
    dist/hicms-0.2.0.zip dist/hicms-0.3.0.zip
```

`upgradetest.php` (20 denetim): **gerçek sürüm yükseltmesi.** `updatetest.php`
aynı sürümü uyguladığı için migration çalıştırmaz; bu betik ESKİ paketle kurup
YENİ paketi güncelleme olarak uygular. Yeni migration'ların mevcut kuruluma
uygulandığını (tablo ve sütun eklendi mi), sürüm damgasının güncellendiğini,
mevcut içerik/terim/ayarların korunduğunu ve yükseltmeden sonra ön yüz ile
editörün çalıştığını doğrular. Güncellemeyi dağıtan bir CMS için en değerli
test bu: şema değişikliği veri kaybettiriyorsa başka hiçbir şeyin önemi yok.

---

## Güvenlik notları

- Şifreler `password_hash()` ile saklanır, algoritma güncellenince sessizce yenilenir.
- "Beni hatırla" çerezi seçici:doğrulayıcı biçimindedir; doğrulayıcı yalnızca hash'li saklanır ve her kullanımda döner.
- Başarısız girişler kademeli gecikmeye tabidir (5. denemeden sonra 30s → 15dk).
- Tüm form gönderimleri CSRF anahtarıyla doğrulanır.
- Yükleme dizininde PHP çalıştırma `.htaccess` ile kapatılır; SVG yüklenirse temizlenir.
- Çıktı bağlama göre kaçırılır: `esc_html` / `esc_attr` / `esc_url` / `esc_json`.
- Yedek, önbellek ve geçici dizinler web erişimine kapatılır.
- Kim neyi ne zaman değiştirdi bilgisi denetim günlüğüne yazılır.

---

## Lisans

GPL-3.0-or-later
