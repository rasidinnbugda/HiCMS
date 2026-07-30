<?php

declare(strict_types=1);

namespace HiMedia;

use HiCMS\Content\Field;
use HiCMS\Extension\Settings;
use HiCMS\Model\MediaItem;
use HiCMS\Support\Str;

/**
 * HiMedia panel ekranı.
 *
 * Beş sekme: genel bakış, kitaplık (klasör/etiket), toplu işlem, denetim,
 * ayarlar. Her yazma işlemi POST + CSRF + 303 yönlendirme kalıbıyla yapılır;
 * JS KAPALIYKEN hepsi çalışır. JS yalnızca toplu işlemi kaldığı yerden
 * sürdürerek düğmeye tekrar tekrar basma zorunluluğunu kaldırır.
 */
final class Screen
{
    /** Sekme anahtarı → başlık. */
    private const TABS = [
        ''         => 'Genel bakış',
        'kitaplik' => 'Kitaplık',
        'toplu'    => 'Toplu işlem',
        'denetim'  => 'Denetim',
        'ayarlar'  => 'Ayarlar',
    ];

    private const PER_PAGE = 25;

    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
        private readonly Pipeline $pipeline,
        private readonly Usage $usage,
        private readonly string $scriptUrl,
    ) {
    }

    public function render(): null
    {
        admin_require('media.upload');

        if (hi()->request()->isPost()) {
            $this->handle();
        }

        match ($this->tab()) {
            'kitaplik' => $this->library(),
            'toplu'    => $this->batch(),
            'denetim'  => $this->audit(),
            'ayarlar'  => $this->settingsScreen(),
            default    => $this->overview(),
        };

        admin_foot();

        return null;
    }

    /* ---------------------------------------------------------------------
     * Adres ve iskelet
     * ------------------------------------------------------------------ */

    private function tab(): string
    {
        $tab = (string) ($_GET['sekme'] ?? '');

        return array_key_exists($tab, self::TABS) ? $tab : '';
    }

    /**
     * @param array<string, string|int|null> $extra
     */
    private function url(array $extra = []): string
    {
        $params = array_merge(['sekme' => $this->tab()], $extra);
        $params = array_filter(
            $params,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0
        );

        return Plugin::SELF_URL . ($params !== [] ? '&' . http_build_query($params) : '');
    }

    private function head(string $description, ?int $count = null, bool $narrow = false): void
    {
        $tab = $this->tab();

        admin_head([
            'title'       => 'Medya boru hattı',
            'slug'        => 'plugin:hi-media',
            'description' => $description,
            'count'       => $count,
            'narrow'      => $narrow,
            'breadcrumb'  => [
                ['label' => 'Medya', 'url' => 'media.php'],
                ['label' => 'HiMedia'],
                ['label' => self::TABS[$tab]],
            ],
        ]);

        $tabs = [];

        foreach (self::TABS as $key => $label) {
            $tabs[] = [
                'label'  => $label,
                'url'    => Plugin::SELF_URL . ($key !== '' ? '&sekme=' . $key : ''),
                'active' => $key === $tab,
            ];
        }

        echo ui_tabs($tabs, 'line');
    }

    /* ---------------------------------------------------------------------
     * Yazma işlemleri
     * ------------------------------------------------------------------ */

    private function handle(): void
    {
        admin_verify($this->url());

        match ((string) ($_POST['islem'] ?? '')) {
            'ayarlar'     => $this->saveSettings(),
            'toplu'       => $this->runBatch(),
            'uret'        => $this->regenerateOne(),
            'atama'       => $this->saveAssignment(),
            'alt'         => $this->saveAltTexts(),
            'klasor-ekle' => $this->addFolder(),
            'klasor-sil'  => $this->removeFolder(),
            'sil'         => $this->deleteMedia(),
            'artik'       => $this->sweepOrphans(),
            default       => null,
        };
    }

    private function saveSettings(): void
    {
        admin_require('settings.manage');

        /*
         * Bölüm bölüm kaydedilir. Tek formda iki bölüm var ve `save()` bölüm
         * sınırında çalışıyor: işaretsiz bir anahtar POST'a hiç girmediği için
         * "kapalı" sayılabilmesi ancak o bölümün alan listesi bilinirken doğru.
         */
        foreach (array_keys($this->settings->sections()) as $section) {
            $this->settings->save($_POST, $section);
        }

        admin_redirect($this->url(['sekme' => 'ayarlar']), 'success', 'HiMedia ayarları kaydedildi.');
    }

    private function runBatch(): void
    {
        if (!Processor::hasGd()) {
            admin_redirect($this->url(['sekme' => 'toplu']), 'error', 'GD eklentisi olmadan üretim yapılamaz.');
        }

        $force  = isset($_POST['zorla']);
        $cursor = max(0, (int) ($_POST['imlec'] ?? 0));
        $size   = max(1, min(50, (int) $this->settings->get('batch', 8)));

        $items   = $this->store->pending($size, $force, $cursor);
        $done    = 0;
        $failed  = [];
        $bytes   = 0;
        $lastId  = $cursor;

        foreach ($items as $item) {
            $lastId = max($lastId, $item->id);
            $result = $this->pipeline->run($item, audit: false);

            if ($result['ok']) {
                $done++;
                $bytes += $result['bytes'];

                continue;
            }

            $failed[] = $item->filename . ' — ' . $result['reason'];
        }

        if ($done > 0) {
            $this->pipeline->record(Str::format('Toplu üretim: %d görsel, %s kopya', $done, Str::bytes($bytes)));
        }

        // İlk üç gerekçe yeter; kalanı sayıyla bildirilir.
        foreach (array_slice($failed, 0, 3) as $reason) {
            admin_flash('warning', $reason);
        }

        if (count($failed) > 3) {
            admin_flash('warning', Str::format('%d dosya daha atlandı.', count($failed) - 3));
        }

        if ($items === []) {
            admin_redirect($this->url(['sekme' => 'toplu']), 'success', 'Sıra boş: işlenecek görsel kalmadı.');
        }

        admin_redirect($this->url([
            'sekme'   => 'toplu',
            'imlec'   => $lastId,
            'islenen' => count($items),
            'basari'  => $done,
            'zorla'   => $force ? 1 : null,
        ]), $done > 0 ? 'success' : 'warning', Str::format(
            '%d görsel işlendi, %d atlandı.',
            $done,
            count($failed)
        ));
    }

    private function regenerateOne(): void
    {
        $item = hi()->mediaRepo()->find((int) ($_POST['id'] ?? 0));

        if ($item === null) {
            admin_redirect($this->url(), 'error', 'Dosya bulunamadı.');
        }

        $result = $this->pipeline->run($item);

        admin_redirect(
            $this->url(['dosya' => $item->id]),
            $result['ok'] ? 'success' : 'warning',
            $result['ok']
                ? Str::format('%d kopya üretildi (%s).', $result['count'], Str::bytes($result['bytes']))
                : 'Üretilemedi: ' . $result['reason']
        );
    }

    private function saveAssignment(): void
    {
        $item = hi()->mediaRepo()->find((int) ($_POST['id'] ?? 0));

        if ($item === null) {
            admin_redirect($this->url(['sekme' => 'kitaplik']), 'error', 'Dosya bulunamadı.');
        }

        $item->title = mb_substr(trim((string) ($_POST['baslik'] ?? '')), 0, 250);
        $item->alt   = mb_substr(trim((string) ($_POST['alt'] ?? '')), 0, 250);

        hi()->mediaRepo()->update($item);

        $this->store->assign(
            $item->id,
            (int) ($_POST['klasor'] ?? 0),
            Store::parseTags((string) ($_POST['etiketler'] ?? ''))
        );

        admin_redirect($this->url(['sekme' => 'kitaplik', 'dosya' => $item->id]), 'success', 'Dosya güncellendi.');
    }

    private function saveAltTexts(): void
    {
        $input = (array) ($_POST['alt'] ?? []);
        $saved = 0;

        foreach (array_slice($input, 0, 100, true) as $id => $text) {
            $text = trim((string) (is_scalar($text) ? $text : ''));

            if ($text === '') {
                continue;
            }

            $item = hi()->mediaRepo()->find((int) $id);

            if ($item === null) {
                continue;
            }

            $item->alt = mb_substr($text, 0, 250);
            hi()->mediaRepo()->update($item);
            $saved++;
        }

        admin_redirect(
            $this->url(['sekme' => 'denetim']),
            $saved > 0 ? 'success' : 'warning',
            $saved > 0 ? Str::format('%d görselin alt metni kaydedildi.', $saved) : 'Doldurulmuş alan yok.'
        );
    }

    private function addFolder(): void
    {
        $result = $this->store->createFolder((string) ($_POST['ad'] ?? ''));

        admin_redirect(
            $this->url(['sekme' => 'kitaplik']),
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Klasör oluşturuldu.' : $result['error']
        );
    }

    private function removeFolder(): void
    {
        $this->store->deleteFolder((int) ($_POST['id'] ?? 0));

        admin_redirect(
            $this->url(['sekme' => 'kitaplik']),
            'success',
            'Klasör silindi; içindeki dosyalar klasörsüz kaldı.'
        );
    }

    private function deleteMedia(): void
    {
        $item = hi()->mediaRepo()->find((int) ($_POST['id'] ?? 0));

        if ($item === null) {
            admin_redirect($this->url(['sekme' => 'denetim']), 'error', 'Dosya bulunamadı.');
        }

        if ($item->authorId !== hi()->auth()->id() && !hi()->auth()->can('media.delete_others')) {
            admin_redirect($this->url(['sekme' => 'denetim']), 'error', 'Başkalarının dosyalarını silme yetkiniz yok.');
        }

        // Son bir denetim: sayfa açıldıktan sonra kullanılmaya başlanmış olabilir.
        if ($this->usage->isUsed($item)) {
            admin_redirect(
                $this->url(['sekme' => 'denetim']),
                'warning',
                'Bu dosya artık bir içerikte kullanılıyor; silinmedi.'
            );
        }

        $copies = $this->pipeline->purgeFiles($item->id);

        hi()->uploader()->deleteFiles($item);
        $this->store->forgetMedia($item->id);
        hi()->mediaRepo()->delete($item->id);

        hi()->audit()->record(
            action: 'media.delete',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'media',
            subjectId: $item->id,
            summary: 'Kullanılmayan dosya silindi: ' . $item->filename,
            ip: hi()->request()->ip(),
        );

        admin_redirect(
            $this->url(['sekme' => 'denetim']),
            'success',
            Str::format('%s silindi (%d kopya dosyasıyla).', $item->filename, $copies)
        );
    }

    private function sweepOrphans(): void
    {
        $orphans = $this->store->orphanVariants();
        $uploads = hi()->uploader()->uploadsDir();
        $removed = 0;
        $ids     = [];

        foreach ($orphans as $variant) {
            $file  = (string) ($variant['file'] ?? '');
            $ids[] = (int) $variant['id'];

            if ($file !== '' && is_file($uploads . '/' . $file) && @unlink($uploads . '/' . $file)) {
                $removed++;
            }
        }

        $this->store->deleteVariants($ids);

        admin_redirect(
            $this->url(['sekme' => 'denetim']),
            'success',
            Str::format('%d artık kayıt temizlendi, %d dosya silindi.', count($ids), $removed)
        );
    }

    /* ---------------------------------------------------------------------
     * Genel bakış
     * ------------------------------------------------------------------ */

    private function overview(): void
    {
        $images  = $this->store->imageCount();
        $covered = $this->store->coveredMediaCount();
        $totals  = $this->store->variantTotals();
        $missing = $this->store->withoutAlt(1, 1)['total'];

        $copies = 0;
        $bytes  = 0;

        foreach ($totals as $total) {
            $copies += $total['count'];
            $bytes  += $total['bytes'];
        }

        $this->head('Türev üretimi, denetim ve gruplama tek yerde.');

        echo ui_metrics([
            ['label' => 'Ölçeklenebilir görsel', 'value' => Str::number($images)],
            ['label' => 'Kopyası olan', 'value' => Str::number($covered),
             'note' => $images > 0 ? Str::format('%d%%', (int) round($covered / $images * 100)) : ''],
            ['label' => 'Üretilen kopya', 'value' => Str::number($copies),
             'note' => $bytes > 0 ? Str::bytes($bytes) : ''],
            ['label' => 'Sırada bekleyen', 'value' => Str::number($this->store->pendingCount()),
             'href' => Plugin::SELF_URL . '&sekme=toplu'],
            ['label' => 'Alt metni eksik', 'value' => Str::number($missing),
             'href' => Plugin::SELF_URL . '&sekme=denetim'],
        ]);

        echo '<div class="grid grid-2 mt-3">';

        /* Sunucu yetenekleri */
        echo ui_panel_open('Sunucu yetenekleri', '', 'Eksik olan yetenek sessizce atlanır');
        echo '<div class="panel-body"><ul class="kv">';

        foreach (Processor::capabilities() as $label => $available) {
            echo '<li><span class="k">' . esc_html($label) . '</span><span class="v">'
                . ui_status($available ? 'active' : 'inactive') . '</span></li>';
        }

        echo '<li><span class="k">Yükleme klasörü</span><span class="v mono">'
            . esc_html(basename(hi()->uploader()->uploadsDir())) . '</span></li>';
        echo '<li><span class="k">Bellek sınırı</span><span class="v mono">'
            . esc_html((string) ini_get('memory_limit')) . '</span></li>';
        echo '</ul></div>';
        echo ui_panel_close();

        /* Etkin ayarlar */
        $formats = [];

        if ((bool) $this->settings->get('webp', true)) {
            $formats[] = 'WebP';
        }

        if ((bool) $this->settings->get('avif', false)) {
            $formats[] = 'AVIF';
        }

        echo ui_panel_open('Etkin ayarlar', '<a class="btn btn-sm" href="' . esc_url(Plugin::SELF_URL . '&sekme=ayarlar')
            . '">Ayarları düzenle</a>');
        echo '<div class="panel-body"><ul class="kv">';
        echo '<li><span class="k">Yükleme anında üretim</span><span class="v">'
            . ui_status($this->pipeline->isAutomatic() ? 'active' : 'inactive') . '</span></li>';
        echo '<li><span class="k">Ek formatlar</span><span class="v">'
            . esc_html($formats === [] ? 'yok — kaynağın formatı' : implode(' + ', $formats)) . '</span></li>';
        echo '<li><span class="k">Genişlikler</span><span class="v mono">'
            . esc_html(implode(' · ', $this->pipeline->widths())) . '</span></li>';
        echo '<li><span class="k">Kalite (JPEG/WebP · AVIF)</span><span class="v mono">'
            . (int) $this->settings->get('quality', 82) . ' · '
            . (int) $this->settings->get('avif_quality', 52) . '</span></li>';
        echo '<li><span class="k">AVIF için &lt;picture&gt;</span><span class="v">'
            . ui_status(((bool) $this->settings->get('picture', true)) ? 'active' : 'inactive') . '</span></li>';
        echo '</ul></div>';
        echo ui_panel_close(
            '<a class="btn btn-primary btn-sm" href="' . esc_url(Plugin::SELF_URL . '&sekme=toplu') . '">'
            . 'Toplu üretime git</a>'
        );

        echo '</div>';

        if ($totals !== []) {
            echo ui_panel_open('Formata göre kopyalar');
            echo '<div class="table-wrap"><table class="data"><thead><tr>'
                . '<th>Format</th><th class="num">Dosya</th><th class="num">Toplam boyut</th>'
                . '<th class="num">Ortalama</th></tr></thead><tbody>';

            foreach ($totals as $format => $total) {
                echo '<tr><td class="edge"><span class="pill is-info no-dot">' . esc_html($format) . '</span></td>'
                    . '<td class="num">' . esc_html(Str::number($total['count'])) . '</td>'
                    . '<td class="num">' . esc_html(Str::bytes($total['bytes'])) . '</td>'
                    . '<td class="num">' . esc_html(Str::bytes(
                        $total['count'] > 0 ? (int) round($total['bytes'] / $total['count']) : 0
                    )) . '</td></tr>';
            }

            echo '</tbody></table></div>';
            echo ui_panel_close();
        }
    }

    /* ---------------------------------------------------------------------
     * Kitaplık — klasör ve etiket
     * ------------------------------------------------------------------ */

    private function library(): void
    {
        $openId = (int) ($_GET['dosya'] ?? 0);

        if ($openId > 0) {
            $item = hi()->mediaRepo()->find($openId);

            if ($item !== null) {
                $this->libraryEdit($item);

                return;
            }
        }

        $folder = (string) ($_GET['klasor'] ?? '');
        $tag    = (string) ($_GET['etiket'] ?? '');
        $search = trim((string) ($_GET['ara'] ?? ''));
        $page   = max(1, (int) ($_GET['sayfa'] ?? 1));

        $result  = $this->store->browse([
            'folder'  => $folder,
            'tag'     => $tag,
            'search'  => $search,
            'page'    => $page,
            'perPage' => self::PER_PAGE,
        ]);

        $folders = $this->store->folders();
        $meta    = $this->store->metaMany(array_map(static fn(MediaItem $i): int => $i->id, $result['items']));
        $names   = [];

        foreach ($folders as $row) {
            $names[$row['id']] = $row['name'];
        }

        $this->head('Klasör ve etiketle grupla; alt metnini ve kopyaları buradan yönet.', $result['total']);

        if (!$this->store->ready()) {
            echo ui_notice('error', 'Eklenti tabloları kurulamamış. Eklentiyi kapatıp yeniden açın.');

            return;
        }

        echo ui_panel_open(
            'Dosyalar',
            '',
            'Görsel, belge, arşiv — hepsi klasörlenir; süzgeç klasör, etiket ve ada göre daralır'
        );

        /* Süzgeç şeridi */
        echo '<div class="filters">';

        $folderTabs = [
            ['label' => 'Tümü', 'url' => $this->url(['klasor' => null, 'etiket' => $tag, 'ara' => $search]),
             'active' => $folder === ''],
            ['label' => 'Klasörsüz', 'url' => $this->url(['klasor' => 'yok', 'etiket' => $tag, 'ara' => $search]),
             'active' => $folder === 'yok'],
        ];

        foreach ($folders as $row) {
            $folderTabs[] = [
                'label'  => $row['name'],
                'url'    => $this->url(['klasor' => $row['id'], 'etiket' => $tag, 'ara' => $search]),
                'active' => $folder === (string) $row['id'],
                'count'  => $row['count'],
            ];
        }

        echo ui_tabs($folderTabs);
        echo '<span class="bar-gap"></span>';

        echo '<form class="row" method="get" action="plugin.php">'
            . '<input type="hidden" name="eklenti" value="hi-media">'
            . '<input type="hidden" name="sekme" value="kitaplik">'
            . '<input type="hidden" name="klasor" value="' . esc_attr($folder) . '">'
            . '<input type="hidden" name="etiket" value="' . esc_attr($tag) . '">'
            . '<label class="sr-only" for="hm-ara">Dosya ara</label>'
            . ui_input('ara', $search, ['id' => 'hm-ara', 'type' => 'search', 'style' => 'width:180px',
                'placeholder' => 'Dosya adı ya da alt metin'])
            . '<button class="btn btn-sm" type="submit">' . admin_icon('search', 14) . '</button>'
            . '</form>';

        echo '</div>';

        /* Etiket şeridi */
        $tags = $this->store->tagCounts();

        if ($tags !== []) {
            echo '<div class="filters">';

            if ($tag !== '') {
                echo '<a class="btn btn-sm" href="' . esc_url($this->url(['etiket' => null, 'klasor' => $folder]))
                    . '">' . admin_icon('x', 13) . 'Etiket süzgecini kaldır</a>';
            }

            foreach ($tags as $name => $count) {
                echo '<a class="chip" href="' . esc_url($this->url([
                    'etiket' => $name, 'klasor' => $folder, 'sayfa' => null,
                ])) . '">' . esc_html($name) . ' <strong>' . (int) $count . '</strong></a>';
            }

            echo '</div>';
        }

        if ($result['items'] === []) {
            echo '<div class="panel-body">'
                . ui_empty('image', 'Eşleşen dosya yok', 'Süzgeci temizleyin ya da medya kitaplığına dosya yükleyin.',
                    '<a class="btn btn-sm" href="media.php">Medyaya git</a>')
                . '</div>';
            echo ui_panel_close();
            $this->folderPanel($folders);

            return;
        }

        echo '<div class="table-wrap"><table class="data"><thead><tr>'
            . '<th>Dosya</th><th>Klasör</th><th>Etiket</th><th class="num">Kopya</th>'
            . '<th class="num">Boyut</th><th class="fit"></th></tr></thead><tbody>';

        foreach ($result['items'] as $item) {
            $itemMeta = $meta[$item->id] ?? ['folder_id' => 0, 'tags' => []];
            $variants = count($this->store->variantsFor($item->id));
            $editUrl  = $this->url(['dosya' => $item->id]);

            /*
             * Satır kenarı: yeşil = kopyası var, turuncu = kopya bekliyor,
             * gri = ölçeklenemez (belge ya da SVG). Renk listeyi taramanın en
             * hızlı yolu, o yüzden üç durumu ayırmak gerekiyor.
             */
            $status = match (true) {
                $variants > 0                  => 'published',
                Processor::processable($item)  => 'pending',
                default                        => 'draft',
            };

            echo '<tr data-status="' . $status . '">';
            echo '<td class="edge"><a class="cell-title" href="' . esc_url($editUrl) . '">'
                . esc_html($item->title !== '' ? $item->title : $item->filename) . '</a>'
                . '<span class="cell-sub">' . ($item->width > 0
                    ? (int) $item->width . '×' . (int) $item->height
                    : esc_html($item->extension()))
                . ($item->isImage() && trim($item->alt) === '' ? ' · alt yok' : '') . '</span></td>';
            echo '<td>' . ($itemMeta['folder_id'] > 0
                ? '<span class="pill is-mute no-dot">'
                    . esc_html($names[$itemMeta['folder_id']] ?? '—') . '</span>'
                : '<span class="muted">—</span>') . '</td>';
            echo '<td>' . ($itemMeta['tags'] === []
                ? '<span class="muted">—</span>'
                : esc_html(implode(', ', $itemMeta['tags']))) . '</td>';
            echo '<td class="num">' . (int) $variants . '</td>';
            echo '<td class="num">' . esc_html(Str::bytes($item->size)) . '</td>';
            echo '<td class="fit"><div class="row-acts"><a class="icon-btn" href="' . esc_url($editUrl)
                . '" aria-label="Düzenle">' . admin_icon('edit', 14) . '</a></div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo ui_pagination(
            $result['page'],
            $result['pages'],
            fn(int $n): string => $this->url([
                'klasor' => $folder, 'etiket' => $tag, 'ara' => $search, 'sayfa' => $n,
            ]),
            $result['total']
        );

        echo ui_panel_close();

        $this->folderPanel($folders);
    }

    /**
     * @param list<array{id: int, name: string, slug: string, count: int}> $folders
     */
    private function folderPanel(array $folders): void
    {
        echo ui_panel_open('Klasörler', '', 'Klasör silindiğinde dosyalar silinmez, klasörsüz kalır');
        echo '<div class="panel-body">';

        echo '<form class="row" method="post" action="' . esc_url($this->url(['sekme' => 'kitaplik'])) . '">'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="klasor-ekle">'
            . '<label class="sr-only" for="hm-klasor">Klasör adı</label>'
            . ui_input('ad', '', ['id' => 'hm-klasor', 'placeholder' => 'Yeni klasör adı',
                'style' => 'width:220px', 'maxlength' => '120'])
            . '<button class="btn btn-sm btn-primary" type="submit">' . admin_icon('plus', 14) . 'Ekle</button>'
            . '</form>';

        if ($folders === []) {
            echo '<p class="muted small mt-2">Henüz klasör yok.</p></div>';
            echo ui_panel_close();

            return;
        }

        echo '<div class="row row-wrap mt-3">';

        foreach ($folders as $row) {
            echo '<form method="post" action="' . esc_url($this->url(['sekme' => 'kitaplik'])) . '">'
                . hi_csrf_field()
                . '<input type="hidden" name="islem" value="klasor-sil">'
                . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                . '<span class="chip">' . esc_html($row['name']) . ' <strong>' . (int) $row['count'] . '</strong>'
                . '<button type="submit" aria-label="Klasörü sil"'
                . ui_confirm(Str::format('"%s" klasörü silinsin mi? Dosyalar silinmez.', $row['name']))
                . '>' . admin_icon('x', 12) . '</button></span>'
                . '</form>';
        }

        echo '</div></div>';
        echo ui_panel_close();
    }

    private function libraryEdit(MediaItem $item): void
    {
        $meta     = $this->store->metaFor($item->id);
        $folders  = ['0' => '— Klasörsüz —'];
        $variants = $this->store->variantsFor($item->id);

        foreach ($this->store->folders() as $row) {
            $folders[(string) $row['id']] = $row['name'];
        }

        $this->head('Dosya bilgileri, gruplama ve kopyalar.', null, true);

        echo ui_panel_open(
            $item->title !== '' ? $item->title : $item->filename,
            '<a class="btn btn-sm" href="' . esc_url($this->url(['dosya' => null])) . '">Listeye dön</a>',
            $item->mime . ' · ' . Str::bytes($item->size)
                . ($item->width > 0 ? Str::format(' · %d×%d', $item->width, $item->height) : '')
        );

        echo '<div class="panel-body">';

        if ($item->isImage()) {
            echo '<img src="' . esc_url(hi()->urls()->uploads($item->path)) . '" alt="' . esc_attr($item->alt)
                . '" style="max-width:100%;max-height:220px;border:1px solid var(--line);'
                . 'border-radius:var(--r-sm)">';
        }

        echo '<form class="mt-3" method="post" action="' . esc_url($this->url(['sekme' => 'kitaplik'])) . '">'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="atama">'
            . '<input type="hidden" name="id" value="' . (int) $item->id . '">';

        echo ui_field('Başlık', ui_input('baslik', $item->title, ['id' => 'hm-baslik']), '', 'hm-baslik');

        echo ui_field(
            'Alternatif metin',
            '<textarea class="input" id="hm-alt" name="alt" rows="2" maxlength="250">'
            . esc_html($item->alt) . '</textarea>',
            'Ekran okuyucular ve arama motorları için. Süs amaçlı görsellerde boş bırakın.',
            'hm-alt'
        );

        echo ui_field(
            'Klasör',
            ui_select('klasor', $folders, (string) $meta['folder_id'], ['id' => 'hm-klasor-sec']),
            '',
            'hm-klasor-sec'
        );

        echo ui_field(
            'Etiketler',
            ui_input('etiketler', implode(', ', $meta['tags']), [
                'id' => 'hm-etiket', 'placeholder' => 'ürün, kapak, ekip',
            ]),
            'Virgülle ayırın. Küçük harfe çevrilir; etiket başına 40 karakter.',
            'hm-etiket'
        );

        echo '<div class="row mt-3"><button class="btn btn-primary btn-sm" type="submit">'
            . admin_icon('save', 14) . 'Kaydet</button>'
            . '<a class="btn btn-sm" href="media.php?dosya=' . (int) $item->id . '">Medya kitaplığında aç</a>'
            . '</div></form>';

        echo '</div>';
        echo ui_panel_close();

        /* Kopyalar */
        $actions = Processor::hasGd()
            ? '<form method="post" action="' . esc_url($this->url(['sekme' => 'kitaplik'])) . '">'
                . hi_csrf_field()
                . '<input type="hidden" name="islem" value="uret">'
                . '<input type="hidden" name="id" value="' . (int) $item->id . '">'
                . '<button class="btn btn-sm" type="submit">' . admin_icon('refresh', 14)
                . 'Kopyaları yeniden üret</button></form>'
            : '';

        echo ui_panel_open('Üretilen kopyalar', $actions, Str::format('%d dosya', count($variants)));

        if ($variants === []) {
            echo '<div class="panel-body">' . ui_empty(
                'image',
                'Kopya yok',
                Processor::hasGd()
                    ? 'Bu görsel için henüz kopya üretilmemiş.'
                    : 'GD eklentisi olmadan kopya üretilemez.'
            ) . '</div>';
            echo ui_panel_close();

            return;
        }

        echo '<div class="table-wrap"><table class="data"><thead><tr>'
            . '<th>Dosya</th><th>Format</th><th class="num">Ölçü</th><th class="num">Boyut</th>'
            . '<th class="fit">Durum</th></tr></thead><tbody>';

        foreach ($variants as $variant) {
            $file    = (string) $variant['file'];
            $onDisk  = is_file(hi()->uploader()->uploadsDir() . '/' . $file);
            $inSizes = in_array($file, array_column($item->sizes, 'file'), true);

            echo '<tr data-status="' . ($onDisk ? 'published' : 'trash') . '">';
            echo '<td class="edge"><a class="cell-title" href="' . esc_url(hi()->urls()->uploads($file))
                . '" target="_blank" rel="noopener">' . esc_html(basename($file)) . '</a>'
                . '<span class="cell-sub">' . esc_html((string) $variant['size_key'])
                . ($inSizes ? ' · srcset' : '') . '</span></td>';
            echo '<td><span class="pill is-info no-dot">' . esc_html((string) $variant['format']) . '</span></td>';
            echo '<td class="num">' . (int) $variant['width'] . '×' . (int) $variant['height'] . '</td>';
            echo '<td class="num">' . esc_html(Str::bytes((int) $variant['bytes'])) . '</td>';
            echo '<td class="fit">' . ($onDisk
                ? '<span class="pill is-ok">Diskte</span>'
                : '<span class="pill is-err">Dosya yok</span>') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo ui_panel_close();
    }

    /* ---------------------------------------------------------------------
     * Toplu işlem
     * ------------------------------------------------------------------ */

    private function batch(): void
    {
        $force     = ($_GET['zorla'] ?? '') !== '';
        $cursor    = max(0, (int) ($_GET['imlec'] ?? 0));
        $processed = max(0, (int) ($_GET['islenen'] ?? 0));
        $succeeded = max(0, (int) ($_GET['basari'] ?? 0));

        $images  = $this->store->imageCount();
        $covered = $this->store->coveredMediaCount();
        $pending = $this->store->pendingCount();
        $size    = max(1, min(50, (int) $this->settings->get('batch', 8)));

        // Sıranın devamı var mı? Tek satırlık bir sorgu; JS sürdürme kararını buna bakarak verir.
        $hasMore = $this->store->pending(1, $force, $cursor) !== [];

        $this->head('Var olan görseller için kopya üretimi. Parti parti çalışır, kaldığı yerden sürer.');

        echo ui_metrics([
            ['label' => 'Ölçeklenebilir görsel', 'value' => Str::number($images)],
            ['label' => 'Kopyası olan', 'value' => Str::number($covered)],
            ['label' => 'Sırada bekleyen', 'value' => Str::number($pending)],
            ['label' => 'Parti boyutu', 'value' => Str::number($size), 'note' => 'ayarlardan'],
        ]);

        if (!Processor::hasGd()) {
            echo ui_notice('error', 'GD eklentisi yok; bu ekrandan üretim yapılamaz. '
                . 'Sunucuda php-gd kurulduktan sonra buraya dönün.');

            return;
        }

        if ($processed > 0) {
            echo ui_notice(
                $succeeded === $processed ? 'success' : 'warning',
                Str::format('Son parti: %d dosya denendi, %d üretildi.', $processed, $succeeded)
            );
        }

        $done = max(0, $images - $pending);

        echo ui_panel_open(
            'Üretim sırası',
            '',
            $force ? 'Zorla mod: kopyası olanlar da yeniden üretilir' : 'Yalnızca kopyası olmayanlar işlenir'
        );

        echo '<div class="panel-body">';

        echo '<p class="row"><progress value="' . (int) $done . '" max="' . (int) max(1, $images) . '"></progress>'
            . '<span class="mono small">' . esc_html(Str::format('%d / %d', $done, $images)) . '</span></p>';

        /*
         * Veri `hi_admin_data()` ile JSON olarak basılır (0.3.0 sözleşmesi) ve
         * AYRICA formun data-* özniteliklerine yazılır. İkisi birden gerekiyor:
         * JSON düğümü admin.footer kancasında, yani <main> DIŞINDA basılıyor;
         * nav.js yalnızca <main> bölgesini değiştirdiği için anında geçişten
         * sonra düğüm eskimiş kalıyor. data-* öznitelikleri bölgeyle birlikte
         * geldiği için her koşulda tazedir.
         */
        hi_admin_data('hi-media', [
            'kalan'   => $pending,
            'islenen' => $processed,
            'devam'   => $hasMore,
            'imlec'   => $cursor,
        ]);

        hi_admin_script($this->scriptUrl);

        echo '<form method="post" action="' . esc_url($this->url(['sekme' => 'toplu'])) . '"'
            . ' data-himedia-batch data-devam="' . ($hasMore ? '1' : '0') . '"'
            . ' data-islenen="' . (int) $processed . '" data-kalan="' . (int) $pending . '"'
            . ' data-imlec="' . (int) $cursor . '">'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="toplu">'
            . '<input type="hidden" name="imlec" value="' . (int) $cursor . '">';

        echo ui_switch('zorla', $force, 'Kopyası olanları da yeniden üret',
            'Genişlik ya da kalite ayarını değiştirdiyseniz açın. Eski dosyalar silinip yenileri yazılır.');

        echo '<div class="row mt-3">'
            . '<button class="btn btn-primary btn-sm" type="submit" data-himedia-run>'
            . admin_icon('play', 14) . ($cursor > 0 ? 'Sonraki partiyi üret' : 'Üretimi başlat') . '</button>';

        if ($cursor > 0) {
            echo '<a class="btn btn-sm" href="' . esc_url(Plugin::SELF_URL . '&sekme=toplu') . '">Baştan başla</a>';
        }

        echo '<span class="muted small" data-himedia-status>'
            . esc_html($hasMore
                ? Str::format('Sırada iş var; her partide en çok %d dosya işlenir.', $size)
                : 'Sıra boş.')
            . '</span></div></form>';

        echo '<p class="hint mt-3">JavaScript açıkken partiler kendiliğinden birbirini izler; '
            . 'kapalıyken düğmeye her partide bir kez basmanız gerekir. Sayfadan çıkmak işlemi bozmaz, '
            . 'üretim kaldığı yerden sürer.</p>';

        echo '</div>';
        echo ui_panel_close();
    }

    /* ---------------------------------------------------------------------
     * Denetim
     * ------------------------------------------------------------------ */

    private function audit(): void
    {
        $page    = max(1, (int) ($_GET['sayfa'] ?? 1));
        $missing = $this->store->withoutAlt($page, self::PER_PAGE);
        $unused  = $this->usage->unused(50);
        $orphans = $this->store->orphanVariants(200);

        $this->head('Alt metni eksik görseller, kullanılmayan dosyalar ve artık kopyalar.', $missing['total']);

        echo ui_metrics([
            ['label' => 'Alt metni eksik', 'value' => Str::number($missing['total'])],
            ['label' => 'Kullanılmayan dosya', 'value' => Str::number(count($unused)),
             'note' => count($unused) >= 50 ? 'ilk 50' : ''],
            ['label' => 'Artık kopya kaydı', 'value' => Str::number(count($orphans))],
        ]);

        $this->altPanel($missing);
        $this->unusedPanel($unused);
        $this->orphanPanel($orphans);
    }

    /**
     * @param array{items: list<MediaItem>, total: int, page: int, pages: int} $missing
     */
    private function altPanel(array $missing): void
    {
        echo ui_panel_open(
            'Alt metni eksik görseller',
            '',
            'Alanı doldurup tek seferde kaydedin; boş bıraktığınız satırlara dokunulmaz'
        );

        if ($missing['items'] === []) {
            echo '<div class="panel-body">'
                . ui_empty('check', 'Eksik yok', 'Bütün görsellerin alternatif metni var.')
                . '</div>';
            echo ui_panel_close();

            return;
        }

        echo '<form method="post" action="' . esc_url($this->url(['sekme' => 'denetim'])) . '">'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="alt">';

        echo '<div class="table-wrap"><table class="data"><thead><tr>'
            . '<th>Dosya</th><th>Alternatif metin</th><th class="num">Ölçü</th></tr></thead><tbody>';

        foreach ($missing['items'] as $item) {
            echo '<tr data-status="pending">';
            echo '<td class="edge"><a class="cell-title" href="'
                . esc_url($this->url(['sekme' => 'kitaplik', 'dosya' => $item->id])) . '">'
                . esc_html($item->title !== '' ? $item->title : $item->filename) . '</a>'
                . '<span class="cell-sub">' . esc_html($item->extension()) . '</span></td>';
            echo '<td>' . ui_input('alt[' . $item->id . ']', '', [
                'id'          => 'hm-alt-' . $item->id,
                'maxlength'   => '250',
                'placeholder' => 'Görselde ne görünüyor?',
                'aria-label'  => 'Alternatif metin: ' . $item->filename,
            ]) . '</td>';
            echo '<td class="num">' . ($item->width > 0
                ? (int) $item->width . '×' . (int) $item->height : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<footer class="panel-foot"><button class="btn btn-primary btn-sm" type="submit">'
            . admin_icon('save', 14) . 'Alt metinleri kaydet</button>'
            . '<span class="bar-gap"></span>'
            . ui_pagination(
                $missing['page'],
                $missing['pages'],
                fn(int $n): string => $this->url(['sekme' => 'denetim', 'sayfa' => $n]),
                $missing['total']
            )
            . '</footer></form></section>';
    }

    /**
     * @param list<MediaItem> $unused
     */
    private function unusedPanel(array $unused): void
    {
        echo ui_panel_open(
            'Kullanılmayan dosyalar',
            '',
            'İçerik, blok, özel alan, sürüm geçmişi ve site ayarları tarandı'
        );

        if ($unused === []) {
            echo '<div class="panel-body">'
                . ui_empty('check', 'Hepsi kullanımda', 'Her dosya en az bir yerde geçiyor.')
                . '</div>';
            echo ui_panel_close();

            return;
        }

        echo '<div class="table-wrap"><table class="data"><thead><tr>'
            . '<th>Dosya</th><th>Tür</th><th class="num">Boyut</th><th class="when">Yüklenme</th>'
            . '<th class="fit"></th></tr></thead><tbody>';

        foreach ($unused as $item) {
            echo '<tr data-status="draft">';
            echo '<td class="edge"><a class="cell-title" href="' . esc_url(hi()->urls()->uploads($item->path))
                . '" target="_blank" rel="noopener">' . esc_html($item->filename) . '</a>'
                . '<span class="cell-sub">#' . (int) $item->id . '</span></td>';
            echo '<td>' . esc_html($item->mime) . '</td>';
            echo '<td class="num">' . esc_html(Str::bytes($item->size)) . '</td>';
            echo '<td class="when">' . ui_time($item->createdAt) . '</td>';
            echo '<td class="fit"><form method="post" action="'
                . esc_url($this->url(['sekme' => 'denetim'])) . '">'
                . hi_csrf_field()
                . '<input type="hidden" name="islem" value="sil">'
                . '<input type="hidden" name="id" value="' . (int) $item->id . '">'
                . '<button class="btn btn-sm btn-danger" type="submit"'
                . ui_confirm(Str::format('%s ve türev kopyaları diskten silinecek. Devam edilsin mi?', $item->filename))
                . '>' . admin_icon('trash', 13) . 'Sil</button></form></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo ui_panel_close(
            '<span class="muted small">Silme geri alınamaz. Tarama kimlik ve adres başvurularına bakar; '
            . 'dosyayı yalnızca tema kodunda sabit yazdıysanız burada "kullanılmıyor" görünür.</span>'
        );
    }

    /**
     * @param list<array<string, mixed>> $orphans
     */
    private function orphanPanel(array $orphans): void
    {
        if ($orphans === []) {
            return;
        }

        $bytes = 0;

        foreach ($orphans as $orphan) {
            $bytes += (int) ($orphan['bytes'] ?? 0);
        }

        echo ui_panel_open('Artık kopyalar', '', 'Kaydı silinmiş medyaya ait kopya dosyaları');
        echo '<div class="panel-body">';
        echo ui_notice('warning', Str::format(
            '%d kopya dosyası, silinmiş medya kayıtlarına ait (%s). Çekirdekte "medya silindi" '
            . 'olayı olmadığı için bu dosyalar silme anında temizlenemiyor.',
            count($orphans),
            Str::bytes($bytes)
        ));

        echo '<form method="post" action="' . esc_url($this->url(['sekme' => 'denetim'])) . '">'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="artik">'
            . '<button class="btn btn-sm" type="submit">' . admin_icon('trash', 14)
            . 'Artık dosyaları temizle</button></form>';

        echo '</div>';
        echo ui_panel_close();
    }

    /* ---------------------------------------------------------------------
     * Ayarlar
     * ------------------------------------------------------------------ */

    private function settingsScreen(): void
    {
        $canSave = hi()->auth()->can('settings.manage');
        $values  = $this->settings->all();

        $this->head('Üretim, format ve kalite ayarları.', null, true);

        if (!$canSave) {
            echo ui_notice('info', 'Ayarları değiştirmek için "Site ayarlarını değiştir" yetkisi gerekiyor.');
        }

        echo '<form method="post" action="' . esc_url($this->url(['sekme' => 'ayarlar'])) . '" data-guard>'
            . hi_csrf_field()
            . '<input type="hidden" name="islem" value="ayarlar">';

        foreach ($this->settings->sections() as $definition) {
            echo ui_panel_open($definition['label'], '', $definition['description']);
            echo '<div class="panel-body">';

            foreach ($definition['fields'] as $field) {
                echo $this->control($field, $values[$field->key] ?? $field->default);
            }

            echo '</div>';
            echo ui_panel_close();
        }

        if ($canSave) {
            echo '<div class="row mt-3"><button class="btn btn-primary" type="submit">'
                . admin_icon('save', 15) . 'Ayarları kaydet</button>'
                . '<a class="btn" href="' . esc_url(Plugin::SELF_URL . '&sekme=toplu') . '">'
                . 'Kaydettikten sonra toplu üretim</a></div>';
        }

        echo '</form>';
    }

    /**
     * Ayar alanını girdiye çevirir.
     *
     * Yalnızca bu eklentinin kullandığı türler ele alınır; bilinmeyen tür metin
     * girdisine düşer, böylece yeni bir alan eklemek ekranı bozmaz.
     */
    private function control(Field $field, mixed $value): string
    {
        $id = 'hm-' . $field->key;

        if ($field->type === 'switch') {
            return '<div class="field">' . ui_switch($field->key, (bool) $value, $field->label, $field->help) . '</div>';
        }

        $control = match ($field->type) {
            'number' => ui_input($field->key, (string) (is_scalar($value) ? $value : ''), [
                'type' => 'number', 'id' => $id, 'style' => 'max-width:140px',
            ]),
            'lines' => '<textarea class="input mono" id="' . esc_attr($id) . '" name="'
                . esc_attr($field->key) . '" rows="' . (int) $field->rows . '">'
                . esc_html(is_array($value) ? implode("\n", $value) : (string) (is_scalar($value) ? $value : ''))
                . '</textarea>',
            'select' => ui_select($field->key, $field->options, (string) $value, ['id' => $id]),
            default  => ui_input($field->key, (string) (is_scalar($value) ? $value : ''), [
                'id' => $id, 'placeholder' => $field->placeholder,
            ]),
        };

        return ui_field($field->label, $control, esc_html($field->help), $id, $field->required);
    }
}
