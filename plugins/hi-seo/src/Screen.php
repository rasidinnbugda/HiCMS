<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Content\Field;
use HiCMS\Extension\Settings;
use HiCMS\Model\Entry;
use HiCMS\Support\Str;

/**
 * Panel ekranı.
 *
 * Tek kanca (`admin.page.hi-seo`), beş sekme ve bir alt ekran:
 *
 *   ozet         → ölçüler, site haritası parçaları, dikkat isteyen içerikler
 *   icerik       → içerik başına SEO durumu; satır tıklanınca düzenleme ekranı
 *   yonlendirme  → yönlendirme listesi + ekleme formu
 *   kayip        → 404 günlüğü ve önerilen yönlendirme
 *   ayarlar      → hi_settings() tanımından üretilen form
 *
 * YOĞUNLUK SÖZLEŞMESİ: tablo satırı TEK SATIR (34px), alt bilgi başlığın
 * yanında (`.cell-sub`), form ekranları `narrow`, liste ekranlarında
 * `$page['count']`.
 *
 * JS KAPALIYKEN: her işlem normal form gönderimi. Betik yalnızca karakter
 * sayaçlarını ve canlı önizlemeyi ekler; önizlemenin kendisi sunucuda basılır.
 */
final class Screen
{
    /** @var array<string, string> */
    private const TABS = [
        'ozet'        => 'Özet',
        'icerik'      => 'İçerik',
        'yonlendirme' => 'Yönlendirme',
        'kayip'       => 'Bulunamayan',
        'ayarlar'     => 'Ayarlar',
    ];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly Settings $settings,
        private readonly EntrySeo $entries,
        private readonly Redirects $redirects,
        private readonly Sitemap $sitemap,
        private readonly string $scriptUrl,
        private readonly string $styleUrl,
    ) {
    }

    public function render(): null
    {
        admin_require('settings.manage');

        $tab = (string) ($_GET['sekme'] ?? 'ozet');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'ozet';

        if (hi()->request()->isPost()) {
            $this->handle($tab);
        }

        hi_admin_style($this->styleUrl);

        $entryId = (int) ($_GET['id'] ?? 0);

        if ($tab === 'icerik' && $entryId > 0) {
            $this->entryScreen($entryId);

            return null;
        }

        match ($tab) {
            'icerik'      => $this->contentScreen(),
            'yonlendirme' => $this->redirectScreen(),
            'kayip'       => $this->missingScreen(),
            'ayarlar'     => $this->settingsScreen(),
            default       => $this->overviewScreen(),
        };

        return null;
    }

    /* =====================================================================
     * POST
     * ================================================================== */

    private function handle(string $tab): void
    {
        $back = $this->url(['sekme' => $tab]);

        admin_verify($back);

        $action = (string) ($_POST['islem'] ?? '');

        if ($action === 'ayar-kaydet') {
            $section = (string) ($_POST['bolum'] ?? '');

            if (!array_key_exists($section, $this->settings->sections())) {
                admin_redirect($back, 'error', 'Bilinmeyen ayar bölümü.');
            }

            /*
             * Bölüm sınırı önemli: yalnızca formda GERÇEKTEN bulunan alanlar
             * yazılır. Tüm alanları kapsayan bir kaydetme, o formda olmayan
             * anahtarları "işaretsiz onay kutusu" sayıp sıfırlardı.
             */
            $this->settings->save($_POST, $section);

            admin_redirect($back, 'success', 'Ayarlar kaydedildi.');
        }

        if ($action === 'icerik-kaydet') {
            $id    = (int) ($_POST['id'] ?? 0);
            $entry = $id > 0 ? hi()->content()->find($id) : null;

            if ($entry === null) {
                admin_redirect($this->url(['sekme' => 'icerik']), 'error', 'İçerik bulunamadı.');
            }

            if (!hi()->auth()->canEdit($entry->authorId)) {
                admin_redirect(
                    $this->url(['sekme' => 'icerik']),
                    'error',
                    'Bu içeriği yalnızca yazarı veya bir editör düzenleyebilir.'
                );
            }

            $this->entries->save($id, $_POST);

            admin_redirect(
                $this->url(['sekme' => 'icerik', 'id' => (string) $id]),
                'success',
                'SEO alanları kaydedildi.'
            );
        }

        if ($action === 'yonlendirme-ekle') {
            $result = $this->redirects->add(
                (string) ($_POST['kaynak'] ?? ''),
                (string) ($_POST['hedef'] ?? ''),
                (int) ($_POST['kod'] ?? 301)
            );

            admin_redirect(
                $this->url(['sekme' => 'yonlendirme']),
                $result['ok'] ? 'success' : 'error',
                $result['ok'] ? 'Yönlendirme eklendi.' : $result['error']
            );
        }

        if ($action === 'yonlendirme-sil') {
            $this->redirects->delete((int) ($_POST['id'] ?? 0));

            admin_redirect($this->url(['sekme' => 'yonlendirme']), 'success', 'Yönlendirme silindi.');
        }

        if ($action === 'kayip-sil') {
            $this->redirects->deleteMissing((int) ($_POST['id'] ?? 0));

            admin_redirect($this->url(['sekme' => 'kayip']), 'success', 'Kayıt silindi.');
        }

        if ($action === 'kayip-temizle') {
            $count = $this->redirects->clearMissing();

            admin_redirect(
                $this->url(['sekme' => 'kayip']),
                'success',
                Str::format('%d kayıt silindi.', $count)
            );
        }

        admin_redirect($back, 'error', 'Bilinmeyen işlem.');
    }

    /* =====================================================================
     * Özet
     * ================================================================== */

    private function overviewScreen(): void
    {
        $coverage = $this->entries->coverage();
        $parts    = (bool) $this->settings->get('sitemap', true) ? $this->sitemap->parts() : [];
        $missing  = $this->redirects->missingCount();
        $weak     = $this->weakEntries(8);

        $sitemapUrls = 0;

        foreach ($parts as $part) {
            $sitemapUrls += $part['count'];
        }

        $this->head('ozet', [
            'description' => 'Etiketler, site haritası, yönlendirmeler ve kırılan adresler.',
        ]);

        echo ui_metrics([
            [
                'label' => 'Site haritası',
                'value' => (bool) $this->settings->get('sitemap', true) ? Str::number($sitemapUrls) : 'kapalı',
                'note'  => (bool) $this->settings->get('sitemap', true)
                    ? Str::format('%d dosya · sitemap.xml', count($parts))
                    : 'ayarlardan açılır',
                'href'  => hi()->urls()->to('sitemap.xml'),
            ],
            [
                'label' => 'Arama açıklaması',
                'value' => Str::number($coverage['seo_description']),
                'note'  => 'içerikte özel açıklama var',
            ],
            [
                'label' => 'Yönlendirme',
                'value' => Str::number($this->redirects->redirectCount()),
                'note'  => 'etkin kural',
            ],
            [
                'label' => 'Bulunamayan adres',
                'value' => Str::number($missing),
                'note'  => $missing > 0 ? 'kurtarılmayı bekliyor' : 'kırık bağlantı yok',
            ],
        ]);
        ?>

        <div class="cols-main mt-3">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Dikkat isteyen içerikler</h2>
                            <p class="panel-sub">En düşük puanlı yayınlar; puan başlık ve açıklamadan hesaplanır</p>
                        </div>
                    </header>

                    <?php if ($weak !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Başlık</th>
                                        <th class="num">Başlık</th>
                                        <th class="num">Açıklama</th>
                                        <th class="num">Puan</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($weak as $row) : ?>
                                        <?php $entry = $row['entry']; ?>
                                        <tr data-status="<?= esc_attr($entry->status) ?>">
                                            <td class="edge">
                                                <a class="cell-title"
                                                   href="<?= esc_url($this->url(['sekme' => 'icerik', 'id' => (string) $entry->id])) ?>">
                                                    <?= esc_html($entry->title !== '' ? $entry->title : '(başlıksız)') ?>
                                                </a>
                                                <span class="cell-sub"><?= esc_html('/' . $entry->slug) ?></span>
                                            </td>
                                            <td class="num"><?= (int) mb_strlen($row['audit']['title']) ?></td>
                                            <td class="num"><?= (int) mb_strlen($row['audit']['description']) ?></td>
                                            <td class="num"><?= $this->scorePill($row['audit']['score']) ?></td>
                                            <td class="fit">
                                                <div class="row-acts">
                                                    <a class="icon-btn"
                                                       href="<?= esc_url($this->url(['sekme' => 'icerik', 'id' => (string) $entry->id])) ?>"
                                                       title="SEO alanları" aria-label="SEO alanları">
                                                        <?= admin_icon('target', 15) ?>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <?= ui_empty('check', 'Eksik yok', 'Yayındaki içeriklerin başlık ve açıklamaları yeterli.') ?>
                    <?php endif; ?>
                </section>

                <section class="panel mt-3">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Site haritası dosyaları</h2>
                            <p class="panel-sub">İçerik türüne göre bölünür; her dosya en çok
                                <?= (int) $this->sitemap->chunkSize() ?> adres taşır</p>
                        </div>
                    </header>

                    <?php if ($parts !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Dosya</th>
                                        <th>Kapsam</th>
                                        <th class="num">Adres</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($parts as $part) : ?>
                                        <tr>
                                            <td class="mono small">
                                                <?= esc_html('/sitemap-' . $part['key'] . '.xml') ?>
                                            </td>
                                            <td class="small dim"><?= esc_html($part['label']) ?></td>
                                            <td class="num"><?= esc_html(Str::number($part['count'])) ?></td>
                                            <td class="fit">
                                                <a class="icon-btn" target="_blank" rel="noopener"
                                                   href="<?= esc_url($this->sitemap->partUrl($part['key'])) ?>"
                                                   title="Aç" aria-label="Aç"><?= admin_icon('external', 15) ?></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <?= ui_empty('list', 'Site haritası boş',
                            'Yayında içerik yok ya da site haritası ayarlardan kapatılmış.') ?>
                    <?php endif; ?>
                </section>
            </div>

            <div>
                <section class="box">
                    <header class="box-head"><?= admin_icon('globe', 15) ?>Adresler</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">sitemap.xml</span>
                                <span class="v"><a target="_blank" rel="noopener"
                                    href="<?= esc_url(hi()->urls()->to('sitemap.xml')) ?>">aç</a></span></li>
                            <li><span class="k">robots.txt</span>
                                <span class="v"><a target="_blank" rel="noopener"
                                    href="<?= esc_url(hi()->urls()->to('robots.txt')) ?>">aç</a></span></li>
                            <li><span class="k">RSS</span>
                                <span class="v"><a target="_blank" rel="noopener"
                                    href="<?= esc_url(hi()->urls()->to('feed')) ?>">aç</a></span></li>
                        </ul>
                    </div>
                </section>

                <section class="box">
                    <header class="box-head"><?= admin_icon('shield', 15) ?>Dizin durumu</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Arama motorları</span>
                                <span class="v"><?= (bool) hi_option('search_engine_index', true)
                                    ? 'açık' : 'KAPALI' ?></span></li>
                            <li><span class="k">Yapısal veri</span>
                                <span class="v"><?= (bool) $this->settings->get('json_ld', true)
                                    ? 'açık' : 'kapalı' ?></span></li>
                            <li><span class="k">Paylaşım etiketleri</span>
                                <span class="v"><?= esc_html($this->socialLabel()) ?></span></li>
                            <li><span class="k">Dizin dışı içerik</span>
                                <span class="v"><?= esc_html(Str::number($coverage['seo_noindex'])) ?></span></li>
                        </ul>

                        <?php if (!(bool) hi_option('search_engine_index', true)) : ?>
                            <p class="hint is-err mt-2">
                                Site ayarlarında arama motorlarına kapalı: robots.txt her şeyi engelliyor
                                ve tüm sayfalar noindex basıyor.
                            </p>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if (!$this->redirects->hasTable('seo_notfound')) : ?>
                    <?= ui_notice('warning', '404 günlüğü tablosu yok. Sistem → Güncellemeler bölümünden '
                        . 'veritabanı güncellemesini çalıştırın.') ?>
                <?php endif; ?>
            </div>
        </div>

        <?php
        admin_foot();
    }

    /* =====================================================================
     * İçerik listesi
     * ================================================================== */

    private function contentScreen(): void
    {
        $types    = hi()->types()->all();
        $typeName = (string) ($_GET['tur'] ?? '');
        $typeName = isset($types[$typeName]) ? $typeName : (string) array_key_first($types);
        $search   = trim((string) ($_GET['ara'] ?? ''));
        $pageNum  = max(1, (int) ($_GET['sayfa'] ?? 1));

        $result = hi()->content()->query([
            'type'    => $typeName,
            'status'  => 'all',
            'search'  => $search,
            'page'    => $pageNum,
            'perPage' => self::PER_PAGE,
        ]);

        $options = [];

        foreach ($types as $name => $type) {
            $options[$name] = $type->plural;
        }

        $this->head('icerik', [
            'description' => 'İçerik başına arama başlığı, açıklaması ve dizin durumu.',
            'count'       => $result['total'],
        ]);
        ?>

        <section class="panel">
            <div class="filters">
                <form class="row" method="get" action="plugin.php">
                    <input type="hidden" name="eklenti" value="hi-seo">
                    <input type="hidden" name="sekme" value="icerik">

                    <label class="sr-only" for="s-tur">İçerik türü</label>
                    <?= ui_select('tur', $options, $typeName, ['id' => 's-tur', 'class' => 'input select w-auto']) ?>

                    <label class="sr-only" for="s-ara">Ara</label>
                    <input class="input" type="search" id="s-ara" name="ara" style="width:180px"
                           value="<?= esc_attr($search) ?>" placeholder="Başlıkta ara">

                    <button class="btn" type="submit"><?= admin_icon('filter', 15) ?>Süz</button>

                    <?php if ($search !== '') : ?>
                        <a class="btn btn-ghost"
                           href="<?= esc_url($this->url(['sekme' => 'icerik', 'tur' => $typeName])) ?>">Sıfırla</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($result['items'] !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Başlık</th>
                                <th>Arama başlığı</th>
                                <th class="num">Başlık</th>
                                <th class="num">Açıklama</th>
                                <th>Durum</th>
                                <th class="num">Puan</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['items'] as $entry) : ?>
                                <?php
                                $values = $this->entries->values($entry);
                                $audit  = $this->entries->analyse($entry, $values);
                                $editUrl = $this->url(['sekme' => 'icerik', 'id' => (string) $entry->id]);
                                ?>
                                <tr data-status="<?= esc_attr($entry->status) ?>">
                                    <td class="edge">
                                        <a class="cell-title" href="<?= esc_url($editUrl) ?>">
                                            <?= esc_html($entry->title !== '' ? $entry->title : '(başlıksız)') ?>
                                        </a>
                                        <span class="cell-sub"><?= esc_html('/' . $entry->slug) ?></span>
                                        <?php if (!empty($values['seo_noindex'])) : ?>
                                            <span class="pill is-mute no-dot">noindex</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small dim">
                                        <?= $values['seo_title'] !== ''
                                            ? esc_html(Str::limit((string) $values['seo_title'], 48))
                                            : '<span class="muted">içerik başlığı</span>' ?>
                                    </td>
                                    <td class="num"><?= $this->lengthPill(
                                        mb_strlen($audit['title']),
                                        EntrySeo::TITLE_LIMIT
                                    ) ?></td>
                                    <td class="num"><?= $this->lengthPill(
                                        mb_strlen($audit['description']),
                                        EntrySeo::DESCRIPTION_LIMIT
                                    ) ?></td>
                                    <td><?= ui_status($entry->status) ?></td>
                                    <td class="num"><?= $this->scorePill($audit['score']) ?></td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url($editUrl) ?>"
                                               title="SEO alanları" aria-label="SEO alanları">
                                                <?= admin_icon('target', 15) ?>
                                            </a>
                                            <a class="icon-btn" href="content-edit.php?id=<?= (int) $entry->id ?>"
                                               title="İçeriği düzenle" aria-label="İçeriği düzenle">
                                                <?= admin_icon('edit', 15) ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= ui_pagination(
                    (int) $result['page'],
                    (int) $result['pages'],
                    fn(int $n): string => $this->url([
                        'sekme' => 'icerik',
                        'tur'   => $typeName,
                        'ara'   => $search,
                        'sayfa' => (string) $n,
                    ]),
                    (int) $result['total']
                ) ?>
            <?php else : ?>
                <?= ui_empty('target', 'Kayıt yok', 'Bu türde içerik bulunamadı.') ?>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();
    }

    /* =====================================================================
     * İçerik SEO düzenleme
     * ================================================================== */

    private function entryScreen(int $entryId): void
    {
        $entry = hi()->content()->find($entryId);

        if ($entry === null) {
            admin_redirect($this->url(['sekme' => 'icerik']), 'error', 'İçerik bulunamadı.');
        }

        $values  = $this->entries->values($entry);
        $preview = $this->entries->preview($entry, $values);
        $audit   = $this->entries->analyse($entry, $values);
        $type    = hi()->types()->get($entry->type);

        /*
         * Panel verisi JSON öğesi olarak basılır, satır içi betikle DEĞİL:
         * anında sayfa geçişinde gelen <script> etiketleri çalıştırılmıyor.
         */
        hi_admin_data('hi-seo-onizleme', [
            'titleLimit'       => EntrySeo::TITLE_LIMIT,
            'descriptionLimit' => EntrySeo::DESCRIPTION_LIMIT,
            'fallbackTitle'    => $entry->title,
            'fallbackText'     => $entry->summary(EntrySeo::DESCRIPTION_LIMIT + 40),
            'url'              => $preview['url'],
        ]);

        hi_admin_script($this->scriptUrl);

        $this->head('icerik', [
            'title'       => 'SEO · ' . ($entry->title !== '' ? Str::limit($entry->title, 40) : '(başlıksız)'),
            'description' => 'Arama sonucunda görünen metinler ve dizin kuralları.',
            'narrow'      => true,
            'crumbTail'   => [['label' => 'İçerik', 'url' => $this->url(['sekme' => 'icerik'])], ['label' => 'Alanlar']],
            'actions'     => '<a class="btn" href="content-edit.php?id=' . (int) $entry->id . '">'
                . admin_icon('edit', 15) . 'İçeriği düzenle</a>'
                . '<a class="btn" target="_blank" rel="noopener" href="'
                . esc_url(hi()->links()->forEntry($entry)) . '">' . admin_icon('eye', 15) . 'Görüntüle</a>',
        ]);
        ?>

        <form method="post" action="<?= esc_url($this->url(['sekme' => 'icerik', 'id' => (string) $entry->id])) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="icerik-kaydet">
            <input type="hidden" name="id" value="<?= (int) $entry->id ?>">

            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Arama sonucu önizlemesi</h2>
                        <p class="panel-sub">Başlık <?= EntrySeo::TITLE_LIMIT ?>, açıklama
                            <?= EntrySeo::DESCRIPTION_LIMIT ?> karakterden sonra kırpılır</p>
                    </div>
                    <div class="panel-actions"><?= $this->scorePill($audit['score']) ?></div>
                </header>

                <div class="panel-body">
                    <?php // Sunucuda basılır; JS varsa yazdıkça güncellenir. ?>
                    <div class="seo-serp" data-seo-preview>
                        <span class="seo-serp-url"><?= esc_html($preview['url']) ?></span>
                        <span class="seo-serp-title" data-seo-preview-title><?= esc_html(
                            Str::limit($preview['title'], EntrySeo::TITLE_LIMIT)
                        ) ?></span>
                        <span class="seo-serp-text" data-seo-preview-text><?= esc_html(
                            Str::limit($preview['description'], EntrySeo::DESCRIPTION_LIMIT)
                        ) ?></span>
                    </div>

                    <div class="mt-3">
                        <?= ui_field(
                            'Arama başlığı',
                            ui_input('seo_title', (string) $values['seo_title'], [
                                'id'           => 'seo_title',
                                'class'        => 'input',
                                'maxlength'    => '180',
                                'placeholder'  => $entry->title,
                                'data-seo-count' => (string) EntrySeo::TITLE_LIMIT,
                                'data-seo-field' => 'title',
                            ])
                            . '<p class="hint" data-seo-counter="title">'
                            . esc_html(Str::format('%d / %d karakter', mb_strlen($audit['title']), EntrySeo::TITLE_LIMIT))
                            . '</p>',
                            esc_html((string) (EntrySeo::field('seo_title')?->help ?? '')),
                            'seo_title'
                        ) ?>

                        <?= ui_field(
                            'Arama açıklaması',
                            '<textarea class="input" id="seo_description" name="seo_description" rows="3"'
                            . ' maxlength="400" data-seo-count="' . EntrySeo::DESCRIPTION_LIMIT . '"'
                            . ' data-seo-field="text">'
                            . esc_html((string) $values['seo_description']) . '</textarea>'
                            . '<p class="hint" data-seo-counter="text">'
                            . esc_html(Str::format(
                                '%d / %d karakter',
                                mb_strlen($audit['description']),
                                EntrySeo::DESCRIPTION_LIMIT
                            ))
                            . '</p>',
                            esc_html((string) (EntrySeo::field('seo_description')?->help ?? '')),
                            'seo_description'
                        ) ?>

                        <div class="field-row">
                            <?= ui_field(
                                'Odak ifade',
                                ui_input('seo_focus', (string) $values['seo_focus'], ['id' => 'seo_focus']),
                                esc_html((string) (EntrySeo::field('seo_focus')?->help ?? '')),
                                'seo_focus'
                            ) ?>

                            <?= ui_field(
                                'Canonical adres',
                                ui_input('seo_canonical', (string) $values['seo_canonical'], [
                                    'id'          => 'seo_canonical',
                                    'class'       => 'input mono',
                                    'placeholder' => '/yol veya https://…',
                                ]),
                                esc_html((string) (EntrySeo::field('seo_canonical')?->help ?? '')),
                                'seo_canonical'
                            ) ?>
                        </div>

                        <?= ui_field(
                            'Paylaşım görseli',
                            ui_input('seo_image', (string) $values['seo_image'], [
                                'id'          => 'seo_image',
                                'class'       => 'input mono',
                                'placeholder' => 'https://…/gorsel.jpg',
                            ]),
                            esc_html((string) (EntrySeo::field('seo_image')?->help ?? ''))
                                . ($entry->image !== null
                                    ? ' Şu an öne çıkan görsel kullanılıyor.' : ''),
                            'seo_image'
                        ) ?>

                        <?= ui_switch(
                            'seo_noindex',
                            (bool) $values['seo_noindex'],
                            'Dizine eklenmesin',
                            (string) (EntrySeo::field('seo_noindex')?->help ?? '')
                        ) ?>
                    </div>
                </div>

                <footer class="panel-foot">
                    <a class="btn" href="<?= esc_url($this->url(['sekme' => 'icerik'])) ?>">Listeye dön</a>
                    <span class="spacer"></span>
                    <button class="btn btn-primary" type="submit"><?= admin_icon('save', 15) ?>Kaydet</button>
                </footer>
            </section>
        </form>

        <section class="panel mt-3">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Denetim</h2>
                    <p class="panel-sub"><?= esc_html($type?->singular ?? $entry->type) ?> ·
                        <?= esc_html(Str::number(Str::words($entry->plainText()))) ?> kelime</p>
                </div>
            </header>

            <?php if ($audit['issues'] !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <tbody>
                            <?php foreach ($audit['issues'] as $issue) : ?>
                                <tr>
                                    <td class="fit"><?= $this->levelPill($issue['level']) ?></td>
                                    <td><?= esc_html($issue['text']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?= ui_empty('check', 'Sorun yok', 'Başlık, açıklama ve görsel tamam.') ?>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();
    }

    /* =====================================================================
     * Yönlendirmeler
     * ================================================================== */

    private function redirectScreen(): void
    {
        $pageNum = max(1, (int) ($_GET['sayfa'] ?? 1));
        $result  = $this->redirects->page($pageNum, self::PER_PAGE);

        // 404 listesinden gelen ön dolgu.
        $source = $this->redirects->normalizeSource((string) ($_GET['kaynak'] ?? ''));
        $source = $source === '/' ? '' : ltrim($source, '/');
        $target = trim((string) ($_GET['hedef'] ?? ''));

        $statuses = [];

        foreach (Redirects::STATUSES as $code => $label) {
            $statuses[(string) $code] = $label;
        }

        $this->head('yonlendirme', [
            'description' => 'Eski adresleri yeni sayfalara taşıyın. Joker: /blog/* → /yazi/*',
            'count'       => $result['total'],
        ]);

        if (!$this->redirects->hasTable()) {
            echo ui_notice('error', 'Yönlendirme tablosu yok. Sistem → Güncellemeler bölümünden '
                . 'veritabanı güncellemesini çalıştırın.');
        }
        ?>

        <div class="cols-main">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Kurallar</h2>
                            <p class="panel-sub">Yönlendirme, içerik çözülmeden önce uygulanır</p>
                        </div>
                    </header>

                    <?php if ($result['items'] !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Kaynak</th>
                                        <th>Hedef</th>
                                        <th class="num">Kod</th>
                                        <th class="num">İsabet</th>
                                        <th>Son</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($result['items'] as $row) : ?>
                                        <tr>
                                            <td class="mono small">
                                                <?= esc_html((string) $row['source']) ?>
                                                <?php if (str_ends_with((string) $row['source'], '*')) : ?>
                                                    <span class="cell-sub">joker</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="mono small dim"><?= esc_html((string) $row['target']) ?></td>
                                            <td class="num"><?= (int) $row['status'] ?></td>
                                            <td class="num"><?= esc_html(Str::number((int) $row['hits'])) ?></td>
                                            <td class="when"><?= ui_time($row['last_hit_at'] ?? null) ?></td>
                                            <td class="fit">
                                                <form method="post"
                                                      action="<?= esc_url($this->url(['sekme' => 'yonlendirme'])) ?>">
                                                    <?= hi_csrf_field() ?>
                                                    <input type="hidden" name="islem" value="yonlendirme-sil">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <button class="icon-btn" type="submit" aria-label="Sil"
                                                        <?= ui_confirm('Bu yönlendirme silinecek.') ?>>
                                                        <?= admin_icon('trash', 15) ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?= ui_pagination(
                            (int) $result['page'],
                            (int) $result['pages'],
                            fn(int $n): string => $this->url(['sekme' => 'yonlendirme', 'sayfa' => (string) $n]),
                            (int) $result['total']
                        ) ?>
                    <?php else : ?>
                        <?= ui_empty('link', 'Yönlendirme yok', 'Sağdaki formdan ilk kuralı ekleyin.') ?>
                    <?php endif; ?>
                </section>
            </div>

            <div>
                <section class="box">
                    <header class="box-head"><?= admin_icon('plus', 15) ?>Yönlendirme ekle</header>
                    <form method="post" action="<?= esc_url($this->url(['sekme' => 'yonlendirme'])) ?>">
                        <?= hi_csrf_field() ?>
                        <input type="hidden" name="islem" value="yonlendirme-ekle">

                        <div class="box-body">
                            <?= ui_field(
                                'Kaynak yol',
                                '<div class="input-group"><span class="addon">/</span>'
                                . ui_input('kaynak', $source, [
                                    'id'          => 'r-src',
                                    'class'       => 'input mono',
                                    'placeholder' => 'eski-yazi',
                                ]) . '</div>',
                                'Site köküne göre eski adres. Sonuna <code>*</code> koyarsanız alt yollar da eşleşir.',
                                'r-src',
                                true
                            ) ?>

                            <?= ui_field(
                                'Hedef',
                                ui_input('hedef', $target, [
                                    'id'          => 'r-dst',
                                    'class'       => 'input mono',
                                    'placeholder' => 'yazi/yeni-adres',
                                ]),
                                'Göreli yol ya da tam adres. Joker kaynakta <code>*</code> kalan yolu taşır.',
                                'r-dst',
                                true
                            ) ?>

                            <?= ui_field('Kod', ui_select('kod', $statuses, '301', ['id' => 'r-code']), '', 'r-code') ?>
                        </div>

                        <footer class="box-foot">
                            <span class="spacer"></span>
                            <button class="btn btn-sm btn-primary" type="submit">Ekle</button>
                        </footer>
                    </form>
                </section>

                <section class="box">
                    <header class="box-head"><?= admin_icon('info', 15) ?>Nasıl çalışır</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Tam eşleşme</span><span class="v">/eski → /yeni</span></li>
                            <li><span class="k">Joker</span><span class="v">/blog/* → /yazi/*</span></li>
                            <li><span class="k">Dış adres</span><span class="v">https://…</span></li>
                            <li><span class="k">Sorgu taşıma</span>
                                <span class="v"><?= (bool) $this->settings->get('keep_query', false)
                                    ? 'açık' : 'kapalı' ?></span></li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <?php
        admin_foot();
    }

    /* =====================================================================
     * Bulunamayan adresler
     * ================================================================== */

    private function missingScreen(): void
    {
        $pageNum = max(1, (int) ($_GET['sayfa'] ?? 1));
        $result  = $this->redirects->missingPage($pageNum, self::PER_PAGE);

        $paths = array_map(static fn(array $row): string => (string) $row['path'], $result['items']);
        $hints = $this->redirects->suggestions($paths);

        $this->head('kayip', [
            'description' => '404 dönen adresler. Önerilen hedefle tek tıkta yönlendirmeye çevirin.',
            'count'       => $result['total'],
            'actions'     => $result['items'] !== []
                ? '<form method="post" action="' . esc_url($this->url(['sekme' => 'kayip'])) . '">'
                    . hi_csrf_field()
                    . '<input type="hidden" name="islem" value="kayip-temizle">'
                    . '<button class="btn" type="submit"'
                    . ui_confirm('Bulunamayan adres günlüğü tamamen silinecek.') . '>'
                    . admin_icon('trash', 15) . 'Günlüğü temizle</button></form>'
                : '',
        ]);

        if (!$this->redirects->hasTable('seo_notfound')) {
            echo ui_notice('error', '404 günlüğü tablosu yok. Sistem → Güncellemeler bölümünden '
                . 'veritabanı güncellemesini çalıştırın.');
        } elseif (!(bool) $this->settings->get('log_404', true)) {
            echo ui_notice('info', 'Kayıt tutma ayarlardan kapatılmış; liste büyümez.');
        }
        ?>

        <section class="panel">
            <?php if ($result['items'] !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Yol</th>
                                <th>Öneri</th>
                                <th class="num">İsabet</th>
                                <th>Son</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['items'] as $row) : ?>
                                <?php
                                $path = (string) $row['path'];
                                $hint = $hints[$path] ?? null;
                                $link = $this->url([
                                    'sekme'  => 'yonlendirme',
                                    'kaynak' => $path,
                                    'hedef'  => (string) ($hint['path'] ?? ''),
                                ]);
                                ?>
                                <tr>
                                    <td class="mono small"><?= esc_html($path) ?></td>
                                    <td class="small dim">
                                        <?php if ($hint !== null) : ?>
                                            <?= esc_html(Str::limit($hint['title'], 40)) ?>
                                            <span class="cell-sub"><?= (int) $hint['score'] ?>%</span>
                                        <?php else : ?>
                                            <span class="muted">eşleşme yok</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= esc_html(Str::number((int) $row['hits'])) ?></td>
                                    <td class="when"><?= ui_time($row['last_hit_at'] ?? null) ?></td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url($link) ?>"
                                               title="Yönlendirme oluştur" aria-label="Yönlendirme oluştur">
                                                <?= admin_icon('link', 15) ?>
                                            </a>
                                            <form method="post"
                                                  action="<?= esc_url($this->url(['sekme' => 'kayip'])) ?>">
                                                <?= hi_csrf_field() ?>
                                                <input type="hidden" name="islem" value="kayip-sil">
                                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                <button class="icon-btn" type="submit" aria-label="Sil">
                                                    <?= admin_icon('x', 15) ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= ui_pagination(
                    (int) $result['page'],
                    (int) $result['pages'],
                    fn(int $n): string => $this->url(['sekme' => 'kayip', 'sayfa' => (string) $n]),
                    (int) $result['total']
                ) ?>
            <?php else : ?>
                <?= ui_empty('check', 'Kırık bağlantı yok',
                    'Ziyaretçiler 404 almadı ya da günlük temizlenmiş.') ?>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();
    }

    /* =====================================================================
     * Ayarlar
     * ================================================================== */

    private function settingsScreen(): void
    {
        $values = $this->settings->all();

        $this->head('ayarlar', [
            'description' => 'Her bölüm kendi formunda kaydedilir.',
            'narrow'      => true,
        ]);

        foreach ($this->settings->sections() as $key => $section) {
            ?>
            <form method="post" action="<?= esc_url($this->url(['sekme' => 'ayarlar'])) ?>" class="mt-3">
                <?= hi_csrf_field() ?>
                <input type="hidden" name="islem" value="ayar-kaydet">
                <input type="hidden" name="bolum" value="<?= esc_attr($key) ?>">

                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title"><?= esc_html($section['label']) ?></h2>
                            <?php if ($section['description'] !== '') : ?>
                                <p class="panel-sub"><?= esc_html($section['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    </header>

                    <div class="panel-body">
                        <?php foreach ($section['fields'] as $field) : ?>
                            <?= $this->control($field, $values[$field->key] ?? $field->default) ?>
                        <?php endforeach; ?>
                    </div>

                    <footer class="panel-foot">
                        <span class="spacer"></span>
                        <button class="btn btn-primary" type="submit">Kaydet</button>
                    </footer>
                </section>
            </form>
            <?php
        }

        admin_foot();
    }

    /**
     * Tek ayar alanının denetimi.
     *
     * Alan türleri `Field::TYPES` kümesinden geliyor; panelin özel alan
     * denetimleriyle aynı sınıfları kullanıyor ki iki ayrı görünüm doğmasın.
     */
    private function control(Field $field, mixed $value): string
    {
        $id   = 'ayar-' . $field->key;
        $help = $field->help !== '' ? esc_html($field->help) : '';

        if ($field->type === 'switch') {
            return '<div class="field">' . ui_switch($field->key, (bool) $value, $field->label, $field->help) . '</div>';
        }

        if ($field->type === 'select') {
            return ui_field(
                $field->label,
                ui_select($field->key, $field->options, (string) $value, ['id' => $id]),
                $help,
                $id
            );
        }

        if ($field->type === 'lines') {
            $lines = is_array($value) ? implode("\n", array_map('strval', $value)) : (string) $value;

            return ui_field(
                $field->label,
                '<textarea class="input mono" id="' . esc_attr($id) . '" name="' . esc_attr($field->key) . '"'
                . ' rows="' . max(2, $field->rows) . '">' . esc_html($lines) . '</textarea>',
                $help,
                $id
            );
        }

        if ($field->type === 'textarea' || $field->type === 'code') {
            return ui_field(
                $field->label,
                '<textarea class="input' . ($field->type === 'code' ? ' mono' : '') . '"'
                . ' id="' . esc_attr($id) . '" name="' . esc_attr($field->key) . '"'
                . ' rows="' . max(2, $field->rows) . '">' . esc_html((string) $value) . '</textarea>',
                $help,
                $id
            );
        }

        if ($field->type === 'number') {
            return ui_field(
                $field->label,
                ui_input($field->key, (string) (int) $value, [
                    'id'    => $id,
                    'type'  => 'number',
                    'class' => 'input',
                    'step'  => '1',
                ]),
                $help,
                $id
            );
        }

        return ui_field(
            $field->label,
            ui_input($field->key, (string) $value, [
                'id'          => $id,
                'class'       => 'input',
                'placeholder' => $field->placeholder,
            ]),
            $help,
            $id
        );
    }

    /* =====================================================================
     * Ortak parçalar
     * ================================================================== */

    /**
     * @param array<string, mixed> $page
     */
    private function head(string $tab, array $page = []): void
    {
        /** @var list<array{label: string, url?: string}> $breadcrumb */
        $breadcrumb = [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiSEO']];

        foreach ((array) ($page['crumbTail'] ?? []) as $crumb) {
            $breadcrumb[] = $crumb;
        }

        unset($page['crumbTail']);

        admin_head(array_merge([
            'title'      => 'SEO',
            'slug'       => 'plugin:hi-seo',
            'breadcrumb' => $breadcrumb,
        ], $page));

        echo ui_tabs(array_map(
            fn(string $key, string $label): array => [
                'label'  => $label,
                'url'    => $this->url(['sekme' => $key]),
                'active' => $key === $tab,
            ],
            array_keys(self::TABS),
            array_values(self::TABS)
        ));
    }

    /**
     * @param array<string, string> $params
     */
    private function url(array $params = []): string
    {
        $query = array_filter(
            $params,
            static fn(mixed $value): bool => $value !== '' && $value !== null
        );

        return Plugin::SELF_URL . ($query !== [] ? '&' . http_build_query($query) : '');
    }

    private function scorePill(int $score): string
    {
        $class = $score >= 85 ? 'is-ok' : ($score >= 60 ? 'is-warn' : 'is-err');

        return '<span class="pill ' . $class . ' no-dot">' . (int) $score . '</span>';
    }

    private function lengthPill(int $length, int $limit): string
    {
        if ($length === 0) {
            return '<span class="pill is-err no-dot">0</span>';
        }

        $class = $length > $limit ? 'is-warn' : 'is-ok';

        return '<span class="pill ' . $class . ' no-dot">' . $length . '</span>';
    }

    private function levelPill(string $level): string
    {
        [$class, $label] = match ($level) {
            'err'  => ['is-err', 'eksik'],
            'warn' => ['is-warn', 'uyarı'],
            default => ['is-info', 'bilgi'],
        };

        return '<span class="pill ' . $class . '">' . $label . '</span>';
    }

    private function socialLabel(): string
    {
        return match ((string) $this->settings->get('og_mode', 'auto')) {
            'always' => 'her zaman',
            'off'    => 'kapalı',
            default  => 'otomatik',
        };
    }

    /**
     * Puanı en düşük yayınlar.
     *
     * Tek sorguyla sınırlı bir küme çekilir ve bellekte değerlendirilir;
     * içerik başına sorgu atmak özet ekranını yüz sorguya çıkarırdı.
     *
     * @return list<array{entry: Entry, audit: array<string, mixed>}>
     */
    private function weakEntries(int $limit): array
    {
        $result = hi()->content()->query([
            'type'        => 'all',
            'visibleOnly' => true,
            'page'        => 1,
            'perPage'     => 60,
            'orderBy'     => 'published_at',
        ]);

        $rows = [];

        foreach ($result['items'] as $entry) {
            $audit = $this->entries->analyse($entry);

            if ($audit['score'] >= 100) {
                continue;
            }

            $rows[] = ['entry' => $entry, 'audit' => $audit];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => $a['audit']['score'] <=> $b['audit']['score']
        );

        return array_slice($rows, 0, $limit);
    }
}
