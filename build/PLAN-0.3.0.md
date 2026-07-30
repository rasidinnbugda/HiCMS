# HiCMS 0.3.0 — uygulama planı ve bağlayıcı kararlar

Bu dosya 0.3.0 revizyonunun karar kaydıdır. Envanter altı alanı dosya:satır
kanıtıyla haritaladı, dört alt sistem tasarlandı, her tasarım bağımsız bir
eleştirmen tarafından çürütülmeye çalışıldı. Dördü de "düzeltilmeli" kararı
aldı; aşağıdaki kısıtlar o eleştirilerden çıktı ve **uygulanmadan ilgili iş
bitmiş sayılmaz**.

## Kullanıcının verdiği kararlar (tartışılmaz)

1. Zengin metin = **sınırlı HTML**. contenteditable + kendi araç çubuğu. Blok
   verisi JSON kalır; metin alanında `strong em a code s sup sub mark` durur.
   Sunucuda DOM tabanlı gerçek ayrıştırıcı + öznitelik allowlist'i.
2. Panel = **ilerici geliştirme**. Sunucu render kalır, SPA'ya geçilmez.
   Eklenti panel sayfaları çalışmaya devam eder. JS kapalıyken temel işlevler
   çalışır.
3. Tasarım = **yoğun profesyonel araç**. Sessiz palet, `--accent #95389e`.
4. Sistem odakları: yazma deneyimi, eklenti altyapısı, hız/ölçek, güvenlik.
5. Sürüm 0.3.0. Yayında kurulum yok, içerik göçü gerekmiyor.

Türetilmiş yön (kullanıcı onayladı): **iki tonlu ayrım** — çerçeve (kenar
çubuğu + komut şeridi) her iki temada koyu bir alet, çalışma yüzeyi kâğıt.
İmza öğe: sürekli görünen **komut şeridi** (konum + en hızlı giriş + canlı
durum bildirimi).

## Dokunulmaz sözleşmeler

Bunları değiştirmek sessiz kırılma üretir:

- `admin_head()` açık `<main>` ve açık div'lerle döner, `admin_foot()` kapatır.
  Bölmek 18 çekirdek sayfayı ve dört eklentinin `screen()`'ini aynı anda kırar.
- Beş eklenti paneli şu sınıflara bağlı: `.panel .panel-head .panel-body
  .table-wrap table.data .pill .box .node .field .input .btn .icon-btn .col`.
- `admin.js` altı sınıfı **davranış** kancası olarak kullanır: `.side-nav a`,
  `.notice, .toast`, `.modal`, `.drop`, `.toasts/.toast`, `.bar-search input`.
  `.drop` tüm dosya bırakma davranışının tek kancası.
- `.avatar-md`, `.avatar-xl`, `.tabs-line` grep'te ölü görünür; `ui_avatar()`
  (ui.php:391) ve `ui_tabs()` (ui.php:494) dinamik birleştiriyor.
- `$page['actions']`, `ui_field()`'in `$hint` ve `$control` parametreleri **ham
  HTML** geçer; 12 sayfa elle string kuruyor. Kaçırmaya çevirmek hepsini bozar.
- `ui_pagination()` dördüncü parametresi callable; dört sayfa closure geçiyor.
- Panel slug biçimi `content:<tür>`, `terms:<taksonomi>`, `plugin:<slug>`.
  Değişirse eklenti menü vurgusu kaybolur.

## Eleştirilerden çıkan kritik kısıtlar

### Zengin metin

- **C0 karakterlerini toptan silme YASAK.** U+0000–U+001F aralığı `\n \r \t`
  içerir; toptan silmek mevcut tüm içeriği bozar. Yalnızca gerçekten zararlı
  olanlar (U+0000, BOM, yön değiştirme karakterleri) hedeflenir.
- **Native ESM güncellemeyi bozar.** Giriş noktasına `?v=` basılıyor ama
  `import './blocks.js'` belirteçleri damgasız kalıyor; güncellemeden sonra
  tarayıcı eski modülü servis eder. Ya import belirteçleri sunucuda damgalanır
  ya tek dosyada kalınır.
- **`Sanitizer` `Kernel`'e bağımlı olamaz.** Allowlist'i `hi_filter()`'a açmak
  `hi()` → `Kernel::instance()` zincirini doğurur; temizleyici kurulum ve test
  yollarında çekirdek olmadan da çalışmak zorunda.
