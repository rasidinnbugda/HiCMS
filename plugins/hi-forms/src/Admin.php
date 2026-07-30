<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Content\Field;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Panel ekranları.
 *
 * Dört görünüm, hepsi `plugin.php?eklenti=hi-forms` altında:
 *
 *   gonderiler (varsayılan) — liste, sekmeli süzgeç, arama, sayfalama
 *   gonderi                 — tek gönderi
 *   formlar / form          — tanım listesi ve düzenleyici
 *   ayarlar                 — hi_settings tanımından üretilen ayar formu
 *
 * YOĞUNLUK SÖZLEŞMESİ: tablo satırı tek satır (34px), alt bilgi başlığın
 * yanında (.cell-sub), form ekranları `narrow`, liste ekranları `count`.
 */
final class Admin
{
    private const PER_PAGE = 25;

    /** Ayar ekranında basılan bölümler — "formlar" bölümünün kendi ekranı var. */
    private const SETTING_SECTIONS = ['genel', 'koruma'];

    public static function screen(): void
    {
        admin_require('settings.manage');

        Forms::migrateLegacy();

        if (hi()->request()->isPost()) {
            self::handlePost();
        }

        match (self::view()) {
            'gonderi' => self::submissionScreen(),
            'formlar' => self::formListScreen(),
            'form'    => self::formEditScreen(),
            'ayarlar' => self::settingsScreen(),
            default   => self::submissionListScreen(),
        };
    }

    /* ---------------------------------------------------------------------
     * Adresler
     * ------------------------------------------------------------------ */

    private static function view(): string
    {
        return (string) ($_GET['gorunum'] ?? 'gonderiler');
    }

    /**
     * @param array<string, string> $params
     */
    public static function url(array $params = []): string
    {
        $params = array_filter(
            array_merge(['eklenti' => Forms::SLUG], $params),
            static fn(string $value): bool => $value !== ''
        );

        return 'plugin.php?' . http_build_query($params);
    }

    /**
     * Liste süzgeçlerini koruyan adres.
     *
     * @param array<string, string> $params
     */
    private static function listUrl(array $params = []): string
    {
        return self::url(array_merge([
            'gorunum' => 'gonderiler',
            'durum'   => (string) ($_GET['durum'] ?? ''),
            'form'    => (string) ($_GET['form'] ?? ''),
            'ara'     => (string) ($_GET['ara'] ?? ''),
            'sayfa'   => (string) ($_GET['sayfa'] ?? ''),
        ], $params));
    }

    /* ---------------------------------------------------------------------
     * POST işlemleri
     * ------------------------------------------------------------------ */

    private static function handlePost(): void
    {
        admin_verify(self::listUrl());

        $action = (string) ($_POST['islem'] ?? '');
        $ids    = array_map('intval', (array) ($_POST['ids'] ?? []));

        if ((int) ($_POST['id'] ?? 0) > 0) {
            $ids = [(int) $_POST['id']];
        }

        /*
         * `match` kullanılıyor, `switch` DEĞİL: her kol `never` dönen bir çağrı,
         * yani araya düşme (fallthrough) diye bir şey olamıyor. switch ile
         * yazıldığında her kol bir yönlendirmeyle bitiyor ve o yönlendirmelerden
         * biri ileride kaldırılırsa akış sessizce SONRAKİ kolun yıkıcı işlemine
         * giriyordu.
         */
        match ($action) {
            'ayarlar'            => self::saveSettings(),
            'form-kaydet'        => self::saveForm(),
            'form-sil'           => self::deleteForm(),
            'durum-new',
            'durum-read',
            'durum-spam'         => self::changeStatus($ids, substr($action, 6)),
            'hepsini-okundu'     => self::markAllRead(),
            'sil'                => self::deleteSubmissions($ids),
            'istenmeyeni-bosalt' => self::emptySpam(),
            'csv'                => self::exportCsv((string) ($_POST['form'] ?? '')),
            default              => admin_redirect(self::listUrl(), 'warning', 'Tanınmayan işlem.'),
        };
    }

    private static function saveSettings(): never
    {
        /*
         * Bölüm bölüm kaydedilir. Bölümsüz çağrı ("formlar" dâhil tüm alanlar)
         * form tanımlarını POST'ta bulunmadıkları için boşaltırdı.
         */
        foreach (self::SETTING_SECTIONS as $section) {
            Forms::store()->save($_POST, $section);
        }

        admin_redirect(self::url(['gorunum' => 'ayarlar']), 'success', 'Ayarlar kaydedildi.');
    }

    private static function deleteForm(): never
    {
        $slug = Str::slug((string) ($_POST['kisa_ad'] ?? ''));

        Forms::forget($slug);

        // Gönderiler VARSAYILAN OLARAK korunur: tanım silmek veri silmek değil.
        if (!empty($_POST['gonderileri_sil'])) {
            $removed = Submissions::deleteByForm($slug);

            admin_redirect(
                self::url(['gorunum' => 'formlar']),
                'success',
                Str::format('Form ve %d gönderisi silindi.', $removed)
            );
        }

        admin_redirect(
            self::url(['gorunum' => 'formlar']),
            'success',
            'Form tanımı silindi. Gönderileri korundu.'
        );
    }

    /**
     * @param list<int> $ids
     */
    private static function changeStatus(array $ids, string $status): never
    {
        $changed = Submissions::setStatus($ids, $status);

        if ($changed === 0) {
            admin_redirect(self::listUrl(), 'warning', 'Hiç gönderi seçilmedi.');
        }

        admin_redirect(
            self::listUrl(),
            'success',
            Str::format('%d gönderi "%s" olarak işaretlendi.', $changed, Submissions::STATUSES[$status] ?? $status)
        );
    }