- **İçerik kaybı tuzağı:** blok verisi yalnızca `bloklar` gizli alanından gelir
  (content-edit.php:231 + editor.js:64). Boş değer `json_decode` → `null` →
  `blocks=[]` → içerik silinir. Alanın **hiç gelmemesi** ile **boş gelmesi**
  ayrılmalı ve hiç gelmediyse bloklar KORUNMALI.

### Panel dinamizmi

- **Bölge değişimi `<script>` çalıştırmaz.** `window.HI_EDITOR` satır içi
  betiği `<main>` içinde (content-edit.php:520-527); anında sayfa geçişi blok
  editörünü öldürür. Editör verisi `<main>` dışına ya da JSON uç noktasına
  taşınmalı.
- **Tembel modüller tek seferlik.** Bölge değişiminden sonra editör, palet,
  otomatik kaydetme ve satır içi düzenleme yeniden kurulmalı; kurulum bir
  "mount/unmount" sözleşmesine bağlanmalı.
- **Oturum dosyası kilidi tüm "anında" iddiasını çürütüyor.** `Auth.php:72`
  düz `session_start()` çağırıyor, depoda hiç `session_write_close()` yok;
  eşzamanlı istekler sıraya giriyor. Bu düzeltilmeden hiçbir hız iddiası
  geçerli değil.
- **Sıfır sonuç durumu:** `liste` bölgesi yalnızca sonuç varken basılıyor
  (content.php:172); boş aramada istemci eski tabloyu ekranda bırakır. Bölge
  her iki durumda da var olmalı.

### Yazma deneyimi

- **Entry başına tek autosave slotu veri yok ediyor** — tam olarak çakışmanın
  gerçekleştiği senaryoda. Slot kullanıcı başına ayrılmalı.
- **İyimser kilit fail-open.** `(int) ($_POST['beklenen_surum'] ?? 0)` → alan
  hiç gelmezse denetim atlanıyor; önbellekten açılmış eski sayfa kilidi
  bypass ediyor. Alan yokluğu hata sayılmalı.
- **`revision_no` yalnızca `save()`'de artıyor.** `bulkStatus()`
  (ContentRepository.php:456-485) tek UPDATE ile yazıyor ve sayacı artırmıyor;
  kilit bu yolu hiç görmüyor. Sayaç veritabanı düzeyinde artırılmalı.
- **Migration `jobs` satırı yazmamalı.** `build/smoke.php:351` veritabanı
  bilgisi olmayan bir `Connection` ile TÜM migration'ları kuru çalıştırıyor;
  ham `$db->insert()` çağrısı smoke'u ölümcül hatayla düşürür ve veritabanı
  erişilebilir bir makinede "veritabanısız" test gerçek satır yazar.
  `if ($schema->isDryRun()) { return; }` ile korunmalı.

### Eklenti altyapısı

- **hi-lang için göç GEREKİYOR.** `plugins/hi-lang/plugin.php:79-96,400`
  `plugin.hi-lang.locales` anahtarında farklı bir şema tutuyor; "göç yok"
  iddiası bu eklenti için yanlış.
- **`SettingsRegistry::save()` kısmi girdide ayarları sessizce sıfırlıyor.**
  İşaretsiz checkbox POST'a hiç girmez; eksik anahtarın anlamı tanımlanmalı
  (yok = değişmedi, yok = false değil).
- **`deactivate()` kancaları sökemez.** `Dispatcher::forget($key, ?callable)`
  dinleyici verilmezse o kancanın TÜM dinleyicilerini siler; eklentiye ait
  olanları ayırt edecek bir kayıt gerekiyor.
- **"UNIQUE > 767 bayt = hata" kuralı yanlış.** Çekirdeğin kendi şemasını
  reddeder (0002_create_content_tables.php:42) ve modern MySQL'de geçersiz.
- **Boş `trusted_proxies` girişi kilitliyor.** `Auth.php:362-393`
  `throttleSeconds()` yalnızca IP'ye bakıyor; ters vekil arkasında tüm
  kullanıcılar aynı IP'den görünür ve site çapında giriş kilitlenir.

## Envanterin bulduğu, 0.2.0'da HÂLÂ DURAN hatalar

Bunlar yeni tasarımdan bağımsız, şu an yayındaki paketin hataları:

| # | Hata | Yer |
|---|---|---|
| 1 | `settings.php` POST'u `$_POST['bolum']`'e göre switch'liyor ama doğrulama ve yönlendirme `$tab` (GET) kullanıyor; ikisi ayrışırsa **yanlış bölümün alanları kaydediliyor** | settings.php:37,42,131 |
| 2 | `system.php` izin listelerinde **`run-jobs` yok** → izin boşluğu | system.php:43-49,111 |
| 3 | `logout.php` hiç CSRF doğrulaması yapmıyor | logout.php:11-15 |
| 4 | Güncelleme denetimi **her istekte iki kez** çalışıyor | bootstrap.php:277 + index.php:216 |
| 5 | `admin_menu()` her sayfa yüklemesinde tür başına `countOfType(...,'pending')` + `statusCounts()` + `check()` sorguları atıyor | bootstrap.php:209,242,277 |
| 6 | `admin_verify()` boş `$redirectTo` ile 419 basıp çıkıyor; `login.php:25` boş çağırıyor → süresi dolmuş anahtar **çıplak hata sayfası** veriyor | bootstrap.php:120, login.php:25 |
| 7 | `content-delete.php` anahtarı `$_GET['_t']`'den okuyor, `admin_verify()` yalnızca `$_POST['_token']` okuyor — iki ayrı CSRF yolu | content-delete.php:22 |
| 8 | JS kapalıyken bozulan eylemler: menüde taşıma, bileşen alanı seçimi, tema/eklenti etkinleştirme-silme-güncelleme, yedek geri yükleme, medya seçimi, blok kaydetme (inline `onclick` gizli input'a yazıyor) | menus.php:288, widgets.php:168, appearance.php:169, plugins.php:175, system.php:319 |
| 9 | `ui_panel_open()` / `ui_panel_close()` ölü kod | ui.php:584,598 |

## Tamamlanan

### Panel tasarımı — temel (doğrulandı)

- Belirteç katmanı: boşluk, yazı ölçeği, ağırlık, yükseklik, z-index tokenleri
  eklendi. 0.2.0'da yalnızca 35 renk/yarıçap tokeni vardı; "8px ızgara" iddiası
  gerçekleşmiyordu (7/9/10/11/13/15/18/26/52 gibi 11 ızgara dışı değer).
- **Karanlık tema JS'siz çalışıyor.** `@media (prefers-color-scheme: dark)`
  eklendi; `data-scheme` artık önceden yazılmıyor. `color-scheme` eklendi, yerli
  form denetimleri de karanlıkta doğru.
- **Google Fonts bağımlılığı kaldırıldı**, sistem yığınına geçildi.
- `font-weight: 550` sekiz yerde kullanılıyordu ama font isteğinde yoktu →
  400/500/600 ölçeğine indi, hiyerarşi artık gerçekten çalışıyor.
- `.btn` ve `.input` aynı `--h-ctl` tokeninden: **28px**, artık hizalı
  (0.2.0'da 34px vs ~39px).
- `.panel { overflow: hidden }` kaldırıldı → **yapışkan tablo başlığı** ve panel
  içi açılır menüler artık mümkün.
- `.metrics` `auto-fit` oldu; 3 ve 5 öğeli çağrılarda düzen bozulmuyordu.
- Tablo satırı **43px → 34px**, tek satır, kırpmalı. Durum bilgisi satırın sol
  kenarında 2px şerit (`td.edge` + `tr[data-status]`).
- Liste ekranları **tam genişlik**; ölçü sınırı `.content.is-narrow`'a taşındı.
- Alt bilgi şeridi (57px) kaldırıldı, bilgisi kenar çubuğu altına indi.
- Komut şeridi: konum + arama + canlı durum (`#hi-activity`).
- Varlık sürümleme içerik damgasına geçti (`admin_asset()`); `?v=VERSION` sürüm
  değişmeden yapılan değişiklikleri önbellekte takıyordu.
- `.btn-icon` silindi (canlı `.icon-btn`'in kullanılmayan kopyası).

Ölçülen: tablo satırı 34px (hepsi eşit), kenar çubuğu 200px, komut şeridi 44px,
içerik `max-width: none`, `.btn`/`.input` 28px, öznitelik yokken karanlık tema
geliyor, yatay kaydırma yok. `build/dbtest.php` 57/57 geçiyor.

## Sıradaki iş

Sırayla: oturum kilidi (her şeyin önkoşulu) → zengin metin + temizleyici →
dinamizm katmanı → yazma deneyimi → eklenti altyapısı → beş eklentinin taşınması
→ 0.2.0'dan gelen dokuz hata → doğrulama ve paket.