    private static function markAllRead(): never
    {
        $changed = Submissions::ready()
            ? hi()->db()->builder(Submissions::TABLE)
                ->where('status', 'new')
                ->update(['status' => 'read', 'is_read' => 1])
            : 0;

        admin_redirect(self::listUrl(), 'success', Str::format('%d gönderi okundu işaretlendi.', $changed));
    }

    /**
     * @param list<int> $ids
     */
    private static function deleteSubmissions(array $ids): never
    {
        $removed = Submissions::delete($ids);

        if ($removed === 0) {
            admin_redirect(self::listUrl(), 'warning', 'Hiç gönderi seçilmedi.');
        }

        admin_redirect(self::listUrl(), 'success', Str::format('%d gönderi silindi.', $removed));
    }

    private static function emptySpam(): never
    {
        $removed = Submissions::emptySpam();

        admin_redirect(
            self::listUrl(['durum' => 'spam']),
            'success',
            Str::format('%d istenmeyen gönderi silindi.', $removed)
        );
    }

    private static function saveForm(): never
    {
        $slug = Str::slug((string) ($_POST['kisa_ad'] ?? ''));
        $name = trim((string) ($_POST['ad'] ?? ''));

        if ($slug === '') {
            $slug = Str::slug($name);
        }

        if ($slug === '') {
            admin_redirect(self::url(['gorunum' => 'form']), 'error',
                'Form adı ya da kısa adı verilmelidir.');
        }

        $fields = [];

        foreach ((array) ($_POST['alan'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (Str::slug((string) ($row['key'] ?? ''), '_') === '') {
                continue;
            }

            /*
             * Seçenekler tek satırda virgülle yazılıyor; depoda liste olarak
             * durur. Dönüşüm BURADA yapılır, `Field` tarafında değil: "lines"
             * türü satır sonuna göre böler, panel formu ise virgül kullanıyor.
             */
            $row['choices'] = array_values(array_filter(
                array_map('trim', preg_split('/[,\r\n]+/', (string) ($row['choices'] ?? '')) ?: []),
                static fn(string $choice): bool => $choice !== ''
            ));

            // Sıra alanı: sürükleyerek taşımada betik bunu güncelliyor.
            $row['__sira'] = (int) ($row['sira'] ?? 0);
            $fields[]      = $row;
        }

        usort($fields, static fn(array $a, array $b): int => $a['__sira'] <=> $b['__sira']);

        $form = [
            'slug'              => $slug,
            'name'              => $name,
            'success'           => trim((string) ($_POST['basari'] ?? '')),
            'redirect'          => trim((string) ($_POST['yonlendir'] ?? '')),
            'submit_label'      => trim((string) ($_POST['dugme'] ?? '')),
            'notify_to'         => preg_split('/[\r\n,;]+/', (string) ($_POST['bildirim'] ?? '')) ?: [],
            'reply_field'       => (string) ($_POST['yanit_alani'] ?? ''),
            'consent'           => !empty($_POST['onay']),
            'autoreply'         => !empty($_POST['oto_yanit']),
            'autoreply_subject' => trim((string) ($_POST['oto_konu'] ?? '')),
            'autoreply_body'    => trim((string) ($_POST['oto_metin'] ?? '')),
            'fields'            => $fields,
        ];

        Forms::put($form);

        $saved = Forms::find($slug);

        admin_redirect(
            self::url(['gorunum' => 'form', 'form' => $slug]),
            'success',
            Str::format('"%s" kaydedildi: %d alan. İçeriğe "Form" bloğuyla yerleştirin.',
                (string) ($saved['name'] ?? $slug), count((array) ($saved['fields'] ?? [])))
        );
    }

    /**
     * Gönderileri CSV olarak indirir.
     *
     * Sütunlar form tanımından gelir; tanımda olmayan eski anahtarlar sona
     * eklenir, yoksa dışa aktarım sessizce veri kaybeder.
     */
    private static function exportCsv(string $form): never
    {
        $rows    = Submissions::allOf($form);
        $columns = [];

        foreach ($rows as $row) {
            foreach (Submissions::labelled($row) as $line) {
                $columns[$line['key']] = $line['label'];
            }
        }

        $name = 'hi-forms-' . ($form !== '' ? $form . '-' : '') . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            exit;
        }

        // Excel UTF-8'i BOM olmadan tanımıyor.
        fwrite($out, "\xEF\xBB\xBF");

        /*
         * `$escape` AÇIKÇA veriliyor: PHP 8.4 varsayılanını kullanmayı
         * kullanımdan kaldırdı ve uyarı doğrudan çıktıya basılıp CSV'yi
         * bozuyor. Boş dize kaçış düzeneğini kapatır — RFC 4180'in istediği de
         * bu, alan içindeki çift tırnak ikilenerek yazılır.
         */
        fputcsv($out, array_merge(['Kimlik', 'Form', 'Tarih', 'Durum'], array_values($columns)), ';', '"', '');

        foreach ($rows as $row) {
            $payload = Submissions::payload($row);
            $line    = [
                (string) $row['id'],
                (string) $row['form'],
                (string) $row['created_at'],
                Submissions::STATUSES[(string) ($row['status'] ?? 'new')] ?? '',
            ];

            foreach (array_keys($columns) as $key) {
                $line[] = $payload[$key] ?? '';
            }

            fputcsv($out, $line, ';', '"', '');
        }

        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Gönderi listesi
     * ------------------------------------------------------------------ */

    private static function submissionListScreen(): void
    {
        $status = (string) ($_GET['durum'] ?? '');
        $form   = (string) ($_GET['form'] ?? '');
        $search = trim((string) ($_GET['ara'] ?? ''));
        $page   = max(1, (int) ($_GET['sayfa'] ?? 1));

        $counts = Submissions::counts();
        $result = Submissions::paginate($status, $form, $search, $page, self::PER_PAGE);
        $forms  = Forms::all();

        admin_head([
            'title'       => 'Formlar',
            'slug'        => 'plugin:' . Forms::SLUG,
            'description' => 'Gelen form gönderileri.',
            'count'       => $result['total'],
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiForms']],
            'actions'     => self::listActions($counts, $form),
        ]);

        echo self::navTabs('gonderiler');

        if (!Submissions::ready()) {
            echo ui_notice('error', 'Gönderi tablosu bulunamadı. Eklentiyi devre dışı bırakıp '
                . 'yeniden etkinleştirin ya da Sistem → Güncellemeler bölümünden bekleyen '
                . 'veritabanı işlemlerini tamamlayın.');
        }
        ?>

        <section class="panel">
            <div class="filters">
                <?= ui_tabs([
                    ['label' => 'Tümü', 'url' => self::listUrl(['durum' => '', 'sayfa' => '']),
                     'active' => !isset(Submissions::STATUSES[$status]), 'count' => $counts['all']],
                    ['label' => 'Yeni', 'url' => self::listUrl(['durum' => 'new', 'sayfa' => '']),
                     'active' => $status === 'new', 'count' => $counts['new']],
                    ['label' => 'Okundu', 'url' => self::listUrl(['durum' => 'read', 'sayfa' => '']),
                     'active' => $status === 'read', 'count' => $counts['read']],
                    ['label' => 'İstenmeyen', 'url' => self::listUrl(['durum' => 'spam', 'sayfa' => '']),
                     'active' => $status === 'spam', 'count' => $counts['spam']],
                ]) ?>

                <span class="spacer"></span>

                <form class="row" method="get" action="plugin.php">
                    <input type="hidden" name="eklenti" value="<?= esc_attr(Forms::SLUG) ?>">
                    <input type="hidden" name="durum" value="<?= esc_attr($status) ?>">
                    <label class="sr-only" for="hf-form-suzgec">Form</label>
                    <?php
                    $filterOptions = ['' => 'Tüm formlar'];

                    foreach ($forms as $filterSlug => $filterForm) {
                        $filterOptions[(string) $filterSlug] = (string) $filterForm['name'];
                    }
                    ?>
                    <?= ui_select('form', $filterOptions, $form,
                        ['id' => 'hf-form-suzgec', 'class' => 'input select', 'style' => 'width:170px']) ?>
                    <label class="sr-only" for="hf-ara">Ara</label>
                    <input class="input" type="search" id="hf-ara" name="ara" style="width:190px"
                           value="<?= esc_attr($search) ?>" placeholder="Gönderi içinde ara">
                    <button class="btn" type="submit"><?= admin_icon('search', 15) ?></button>
                </form>
            </div>

            <?php if ($result['items'] !== []) : ?>
                <form method="post" action="<?= esc_url(self::listUrl()) ?>">
                    <?= hi_csrf_field() ?>

                    <div class="bulk" data-bulk="hf-table">
                        <span><strong data-bulk-count>0</strong> seçildi</span>
                        <button class="btn btn-sm is-off" type="submit" name="islem" value="durum-read"
                                data-bulk-action>Okundu</button>
                        <button class="btn btn-sm is-off" type="submit" name="islem" value="durum-new"
                                data-bulk-action>Yeni</button>
                        <button class="btn btn-sm is-off" type="submit" name="islem" value="durum-spam"
                                data-bulk-action>İstenmeyen</button>
                        <button class="btn btn-sm btn-danger is-off" type="submit" name="islem" value="sil"
                                data-bulk-action <?= ui_confirm('Seçili gönderiler kalıcı olarak silinecek.') ?>>
                            Sil
                        </button>
                    </div>

                    <div class="table-wrap">
                        <table class="data" id="hf-table">
                            <thead>
                                <tr>
                                    <th class="pick">
                                        <label class="check">
                                            <input type="checkbox" data-check-all="hf-table">
                                            <span class="sr-only">Tümünü seç</span>
                                        </label>
                                    </th>
                                    <th>Gönderi</th>
                                    <th>Form</th>
                                    <th>Bildirim</th>
                                    <th>Tarih</th>
                                    <th class="fit"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($result['items'] as $row) : ?>
                                    <?php
                                    $rowStatus = (string) ($row['status'] ?? 'new');
                                    $detail    = self::url([
                                        'gorunum' => 'gonderi',
                                        'id'      => (string) $row['id'],
                                    ]);
                                    ?>
                                    <tr data-status="<?= esc_attr(self::rowStatus($rowStatus)) ?>">
                                        <td class="pick">
                                            <label class="check">
                                                <input type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>">
                                                <span class="sr-only">Seç</span>
                                            </label>
                                        </td>
                                        <td class="edge">
                                            <a class="cell-title" href="<?= esc_url($detail) ?>">
                                                <?= esc_html(Str::limit((string) ($row['subject'] ?? ''), 70)) ?>
                                            </a>
                                            <span class="cell-sub">#<?= (int) $row['id'] ?></span>
                                        </td>
                                        <td class="small">
                                            <?= esc_html((string) ($forms[(string) $row['form']]['name'] ?? $row['form'])) ?>
                                        </td>
                                        <td class="small muted">
                                            <?= (int) ($row['notified'] ?? 0) === 1 ? 'gitti' : '—' ?>
                                        </td>
                                        <td class="when"><?= ui_time((string) $row['created_at']) ?></td>
                                        <td class="fit">
                                            <div class="row-acts">
                                                <a class="icon-btn" href="<?= esc_url($detail) ?>"
                                                   title="Görüntüle" aria-label="Görüntüle">
                                                    <?= admin_icon('eye', 15) ?>
                                                </a>
                                                <button class="icon-btn" type="submit" name="islem" value="sil"
                                                        formaction="<?= esc_url(self::listUrl(['id' => (string) $row['id']])) ?>"
                                                        title="Sil" aria-label="Sil"
                                                        <?= ui_confirm('Bu gönderi kalıcı olarak silinecek.') ?>>
                                                    <?= admin_icon('trash', 15) ?>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?= ui_pagination($result['page'], $result['pages'],
                    static fn(int $n): string => self::listUrl(['sayfa' => (string) $n]), $result['total']) ?>
            <?php else : ?>
                <?= ui_empty('inbox', 'Gönderi yok',
                    $search !== '' || $form !== ''
                        ? 'Bu süzgeçle eşleşen gönderi bulunamadı.'
                        : 'Formu bir içeriğe "Form" bloğuyla yerleştirin; gelen gönderiler burada birikir.',
                    '<a class="btn btn-sm" href="' . esc_url(self::url(['gorunum' => 'formlar'])) . '">Formlara git</a>') ?>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();
    }

    /**
     * @param array<string, int> $counts
     */
    private static function listActions(array $counts, string $form): string
    {
        $html = '';

        if ($counts['new'] > 0) {
            $html .= '<form method="post" action="' . esc_url(self::listUrl()) . '" style="display:inline">'
                . hi_csrf_field() . '<input type="hidden" name="islem" value="hepsini-okundu">'
                . '<button class="btn btn-sm" type="submit">Tümünü okundu işaretle</button></form>';
        }

        if ($counts['all'] > 0) {
            $html .= ' <form method="post" action="' . esc_url(self::listUrl()) . '" style="display:inline">'
                . hi_csrf_field() . '<input type="hidden" name="islem" value="csv">'
                . '<input type="hidden" name="form" value="' . esc_attr($form) . '">'
                . '<button class="btn btn-sm" type="submit">' . admin_icon('download', 14) . 'CSV</button></form>';
        }

        if ($counts['spam'] > 0) {
            $html .= ' <form method="post" action="' . esc_url(self::listUrl()) . '" style="display:inline">'
                . hi_csrf_field() . '<input type="hidden" name="islem" value="istenmeyeni-bosalt">'
                . '<button class="btn btn-sm btn-danger" type="submit"'
                . ui_confirm('Karantinadaki tüm gönderiler kalıcı olarak silinecek.')
                . '>İstenmeyeni boşalt</button></form>';
        }

        return $html;
    }

    /* ---------------------------------------------------------------------
     * Tek gönderi
     * ------------------------------------------------------------------ */

    private static function submissionScreen(): void
    {
        $row = Submissions::find((int) ($_GET['id'] ?? 0));

        if ($row === null) {
            admin_redirect(self::listUrl(), 'error', 'Gönderi bulunamadı.');
        }

        // Açmak okumaktır: liste nişanı gerçeği göstersin.
        if ((string) ($row['status'] ?? '') === 'new') {
            Submissions::setStatus([(int) $row['id']], 'read');

            $row['status'] = 'read';
        }

        $lines  = Submissions::labelled($row);
        $form   = Forms::find((string) $row['form']);
        $status = (string) ($row['status'] ?? 'new');
        $rowUrl = self::listUrl(['id' => (string) $row['id']]);

        admin_head([
            'title'       => Str::limit((string) ($row['subject'] ?? 'Gönderi'), 60),
            'slug'        => 'plugin:' . Forms::SLUG,
            'narrow'      => true,
            'description' => Str::format('%s · %s', (string) ($form['name'] ?? $row['form']),
                Dates::format((string) $row['created_at'], 'j F Y, H:i')),
            'breadcrumb'  => [
                ['label' => 'HiForms', 'url' => self::url()],
                ['label' => 'Gönderi #' . (int) $row['id']],
            ],
            'actions'     => '<a class="btn btn-sm" href="' . esc_url(self::listUrl()) . '">Listeye dön</a>',
        ]);
        ?>

        <section class="panel">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Gönderi içeriği</h2>
                    <p class="panel-sub"><?= count($lines) ?> alan</p>
                </div>
                <div class="panel-actions"><?= self::statusPill($status) ?></div>
            </header>

            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        <?php foreach ($lines as $line) : ?>
                            <tr>
                                <th style="width:190px"><?= esc_html($line['label']) ?></th>
                                <td style="white-space:normal">
                                    <?php if (filter_var($line['value'], FILTER_VALIDATE_EMAIL) !== false) : ?>
                                        <a href="mailto:<?= esc_attr($line['value']) ?>"><?= esc_html($line['value']) ?></a>
                                    <?php elseif ($line['value'] === '') : ?>
                                        <span class="muted">—</span>
                                    <?php else : ?>
                                        <?= nl2br(esc_html($line['value'])) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($lines === []) : ?>
                            <tr><td class="muted">Bu gönderide alan yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <footer class="panel-foot">
                <form method="post" action="<?= esc_url($rowUrl) ?>" class="row">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

                    <?php if ($status !== 'spam') : ?>
                        <button class="btn btn-sm" type="submit" name="islem" value="durum-spam"
                            <?= ui_confirm('Bu gönderi istenmeyen olarak işaretlenecek.') ?>>
                            <?= admin_icon('alert', 14) ?>İstenmeyen
                        </button>
                    <?php else : ?>
                        <button class="btn btn-sm" type="submit" name="islem" value="durum-read">
                            <?= admin_icon('check', 14) ?>İstenmeyen değil
                        </button>
                    <?php endif; ?>

                    <button class="btn btn-sm" type="submit" name="islem" value="durum-new">Okunmadı say</button>

                    <span class="spacer"></span>

                    <button class="btn btn-sm btn-danger" type="submit" name="islem" value="sil"
                        <?= ui_confirm('Bu gönderi kalıcı olarak silinecek.') ?>>
                        <?= admin_icon('trash', 14) ?>Sil
                    </button>
                </form>
            </footer>
        </section>

        <section class="panel">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Teknik bilgi</h2>
                    <p class="panel-sub">Gönderiyle birlikte kaydedilenler</p>
                </div>
            </header>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                        <tr>
                            <th style="width:190px">Ziyaretçi imzası</th>
                            <td class="mono small">
                                <?php $ip = (string) ($row['ip'] ?? ''); ?>
                                <?= $ip !== '' ? esc_html($ip) : '<span class="muted">saklanmadı</span>' ?>
                                <?php if (str_starts_with($ip, 'h:')) : ?>
                                    <span class="pill is-info no-dot">özet</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Tarayıcı</th>
                            <td class="small muted" style="white-space:normal">
                                <?= esc_html((string) ($row['user_agent'] ?? '')) ?: '—' ?>
                            </td>
                        </tr>
                        <tr>
                            <th>E-posta bildirimi</th>
                            <td class="small">
                                <?= (int) ($row['notified'] ?? 0) === 1
                                    ? 'Gönderildi'
                                    : '<span class="muted">Gönderilmedi</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Alındığı zaman</th>
                            <td class="small"><?= ui_time((string) $row['created_at']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Form listesi
     * ------------------------------------------------------------------ */

    private static function formListScreen(): void
    {
        $forms = Forms::all();

        admin_head([
            'title'       => 'Form tanımları',
            'slug'        => 'plugin:' . Forms::SLUG,
            'description' => 'Her tanım blok editöründe "Form" bloğu olarak seçilebilir.',
            'count'       => count($forms),
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiForms']],
            'actions'     => '<a class="btn btn-sm btn-primary" href="'
                . esc_url(self::url(['gorunum' => 'form'])) . '">'
                . admin_icon('plus', 14) . 'Yeni form</a>',
        ]);

        echo self::navTabs('formlar');
        ?>

        <section class="panel">
            <?php if ($forms !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Form</th>
                                <th>Alan</th>
                                <th>Koşullu</th>
                                <th>Bildirim</th>
                                <th>Gönderi</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $slug => $form) : ?>
                                <?php
                                $editUrl    = self::url(['gorunum' => 'form', 'form' => $slug]);
                                $conditions = 0;

                                foreach ((array) $form['fields'] as $field) {
                                    if ((string) $field['cond_field'] !== '') {
                                        $conditions++;
                                    }
                                }

                                $recipients = $form['notify_to'] !== []
                                    ? $form['notify_to'] : Forms::lines('notify_to');
                                ?>
                                <tr>
                                    <td>
                                        <a class="cell-title" href="<?= esc_url($editUrl) ?>">
                                            <?= esc_html((string) $form['name']) ?>
                                        </a>
                                        <span class="cell-sub"><?= esc_html($slug) ?></span>
                                    </td>
                                    <td class="num"><?= count((array) $form['fields']) ?></td>
                                    <td class="small muted">
                                        <?= $conditions > 0 ? esc_html($conditions . ' alan') : '—' ?>
                                    </td>
                                    <td class="small muted">
                                        <?= $recipients !== [] ? esc_html(Str::limit(implode(', ', $recipients), 34)) : '—' ?>
                                    </td>
                                    <td class="num">
                                        <a href="<?= esc_url(self::url(['gorunum' => 'gonderiler', 'form' => $slug])) ?>">
                                            <?= (int) self::countOf($slug) ?>
                                        </a>
                                    </td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url($editUrl) ?>"
                                               title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?= ui_empty('inbox', 'Form yok', 'İlk formu kurun; ardından içeriğe blok olarak yerleştirin.',
                    '<a class="btn btn-sm btn-primary" href="' . esc_url(self::url(['gorunum' => 'form']))
                    . '">Yeni form</a>') ?>
            <?php endif; ?>
        </section>

        <?php
        admin_foot();
    }

    private static function countOf(string $slug): int
    {
        if (!Submissions::ready()) {
            return 0;
        }

        return hi()->db()->builder(Submissions::TABLE)
            ->where('form', $slug)
            ->where('status', '!=', 'spam')
            ->count();
    }

    /* ---------------------------------------------------------------------
     * Form düzenleyici
     * ------------------------------------------------------------------ */

    private static function formEditScreen(): void
    {
        $slug = Str::slug((string) ($_GET['form'] ?? ''));
        $form = $slug !== '' ? Forms::find($slug) : null;
        $isNew = $form === null;

        if ($isNew) {
            $form = Forms::normalize('', [
                'name'   => '',
                'fields' => [
                    ['key' => 'ad', 'label' => 'Adınız', 'type' => 'text', 'required' => true, 'width' => 'half'],
                    ['key' => 'eposta', 'label' => 'E-posta', 'type' => 'email', 'required' => true, 'width' => 'half'],
                    ['key' => 'mesaj', 'label' => 'Mesajınız', 'type' => 'textarea', 'required' => true],
                ],
            ]);
        }

        /*
         * Betiğe geçen veri: satır şablonu ve hangi türlerin seçenek listesi
         * istediği. Satır içi <script> ile geçirilemez — anında sayfa
         * geçişinde bölge değişimi betik etiketlerini çalıştırmaz.
         */
        hi_admin_data('hi-forms', [
            'choiceTypes' => Forms::CHOICE_TYPES,
            'nextIndex'   => count((array) $form['fields']) + 3,
        ]);

        hi_admin_script(hi()->urls()->plugin(Forms::SLUG, 'assets/admin.js') . '?v=1.1.0');

        admin_head([
            'title'       => $isNew ? 'Yeni form' : (string) $form['name'],
            'slug'        => 'plugin:' . Forms::SLUG,
            'narrow'      => true,
            'description' => $isNew
                ? 'Alanları tanımlayın; kısa ad boş bırakılırsa addan türetilir.'
                : Str::format('Blok editöründe "%s" adıyla görünür.', (string) $form['slug']),
            'breadcrumb'  => [
                ['label' => 'HiForms', 'url' => self::url()],
                ['label' => 'Formlar', 'url' => self::url(['gorunum' => 'formlar'])],
                ['label' => $isNew ? 'Yeni' : (string) $form['slug']],
            ],
        ]);
        ?>

        <form method="post" action="<?= esc_url(self::url(['gorunum' => 'form', 'form' => $slug])) ?>"
              data-hf-editor>
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="form-kaydet">

            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Form</h2>
                        <p class="panel-sub">Ad, kısa ad ve gönderim sonrası davranış</p>
                    </div>
                </header>
                <div class="panel-body">
                    <div class="field-row">
                        <?= ui_field('Form adı', ui_input('ad', (string) $form['name'],
                            ['id' => 'hf-ad', 'required' => true, 'placeholder' => 'İletişim']),
                            '', 'hf-ad', true) ?>

                        <?= ui_field('Kısa ad', ui_input('kisa_ad', (string) $form['slug'],
                            ['id' => 'hf-slug', 'class' => 'input mono', 'placeholder' => 'iletisim']),
                            'Boş bırakılırsa addan üretilir. Var olan bir kısa ad yazmak o formu değiştirir.',
                            'hf-slug') ?>
                    </div>

                    <?= ui_field('Başarı mesajı', ui_input('basari', (string) $form['success'],
                        ['id' => 'hf-basari', 'placeholder' => (string) Forms::setting('success_message', '')]),
                        'Boşsa genel ayarlardaki mesaj kullanılır.', 'hf-basari') ?>

                    <div class="field-row">
                        <?= ui_field('Yönlendirme', ui_input('yonlendir', (string) $form['redirect'],
                            ['id' => 'hf-yonlendir', 'placeholder' => 'tesekkurler']),
                            'Doluysa gönderim sonrası bu adrese gidilir. Site içi yol ya da tam adres.',
                            'hf-yonlendir') ?>

                        <?= ui_field('Düğme yazısı', ui_input('dugme', (string) $form['submit_label'],
                            ['id' => 'hf-dugme', 'placeholder' => 'Gönder']), '', 'hf-dugme') ?>
                    </div>

                    <?= ui_switch('onay', (bool) $form['consent'], 'Onay kutusu göster',
                        'Genel ayarlardaki onay metniyle, zorunlu bir kutu eklenir.') ?>
                </div>
            </section>

            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Alanlar</h2>
                        <p class="panel-sub">Sıra numarası düzeni belirler; satırları sürükleyerek de taşıyabilirsiniz</p>
                    </div>
                    <div class="panel-actions">
                        <button class="btn btn-sm" type="button" data-hf-add>
                            <?= admin_icon('plus', 14) ?>Alan ekle
                        </button>
                    </div>
                </header>
                <div class="panel-body col" data-sortable data-hf-rows>
                    <?php
                    $fields = (array) $form['fields'];
                    $index  = 0;

                    foreach ($fields as $field) {
                        self::fieldRow($index++, $field, $fields);
                    }

                    // Betiksiz de alan eklenebilsin: üç boş satır her zaman durur.
                    for ($spare = 0; $spare < 3; $spare++) {
                        self::fieldRow($index++, null, $fields);
                    }
                    ?>
                </div>
            </section>

            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title">Bildirim</h2>
                        <p class="panel-sub">Gönderi geldiğinde kime, hangi adresle</p>
                    </div>
                </header>
                <div class="panel-body">
                    <?= ui_field('Bildirim adresleri',
                        '<textarea class="input mono" id="hf-bildirim" name="bildirim" rows="2" '
                        . 'placeholder="ornek@site.com">'
                        . esc_html(implode("\n", (array) $form['notify_to'])) . '</textarea>',
                        'Her satıra bir adres. Boşsa genel ayarlardaki adresler kullanılır.', 'hf-bildirim') ?>

                    <?= ui_field('Yanıt adresi alanı',
                        ui_select('yanit_alani', self::fieldChoices($fields, 'İlk e-posta alanı'),
                            (string) $form['reply_field'], ['id' => 'hf-yanit']),
                        'Bildirim iletisinin "Reply-To" başlığı bu alandan doldurulur.', 'hf-yanit') ?>

                    <hr>

                    <?= ui_switch('oto_yanit', (bool) $form['autoreply'], 'Gönderene otomatik yanıt gönder',
                        'Yanıt adresi bulunabiliyorsa gönderilir.') ?>

                    <?= ui_field('Otomatik yanıt konusu', ui_input('oto_konu',
                        (string) $form['autoreply_subject'],
                        ['id' => 'hf-oto-konu', 'placeholder' => 'Mesajınızı aldık']), '', 'hf-oto-konu') ?>

                    <?= ui_field('Otomatik yanıt metni',
                        '<textarea class="input" id="hf-oto-metin" name="oto_metin" rows="5">'
                        . esc_html((string) $form['autoreply_body']) . '</textarea>',
                        'Yer tutucular: <code>{site}</code>, <code>{form}</code> ve alan anahtarları '
                        . '(<code>{ad}</code> gibi).', 'hf-oto-metin') ?>
                </div>
            </section>

            <div class="row">
                <a class="btn btn-sm" href="<?= esc_url(self::url(['gorunum' => 'formlar'])) ?>">Vazgeç</a>
                <span class="spacer"></span>

                <?php if (!$isNew) : ?>
                    <button class="btn btn-sm btn-danger" type="submit" name="islem" value="form-sil"
                            formnovalidate
                        <?= ui_confirm('Form tanımı silinecek. Gelen gönderiler korunur.') ?>>
                        <?= admin_icon('trash', 14) ?>Formu sil
                    </button>
                <?php endif; ?>

                <button class="btn btn-sm btn-primary" type="submit" data-primary-save>Kaydet</button>
            </div>
        </form>

        <?php
        admin_foot();
    }

    /**
     * Tek alan satırı.
     *
     * @param array<string, mixed>|null $field null → boş satır
     * @param list<array<string, mixed>> $siblings koşul açılır listesi için
     */
    private static function fieldRow(int $index, ?array $field, array $siblings): void
    {
        $name = 'alan[' . $index . ']';
        $id   = 'hf-alan-' . $index;
        $get  = static fn(string $key, string $fallback = ''): string
            => (string) ($field[$key] ?? $fallback);
        ?>
        <div class="node" draggable="true" style="display:block;padding:10px 12px"
             data-hf-row data-hf-index="<?= $index ?>">
            <div class="row" style="gap:8px;align-items:center">
                <span class="node-grip" aria-hidden="true"><?= admin_icon('drag', 14) ?></span>

                <label class="sr-only" for="<?= esc_attr($id . '-sira') ?>">Sıra</label>
                <input class="input mono" type="number" id="<?= esc_attr($id . '-sira') ?>"
                       name="<?= esc_attr($name . '[sira]') ?>" value="<?= $index ?>"
                       style="width:62px" data-hf-order>

                <label class="sr-only" for="<?= esc_attr($id . '-key') ?>">Anahtar</label>
                <input class="input mono" type="text" id="<?= esc_attr($id . '-key') ?>"
                       name="<?= esc_attr($name . '[key]') ?>" value="<?= esc_attr($get('key')) ?>"
                       placeholder="anahtar" data-hf-key>

                <label class="sr-only" for="<?= esc_attr($id . '-label') ?>">Etiket</label>
                <input class="input" type="text" id="<?= esc_attr($id . '-label') ?>"
                       name="<?= esc_attr($name . '[label]') ?>" value="<?= esc_attr($get('label')) ?>"
                       placeholder="Etiket">
            </div>

            <div class="row mt-1" style="gap:8px;align-items:center">
                <label class="sr-only" for="<?= esc_attr($id . '-type') ?>">Tür</label>
                <?= ui_select($name . '[type]', Forms::TYPES, $get('type', 'text'),
                    ['id' => $id . '-type', 'style' => 'width:132px', 'data-hf-type' => 'true']) ?>

                <label class="sr-only" for="<?= esc_attr($id . '-width') ?>">Genişlik</label>
                <?= ui_select($name . '[width]', Forms::WIDTHS, $get('width', 'full'),
                    ['id' => $id . '-width', 'style' => 'width:112px']) ?>

                <label class="sr-only" for="<?= esc_attr($id . '-placeholder') ?>">Yer tutucu</label>
                <input class="input" type="text" id="<?= esc_attr($id . '-placeholder') ?>"
                       name="<?= esc_attr($name . '[placeholder]') ?>"
                       value="<?= esc_attr($get('placeholder')) ?>" placeholder="Yer tutucu">

                <label class="check">
                    <input type="checkbox" name="<?= esc_attr($name . '[required]') ?>" value="1"
                        <?= !empty($field['required']) ? 'checked' : '' ?>>
                    <span class="check-body">Zorunlu</span>
                </label>
            </div>

            <div class="row mt-1" style="gap:8px;align-items:center" data-hf-choices>
                <label class="sr-only" for="<?= esc_attr($id . '-choices') ?>">Seçenekler</label>
                <input class="input mono" type="text" id="<?= esc_attr($id . '-choices') ?>"
                       name="<?= esc_attr($name . '[choices]') ?>"
                       value="<?= esc_attr(implode(', ', (array) ($field['choices'] ?? []))) ?>"
                       placeholder="Seçenekler: virgülle ayırın">
            </div>

            <div class="row mt-1" style="gap:8px;align-items:center">
                <span class="small muted" style="white-space:nowrap">Görünsün:</span>

                <label class="sr-only" for="<?= esc_attr($id . '-cond') ?>">Koşul alanı</label>
                <?= ui_select($name . '[cond_field]', self::fieldChoices($siblings, 'her zaman'),
                    $get('cond_field'), ['id' => $id . '-cond', 'style' => 'width:150px']) ?>

                <label class="sr-only" for="<?= esc_attr($id . '-op') ?>">Koşul işleci</label>
                <?= ui_select($name . '[cond_op]', Forms::OPERATORS, $get('cond_op', 'eq'),
                    ['id' => $id . '-op', 'style' => 'width:150px']) ?>

                <label class="sr-only" for="<?= esc_attr($id . '-condval') ?>">Koşul değeri</label>
                <input class="input" type="text" id="<?= esc_attr($id . '-condval') ?>"
                       name="<?= esc_attr($name . '[cond_value]') ?>"
                       value="<?= esc_attr($get('cond_value')) ?>" placeholder="değer">
            </div>

            <div class="row mt-1" style="gap:8px">
                <label class="sr-only" for="<?= esc_attr($id . '-help') ?>">Açıklama</label>
                <input class="input" type="text" id="<?= esc_attr($id . '-help') ?>"
                       name="<?= esc_attr($name . '[help]') ?>" value="<?= esc_attr($get('help')) ?>"
                       placeholder="Alanın altında görünecek açıklama">
            </div>
        </div>
        <?php
    }

    /**
     * Koşul ve yanıt alanı açılır listeleri için alan anahtarları.
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>
     */
    private static function fieldChoices(array $fields, string $blank): array
    {
        $choices = ['' => $blank];

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key !== '') {
                $choices[$key] = (string) ($field['label'] ?? $key) . ' (' . $key . ')';
            }
        }

        return $choices;
    }

    /* ---------------------------------------------------------------------
     * Ayarlar
     * ------------------------------------------------------------------ */

    private static function settingsScreen(): void
    {
        $values = Forms::store()->all();

        admin_head([
            'title'       => 'Form ayarları',
            'slug'        => 'plugin:' . Forms::SLUG,
            'narrow'      => true,
            'description' => 'Bildirim, gizlilik ve istenmeyen koruması.',
            'breadcrumb'  => [['label' => 'HiForms', 'url' => self::url()], ['label' => 'Ayarlar']],
        ]);

        echo self::navTabs('ayarlar');
        ?>

        <form method="post" action="<?= esc_url(self::url(['gorunum' => 'ayarlar'])) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="ayarlar">

            <?php foreach (Forms::store()->sections() as $key => $section) : ?>
                <?php if (!in_array($key, self::SETTING_SECTIONS, true)) { continue; } ?>

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
                            <?= self::settingField($field, $values[$field->key] ?? $field->default) ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="row">
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit" data-primary-save>Ayarları kaydet</button>
            </div>
        </form>

        <?php
        admin_foot();
    }

    /**
     * Ayar alanını türüne göre basar.
     *
     * Tanım bildirimsel olduğu için burada alan başına elle işaretleme yok:
     * yeni bir ayar eklemek `Forms::describe()` içine bir satır yazmak demek.
     */
    private static function settingField(Field $field, mixed $value): string
    {
        $id = 'set-' . $field->key;

        return match ($field->type) {
            'switch' => ui_switch($field->key, (bool) $value, $field->label, $field->help),
            'lines'  => ui_field($field->label,
                '<textarea class="input mono" id="' . esc_attr($id) . '" name="' . esc_attr($field->key)
                . '" rows="3">' . esc_html(implode("\n", is_array($value) ? $value : [])) . '</textarea>',
                esc_html($field->help), $id),
            'textarea' => ui_field($field->label,
                '<textarea class="input" id="' . esc_attr($id) . '" name="' . esc_attr($field->key)
                . '" rows="' . $field->rows . '">' . esc_html((string) $value) . '</textarea>',
                esc_html($field->help), $id),
            'number' => ui_field($field->label,
                ui_input($field->key, (string) (int) $value,
                    ['id' => $id, 'type' => 'number', 'min' => '0', 'style' => 'width:130px']),
                esc_html($field->help), $id),
            'email' => ui_field($field->label,
                ui_input($field->key, (string) $value, ['id' => $id, 'type' => 'email']),
                esc_html($field->help), $id),
            default => ui_field($field->label,
                ui_input($field->key, (string) $value, ['id' => $id, 'placeholder' => (string) $field->placeholder]),
                esc_html($field->help), $id),
        };
    }

    /* ---------------------------------------------------------------------
     * Ortak
     * ------------------------------------------------------------------ */

    /**
     * Gönderi durumu nişanı.
     *
     * `ui_status()` içerik durumlarını (yayında/taslak) biliyor; gönderi
     * durumları başka bir kümedir ve onun etiketlerini ("Onaylı") ödünç almak
     * yanlış anlam üretir.
     */
    private static function statusPill(string $status): string
    {
        [$class, $label] = match ($status) {
            'new'  => ['is-warn', 'Yeni'],
            'spam' => ['is-err', 'İstenmeyen'],
            default => ['is-mute', 'Okundu'],
        };

        return '<span class="pill ' . $class . '">' . esc_html($label) . '</span>';
    }

    /**
     * Satır kenarındaki durum rengi için çekirdeğin bildiği ada eşler.
     *
     * Kenar şeridinin renkleri `tr[data-status]` üzerinden geliyor ve çekirdek
     * kümesi sabit; gönderi durumları en yakın anlama oturtulur.
     */
    private static function rowStatus(string $status): string
    {
        return match ($status) {
            'new'  => 'pending',
            'spam' => 'trash',
            default => 'published',
        };
    }

    private static function navTabs(string $active): string
    {
        return ui_tabs([
            ['label' => 'Gönderiler', 'url' => self::url(), 'active' => $active === 'gonderiler'],
            ['label' => 'Formlar', 'url' => self::url(['gorunum' => 'formlar']), 'active' => $active === 'formlar'],
            ['label' => 'Ayarlar', 'url' => self::url(['gorunum' => 'ayarlar']), 'active' => $active === 'ayarlar'],
        ], 'line');
    }
}
