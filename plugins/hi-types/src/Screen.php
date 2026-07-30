<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Support\Str;

/**
 * HiTypes panel ekranları.
 *
 * Tek kanca (`admin.page.hi-types`) üzerinden dört ekran sunulur; hangisi
 * basılacağı `ekran` sorgu parametresinden gelir:
 *
 *   (yok)        → tür listesi
 *   tur          → tür formu (yeni ya da düzenleme)
 *   sil          → silme onayı (o türde kayıt varsa)
 *   taksonomiler → taksonomi listesi
 *   taksonomi    → taksonomi formu
 *   ayar         → eklenti ayarları + tanım deposu (JSON içe/dışa aktarma)
 *
 * Bütün yazma işlemleri POST + CSRF + 303 yönlendirme kalıbıyla çalışır;
 * JavaScript kapalıyken de tam işlevlidir. JS yalnızca alan düzenleyicide
 * satır ekleyip çıkarmayı ve alan türüne göre gereksiz kutuları saklamayı
 * kolaylaştırır.
 */
final class Screen
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * Panel betiğine geçen değişmez yapılandırma.
     *
     * `hi_admin_data()` bunu `<script type="application/json">` olarak basar;
     * JS tarafında `HiAdmin.data('hi-types')` ile okunur. Satır içi
     * `<script>window.X = …</script>` kullanılamaz: anında sayfa geçişinde
     * gelen betik etiketleri çalıştırılmaz ve veri kaybolur.
     *
     * @return array<string, mixed>
     */
    public static function jsData(): array
    {
        $shows = [];

        foreach (array_keys(TypeBlueprint::FIELD_TYPES) as $type) {
            $boxes = [];

            if (in_array($type, TypeBlueprint::NEEDS_OPTIONS, true)) {
                $boxes[] = 'options';
            }

            if (in_array($type, TypeBlueprint::NEEDS_SUBFIELDS, true)) {
                $boxes[] = 'subfields';
            }

            if (in_array($type, TypeBlueprint::NEEDS_ROWS, true)) {
                $boxes[] = 'rows';
            }

            if (in_array($type, TypeBlueprint::NEEDS_PLACEHOLDER, true)) {
                $boxes[] = 'placeholder';
            }

            $shows[$type] = $boxes;
        }

        return [
            'shows'       => $shows,
            'maxFields'   => TypeBlueprint::MAX_FIELDS,
            'maxSubFields' => TypeBlueprint::MAX_SUBFIELDS,
        ];
    }

    /* ---------------------------------------------------------------------
     * Yönlendirme
     * ------------------------------------------------------------------ */

    public function render(): void
    {
        admin_require('settings.manage');

        if (hi()->request()->isPost()) {
            $this->handle();
        }

        switch ((string) ($_GET['ekran'] ?? '')) {
            case 'tur':
                $this->typeForm();
                break;
            case 'sil':
                $this->deleteConfirm();
                break;
            case 'taksonomiler':
                $this->taxonomyList();
                break;
            case 'taksonomi':
                $this->taxonomyForm();
                break;
            case 'ayar':
                $this->settingsScreen();
                break;
            default:
                $this->typeList();
        }
    }

    private function handle(): never
    {
        admin_verify($this->url());

        // Her kol yönlendirmeyle çıkar; buradan dönüş yok.
        match ((string) ($_POST['islem'] ?? '')) {
            'tur-kaydet'       => $this->saveType(),
            'tur-sil'          => $this->deleteType(),
            'taksonomi-kaydet' => $this->saveTaxonomy(),
            'taksonomi-sil'    => $this->deleteTaxonomy(),
            'ayar-kaydet'      => $this->saveSettings(),
            'depo-kaydet'      => $this->importStore(),
            default            => admin_redirect($this->url(), 'error', 'Bilinmeyen işlem.'),
        };
    }

    /* ---------------------------------------------------------------------
     * Yazma
     * ------------------------------------------------------------------ */

    private function saveType(): never
    {
        $previous = Str::slug((string) ($_POST['onceki'] ?? ''), '_');
        $back     = $this->url($previous !== '' ? ['ekran' => 'tur', 'ad' => $previous] : ['ekran' => 'tur']);

        $result = TypeBlueprint::fromInput($_POST);

        if (!$result['ok']) {
            $this->reject($back, $result['error']);
        }

        $definition = $result['definition'];
        $name       = (string) $definition['name'];

        /*
         * Ad çakışması. Kayıtta aynı adda bir tür varsa ve o bizim değilse
         * (çekirdek ya da tema kaydetmişse) kayıt reddedilir: aksi hâlde
         * çekirdeğin ya da temanın türünü sessizce değiştirirdik.
         */
        $registered = hi()->types()->get($name);

        if ($registered !== null && $registered->source !== $this->store->source()) {
            $this->reject($back, Str::format(
                '"%s" adı zaten kullanılıyor (kaynak: %s). Başka bir makine adı seçin.',
                $name,
                $registered->source
            ));
        }

        if ($name !== $previous && $this->store->type($name) !== null) {
            $this->reject($back, Str::format('"%s" adında bir tür tanımı zaten var.', $name));
        }

        // Rota çakışması: iki tür aynı yolda kayıt açamaz.
        foreach ($this->store->types() as $other) {
            if ((string) ($other['name'] ?? '') === $previous || (string) ($other['name'] ?? '') === $name) {
                continue;
            }

            if ((string) ($other['route'] ?? '') === (string) $definition['route']) {
                $this->reject($back, Str::format(
                    '"/%s" yolu "%s" türünde kullanılıyor. Tek kayıt yolunu değiştirin.',
                    (string) $definition['route'],
                    (string) ($other['name'] ?? '')
                ));
            }
        }

        $this->store->saveType($definition, $previous);

        hi()->audit()->record(
            action: 'types.save',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'content_type',
            summary: $name,
        );

        $fields = count((array) $definition['fields']);

        admin_redirect(
            $this->url(),
            'success',
            Str::format(
                $previous !== '' ? '"%s" türü güncellendi (%d özel alan).' : '"%s" türü oluşturuldu (%d özel alan).',
                (string) $definition['labels']['plural'],
                $fields
            )
        );
    }

    private function deleteType(): never
    {
        $name       = Str::slug((string) ($_POST['ad'] ?? ''), '_');
        $definition = $name !== '' ? $this->store->type($name) : null;

        if ($definition === null) {
            admin_redirect($this->url(), 'error', 'Tür tanımı bulunamadı.');
        }

        $label = (string) (($definition['labels'] ?? [])['plural'] ?? $name);
        $count = $this->store->contentCount($name);

        /*
         * İÇERİK VARSA DOĞRUDAN SİLİNMEZ. Tanımı kaldırmak kayıtları silmez
         * ama panelde erişilemez hâle getirir: liste ekranı, düzenleme formu
         * ve rotalar türle birlikte gider. Kullanıcı bunu görmeden karar
         * vermemeli, o yüzden araya onay ekranı konur.
         */
        if ($count > 0 && (string) ($_POST['onay'] ?? '') !== '1') {
            admin_redirect(
                $this->url(['ekran' => 'sil', 'ad' => $name]),
                'warning',
                Str::format('"%s" türünde %s kayıt var. Ne olacağını seçin.', $label, Str::number($count))
            );
        }

        $moved = 0;

        if ((string) ($_POST['cop'] ?? '') === '1' && $count > 0) {
            foreach (hi()->content()->get([
                'type'          => $name,
                'status'        => 'all',
                'perPage'       => 0,
                'withRelations' => false,
            ]) as $entry) {
                if ($entry->status !== 'trash' && hi()->content()->trash($entry->id)) {
                    $moved++;
                }
            }
        }

        $this->store->deleteType($name);

        hi()->audit()->record(
            action: 'types.delete',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'content_type',
            summary: $name . ($moved > 0 ? ' (' . $moved . ' kayıt çöp kutusuna)' : ''),
        );

        admin_redirect($this->url(), 'success', $moved > 0
            ? Str::format('"%s" türü kaldırıldı; %s kayıt çöp kutusuna taşındı.', $label, Str::number($moved))
            : Str::format(
                '"%s" türü kaldırıldı. %s kayıt veritabanında duruyor; türü aynı adla yeniden'
                . ' oluşturursanız geri gelir.',
                $label,
                Str::number($count)
            ));
    }

    private function saveTaxonomy(): never
    {
        $previous = Str::slug((string) ($_POST['onceki'] ?? ''), '_');
        $back     = $this->url($previous !== ''
            ? ['ekran' => 'taksonomi', 'ad' => $previous]
            : ['ekran' => 'taksonomi']);

        $result = TaxonomyBlueprint::fromInput($_POST);

        if (!$result['ok']) {
            $this->reject($back, $result['error']);
        }

        $definition = $result['definition'];
        $name       = (string) $definition['name'];

        $registered = hi()->types()->taxonomy($name);

        if ($registered !== null && $registered->source !== $this->store->source()) {
            $this->reject($back, Str::format(
                '"%s" adı zaten kullanılıyor (kaynak: %s).',
                $name,
                $registered->source
            ));
        }

        if ($name !== $previous && $this->store->taxonomy($name) !== null) {
            $this->reject($back, Str::format('"%s" adında bir taksonomi tanımı zaten var.', $name));
        }

        $this->store->saveTaxonomy($definition, $previous);

        hi()->audit()->record(
            action: 'types.taxonomy',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'taxonomy',
            summary: $name,
        );

        admin_redirect(
            $this->url(['ekran' => 'taksonomiler']),
            'success',
            Str::format(
                $previous !== '' ? '"%s" taksonomisi güncellendi.' : '"%s" taksonomisi oluşturuldu.',
                (string) $definition['labels']['plural']
            )
        );
    }

    private function deleteTaxonomy(): never
    {
        $name = Str::slug((string) ($_POST['ad'] ?? ''), '_');
        $back = $this->url(['ekran' => 'taksonomiler']);

        if ($name === '' || $this->store->taxonomy($name) === null) {
            admin_redirect($back, 'error', 'Taksonomi tanımı bulunamadı.');
        }

        $terms = hi()->terms()->countIn($name);

        $this->store->deleteTaxonomy($name);

        hi()->audit()->record(
            action: 'types.taxonomy_delete',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'taxonomy',
            summary: $name,
        );

        admin_redirect($back, 'success', $terms > 0
            ? Str::format(
                '"%s" taksonomisi kaldırıldı ve kullandığı türlerden düşürüldü. %s terim'
                . ' veritabanında duruyor.',
                $name,
                Str::number($terms)
            )
            : Str::format('"%s" taksonomisi kaldırıldı.', $name));
    }

    private function saveSettings(): never
    {
        /*
         * Bölüm sınırı verilerek kaydedilir: yalnızca `genel` bölümünün
         * alanları ele alınır. İşaretsiz anahtar POST'a hiç girmediği için
         * "kapalı" sayılır — bu yüzden tanım deposu ayrı bir bölümde durur,
         * yoksa ayar formu her gönderimde tanımları silerdi.
         */
        $this->store->settings()->save((array) ($_POST['ayar'] ?? []), 'genel');

        admin_redirect($this->url(['ekran' => 'ayar']), 'success', 'Ayarlar kaydedildi.');
    }

    private function importStore(): never
    {
        $back  = $this->url(['ekran' => 'ayar']);
        $input = (array) ($_POST['depo'] ?? []);

        $types      = json_decode((string) ($input['types'] ?? ''), true);
        $taxonomies = json_decode((string) ($input['taxonomies'] ?? ''), true);

        if (!is_array($types) || !is_array($taxonomies)) {
            // Yapıştırılan metin korunur: kullanıcı düzeltip yeniden gönderebilir.
            $this->reject($back, 'JSON okunamadı. Metnin tamamını bir dizi ( [ … ] ) olarak yapıştırın.');
        }

        $cleanTypes = [];
        $dropped    = 0;

        foreach ($types as $definition) {
            $normalized = is_array($definition) ? TypeBlueprint::normalize($definition) : [];

            if ($normalized === []) {
                $dropped++;
                continue;
            }

            $cleanTypes[] = $normalized;
        }

        $cleanTaxonomies = [];

        foreach ($taxonomies as $definition) {
            $normalized = is_array($definition) ? TaxonomyBlueprint::normalize($definition) : [];

            if ($normalized === []) {
                $dropped++;
                continue;
            }

            $cleanTaxonomies[] = $normalized;
        }

        $this->store->replaceAll($cleanTypes, $cleanTaxonomies);

        hi()->audit()->record(
            action: 'types.import',
            userId: hi()->auth()->id(),
            actor: (string) hi()->auth()->user()?->displayName,
            subjectType: 'content_type',
            summary: count($cleanTypes) . ' tür, ' . count($cleanTaxonomies) . ' taksonomi',
        );

        admin_redirect($back, 'success', Str::format(
            '%d tür ve %d taksonomi tanımı içe alındı.%s',
            count($cleanTypes),
            count($cleanTaxonomies),
            $dropped > 0 ? ' ' . $dropped . ' geçersiz tanım atlandı.' : ''
        ));
    }

    /* ---------------------------------------------------------------------
     * Tür listesi
     * ------------------------------------------------------------------ */

    private function typeList(): void
    {
        $types      = $this->store->types();
        $taxonomies = $this->store->taxonomies();

        $fieldCount   = 0;
        $contentCount = 0;
        $rows         = [];

        foreach ($types as $definition) {
            $definition   = TypeBlueprint::normalize($definition);

            if ($definition === []) {
                continue;
            }

            $count        = $this->store->contentCount((string) $definition['name']);
            $fields       = count((array) $definition['fields']);
            $fieldCount  += $fields;
            $contentCount += $count;

            $rows[] = ['definition' => $definition, 'count' => $count, 'fields' => $fields];
        }

        admin_head([
            'title'       => 'İçerik türleri',
            'slug'        => 'plugin:hi-types',
            'count'       => count($rows),
            'description' => 'Kod yazmadan içerik türü, taksonomi ve özel alan tanımlayın.',
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiTypes']],
            'actions'     => '<a class="btn btn-primary btn-sm" href="' . esc_url($this->url(['ekran' => 'tur'])) . '">'
                . admin_icon('plus', 14) . 'Yeni tür</a>',
        ]);

        echo $this->tabs('tur');

        echo ui_metrics([
            ['label' => 'Tanımlı tür', 'value' => Str::number(count($rows))],
            ['label' => 'Özel alan', 'value' => Str::number($fieldCount)],
            ['label' => 'Kayıt', 'value' => Str::number($contentCount)],
            ['label' => 'Taksonomi', 'value' => Str::number(count($taxonomies)),
             'href' => $this->url(['ekran' => 'taksonomiler'])],
        ]);
        ?>

        <section class="panel">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Tanımlı türler</h2>
                    <p class="panel-sub">Çekirdeğin Yazı ve Sayfa türleri burada listelenmez</p>
                </div>
            </header>

            <?php if ($rows !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Tür</th>
                                <th>Yol</th>
                                <th>Arşiv</th>
                                <th>Taksonomi</th>
                                <th class="num">Alan</th>
                                <th class="num">Kayıt</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                $definition = $row['definition'];
                                $name       = (string) $definition['name'];
                                $editUrl    = $this->url(['ekran' => 'tur', 'ad' => $name]);
                                ?>
                                <tr data-status="<?= $definition['public'] ? 'published' : 'draft' ?>">
                                    <td class="edge">
                                        <a class="cell-title" href="<?= esc_url($editUrl) ?>">
                                            <?= esc_html((string) $definition['labels']['plural']) ?>
                                        </a>
                                        <span class="cell-sub"><?= esc_html($name) ?></span>
                                    </td>
                                    <td class="mono small muted">/<?= esc_html((string) $definition['route']) ?></td>
                                    <td class="mono small muted">
                                        <?= (string) $definition['archive'] !== ''
                                            ? '/' . esc_html((string) $definition['archive'])
                                            : '<span class="muted">—</span>' ?>
                                    </td>
                                    <td class="small muted">
                                        <?= $definition['taxonomies'] !== []
                                            ? esc_html(implode(', ', $definition['taxonomies']))
                                            : '<span class="muted">—</span>' ?>
                                    </td>
                                    <td class="num"><?= (int) $row['fields'] ?></td>
                                    <td class="num"><?= (int) $row['count'] ?></td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url('content.php?tur=' . rawurlencode($name)) ?>"
                                               title="İçerikleri gör" aria-label="İçerikleri gör">
                                                <?= admin_icon('list', 15) ?>
                                            </a>
                                            <a class="icon-btn" href="<?= esc_url($editUrl) ?>"
                                               title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                            <button class="icon-btn" type="submit" form="tur-sil"
                                                    name="ad" value="<?= esc_attr($name) ?>"
                                                    title="Kaldır" aria-label="Kaldır"
                                                <?= ui_confirm('Tür tanımı kaldırılacak. Kayıtlar silinmez.') ?>>
                                                <?= admin_icon('trash', 15) ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?= ui_empty(
                    'grid',
                    'Henüz tür yok',
                    'İlk içerik türünüzü oluşturun — örneğin "Portfolyo": kendi listesi, kendi'
                    . ' alanları ve kendi arşiv sayfasıyla gelir.',
                    '<a class="btn btn-primary btn-sm" href="' . esc_url($this->url(['ekran' => 'tur'])) . '">'
                    . 'Yeni tür</a>'
                ) ?>
            <?php endif; ?>
        </section>

        <form id="tur-sil" method="post" action="<?= esc_url($this->url()) ?>" hidden>
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="tur-sil">
        </form>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Tür formu
     * ------------------------------------------------------------------ */

    private function typeForm(): void
    {
        $name    = Str::slug((string) ($_GET['ad'] ?? ''), '_');
        $stored  = $name !== '' ? $this->store->type($name) : null;
        $editing = $stored !== null;

        $definition = $editing ? TypeBlueprint::normalize($stored) : TypeBlueprint::blank();

        if ($definition === []) {
            $definition = TypeBlueprint::blank();
            $editing    = false;
        }

        // Reddedilmiş gönderim varsa form onunla basılır: girilen hiçbir şey kaybolmaz.
        $retry = $this->takeInput('tur-kaydet');

        if ($retry !== null) {
            $definition = TypeBlueprint::draft($retry);
        }

        $selfUrl = $this->url(['ekran' => 'tur'] + ($editing ? ['ad' => $name] : []));

        admin_head([
            'title'       => $editing
                ? (string) $definition['labels']['plural']
                : 'Yeni içerik türü',
            'slug'        => 'plugin:hi-types',
            'narrow'      => true,
            'description' => $editing
                ? 'Makine adını değiştirmek eski kalıcı bağlantıları kırar; kayıtlar taşınmaz.'
                : 'Tür oluşturulduğu anda panel menüsünde, listesinde ve ön yüz rotalarında görünür.',
            'breadcrumb'  => [
                ['label' => 'HiTypes', 'url' => $this->url()],
                ['label' => $editing ? (string) $definition['labels']['plural'] : 'Yeni tür'],
            ],
            'actions'     => '<button class="btn btn-primary btn-sm" type="submit" form="tur-form" data-primary-save>'
                . admin_icon('save', 14) . ($editing ? 'Güncelle' : 'Oluştur') . '</button>',
        ]);

        $taxonomyOptions = [];

        foreach (hi()->types()->taxonomies() as $taxonomy) {
            $taxonomyOptions[$taxonomy->name] = $taxonomy->plural;
        }
        ?>

        <form id="tur-form" method="post" action="<?= esc_url($selfUrl) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="tur-kaydet">
            <input type="hidden" name="onceki" value="<?= esc_attr($editing ? $name : '') ?>">

            <section class="box">
                <header class="box-head"><?= admin_icon('grid', 15) ?>Kimlik</header>
                <div class="box-body">
                    <?= ui_field(
                        'Makine adı',
                        ui_input('ad', (string) $definition['name'], [
                            'id'          => 'hit-ad',
                            'class'       => 'input mono',
                            'required'    => true,
                            'maxlength'   => '32',
                            'placeholder' => 'portfolyo',
                        ]),
                        'Harf, sayı ve alt çizgi. Veritabanında bu ad saklanır; sonradan değiştirirseniz'
                        . ' eski kayıtlar eski adla kalır.',
                        'hit-ad',
                        true
                    ) ?>

                    <div class="field-row">
                        <?= ui_field('Tekil ad', ui_input('tekil', (string) $definition['labels']['singular'], [
                            'id' => 'hit-tekil', 'placeholder' => 'Proje',
                        ]), '', 'hit-tekil') ?>

                        <?= ui_field('Çoğul ad', ui_input('cogul', (string) $definition['labels']['plural'], [
                            'id' => 'hit-cogul', 'placeholder' => 'Portfolyo',
                        ]), 'Panel menüsünde bu ad görünür.', 'hit-cogul') ?>
                    </div>

                    <?= ui_field(
                        'Açıklama',
                        '<textarea class="input" id="hit-aciklama" name="aciklama" rows="2">'
                        . esc_html((string) $definition['description']) . '</textarea>',
                        'Yalnızca panelde görünür; ne için kullanıldığını hatırlatır.',
                        'hit-aciklama'
                    ) ?>
                </div>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('link', 15) ?>Adres, sıra ve görünürlük</header>
                <div class="box-body">
                    <div class="field-row">
                        <?= ui_field(
                            'Tek kayıt yolu',
                            '<div class="input-group"><span class="addon">/</span>'
                            . ui_input('rota', (string) $definition['route'], [
                                'id' => 'hit-rota', 'class' => 'input mono', 'placeholder' => 'proje',
                            ]) . '</div>',
                            'Adres: <code>/proje/kayit-adi</code>. Boş bırakırsanız makine adı kullanılır.',
                            'hit-rota'
                        ) ?>

                        <?= ui_field(
                            'Arşiv yolu',
                            '<div class="input-group"><span class="addon">/</span>'
                            . ui_input('arsiv', (string) $definition['archive'], [
                                'id' => 'hit-arsiv', 'class' => 'input mono', 'placeholder' => 'portfolyo',
                            ]) . '</div>',
                            'Tüm kayıtları listeleyen sayfa. Boş bırakılırsa arşiv oluşmaz.',
                            'hit-arsiv'
                        ) ?>
                    </div>

                    <div class="field-row">
                        <?= ui_field(
                            'Menü ikonu',
                            ui_select('ikon', $this->iconOptions(), (string) $definition['icon'], ['id' => 'hit-ikon']),
                            'Panel menüsünde türün yanında görünür.',
                            'hit-ikon'
                        ) ?>

                        <?= ui_field(
                            'Arşiv sıralaması',
                            ui_select('siralama', TypeBlueprint::ORDER_COLUMNS, (string) $definition['order_by'], [
                                'id' => 'hit-siralama',
                            ])
                            . ui_select('yon', ['desc' => 'Azalan (yeni önce)', 'asc' => 'Artan (eski önce)'],
                                (string) $definition['order_dir'], ['id' => 'hit-yon', 'class' => 'input select mt-1']),
                            'Ön yüzdeki arşiv sayfasının sırası. "Elle sıra" için sıralama desteği açık olmalı.',
                            'hit-siralama'
                        ) ?>
                    </div>

                    <div class="mt-3">
                        <?= ui_switch('acik', (bool) $definition['public'], 'Ön yüzde görünür',
                            'Kapatırsanız rota ve arşiv oluşmaz; tür yalnızca panelde kullanılır.') ?>

                        <?= ui_switch('hiyerarsik', (bool) $definition['hierarchical'], 'Hiyerarşik',
                            'Kayıtlar üst-alt ilişkisi kurabilir (Sayfa türü gibi).') ?>
                    </div>
                </div>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('sliders', 15) ?>Destekler, durumlar, taksonomiler</header>
                <div class="box-body">
                    <p class="label">Düzenleyici desteği</p>
                    <?php
                    $supports = (array) $definition['supports'];

                    foreach (TypeBlueprint::SUPPORTS as $key => $meta) {
                        echo $this->checkbox(
                            'destek[' . $key . ']',
                            in_array($key, $supports, true),
                            $meta[0],
                            $meta[1]
                        );
                    }
                    ?>

                    <div class="mt-3">
                        <p class="label">Yayın durumları</p>
                        <p class="hint mb-2">İçerik düzenleyicideki durum listesi bunlardan üretilir.
                            Taslak her zaman bulunur.</p>
                        <?php
                        $statuses = (array) $definition['statuses'];

                        foreach (TypeBlueprint::STATUSES as $key => $label) {
                            echo $this->checkbox(
                                'durum[' . $key . ']',
                                in_array($key, $statuses, true),
                                $label,
                                $key === 'draft' ? 'Kaldırılamaz' : ''
                            );
                        }
                        ?>
                    </div>

                    <div class="mt-3">
                        <p class="label">Taksonomiler</p>
                        <?php
                        $selected = (array) $definition['taxonomies'];

                        if ($taxonomyOptions === []) {
                            echo '<p class="hint">Kayıtlı taksonomi yok.</p>';
                        }

                        foreach ($taxonomyOptions as $key => $label) {
                            echo '<label class="check"><input type="checkbox" name="taksonomi[]" value="'
                                . esc_attr((string) $key) . '"'
                                . (in_array((string) $key, $selected, true) ? ' checked' : '') . '>'
                                . '<span class="check-body"><strong>' . esc_html($label) . '</strong>'
                                . '<small>' . esc_html((string) $key) . '</small></span></label>';
                        }
                        ?>
                        <p class="hint mt-1">
                            <a href="<?= esc_url($this->url(['ekran' => 'taksonomi'])) ?>">Yeni taksonomi tanımla</a>
                        </p>
                    </div>
                </div>
            </section>

            <section class="box">
                <header class="box-head"><?= admin_icon('brackets', 15) ?>Özel alanlar</header>
                <div class="box-body">
                    <p class="hint mb-2">
                        En fazla <?= TypeBlueprint::MAX_FIELDS ?> alan. Alan türü içerik düzenleyicideki
                        girdiyi ve kaydedilen değerin temizlenme kuralını belirler. Anahtar, tema tarafında
                        <code>hi_field('anahtar')</code> ile okunur.
                    </p>

                    <?php
                    /*
                     * Dürüst uyarı: çekirdek 0.3.0 düzenleyicisi bu iki tür için girdi basmıyor
                     * (admin/content-edit.php alan eşlemesinde karşılıkları yok). Tanım geçerli,
                     * temizleyici çalışıyor; ama kaydı panelden kaydetmek değeri boşaltır.
                     */
                    ?>
                    <p class="hint mb-2">
                        <strong>Yinelenen grup</strong> ve <strong>Medya listesi</strong> alanları için çekirdek
                        0.3.0 düzenleyicisi girdi basmaz: tanım geçerlidir ve değer tema tarafında okunur, ama
                        kaydı panelden kaydetmek bu alanları boşaltır. Şimdilik API ya da göç betiğiyle
                        doldurulan veriler için kullanın.
                    </p>

                    <?= $this->fieldGroup((array) $definition['fields']) ?>
                </div>

                <footer class="box-foot">
                    <a class="btn btn-sm" href="<?= esc_url($this->url()) ?>">Vazgeç</a>
                    <?php if ($editing) : ?>
                        <button class="btn btn-sm" type="submit" form="tur-sil-form"
                            <?= ui_confirm('Tür tanımı kaldırılacak. Kayıtlar silinmez.') ?>>
                            <?= admin_icon('trash', 14) ?>Türü kaldır
                        </button>
                    <?php endif; ?>
                    <span class="spacer"></span>
                    <button class="btn btn-sm btn-primary" type="submit" data-primary-save>
                        <?= $editing ? 'Güncelle' : 'Türü oluştur' ?>
                    </button>
                </footer>
            </section>
        </form>

        <?php if ($editing) : ?>
            <form id="tur-sil-form" method="post" action="<?= esc_url($this->url()) ?>" hidden>
                <?= hi_csrf_field() ?>
                <input type="hidden" name="islem" value="tur-sil">
                <input type="hidden" name="ad" value="<?= esc_attr($name) ?>">
            </form>
        <?php endif; ?>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Silme onayı
     * ------------------------------------------------------------------ */

    private function deleteConfirm(): void
    {
        $name       = Str::slug((string) ($_GET['ad'] ?? ''), '_');
        $definition = $name !== '' ? $this->store->type($name) : null;

        if ($definition === null) {
            admin_redirect($this->url(), 'error', 'Tür tanımı bulunamadı.');
        }

        $definition = TypeBlueprint::normalize($definition);
        $label      = (string) $definition['labels']['plural'];
        $counts     = hi()->content()->statusCounts($name);
        $total      = (int) ($counts['all'] ?? 0);
        $trashFirst = (bool) $this->store->settings()->get('trash_on_delete', false);

        admin_head([
            'title'       => $label . ' türünü kaldır',
            'slug'        => 'plugin:hi-types',
            'narrow'      => true,
            'description' => 'Tanım kaldırılınca bu türün panel listesi, düzenleme formu ve rotaları kaybolur.',
            'breadcrumb'  => [
                ['label' => 'HiTypes', 'url' => $this->url()],
                ['label' => $label, 'url' => $this->url(['ekran' => 'tur', 'ad' => $name])],
                ['label' => 'Kaldır'],
            ],
        ]);

        echo ui_notice('warning', Str::format(
            'Bu türde %s kayıt var. Tanımı kaldırmak kayıtları SİLMEZ; ama türü yeniden'
            . ' oluşturana kadar panelden erişilemezler.',
            Str::number($total)
        ));

        echo ui_metrics([
            ['label' => 'Toplam', 'value' => Str::number($total)],
            ['label' => 'Yayında', 'value' => Str::number((int) ($counts['published'] ?? 0))],
            ['label' => 'Taslak', 'value' => Str::number((int) ($counts['draft'] ?? 0))],
            ['label' => 'İncelemede', 'value' => Str::number((int) ($counts['pending'] ?? 0))],
            ['label' => 'Özel', 'value' => Str::number((int) ($counts['private'] ?? 0))],
        ]);
        ?>

        <form class="box" method="post" action="<?= esc_url($this->url()) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="tur-sil">
            <input type="hidden" name="ad" value="<?= esc_attr($name) ?>">
            <input type="hidden" name="onay" value="1">

            <header class="box-head"><?= admin_icon('alert', 15) ?>Ne olacak?</header>

            <div class="box-body">
                <p class="hint mb-2">
                    Kayıtlar <code>content</code> tablosunda <code><?= esc_html($name) ?></code> türüyle durur.
                    İsterseniz aynı işlemde çöp kutusuna taşıyıp panelden geri getirilebilir hâle
                    getirebilirsiniz — çöp kutusundan geri alınan kayıt taslak olur.
                </p>

                <?= $this->checkbox(
                    'cop',
                    $trashFirst,
                    'Kayıtları çöp kutusuna taşı',
                    Str::format('%s kayıt "çöp" durumuna geçer; 30 gün sonra planlı görev kalıcı siler.',
                        Str::number($total))
                ) ?>

                <?php if ((int) ($counts['published'] ?? 0) > 0) : ?>
                    <p class="hint mt-2">
                        Yayında <?= esc_html(Str::number((int) $counts['published'])) ?> kayıt var; çöp kutusuna
                        taşırsanız bu adresler ön yüzde 404 verir.
                    </p>
                <?php endif; ?>
            </div>

            <footer class="box-foot">
                <a class="btn btn-sm" href="<?= esc_url($this->url()) ?>">Vazgeç</a>
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit">Tanımı kaldır</button>
            </footer>
        </form>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Taksonomiler
     * ------------------------------------------------------------------ */

    private function taxonomyList(): void
    {
        $rows = [];

        foreach ($this->store->taxonomies() as $definition) {
            $definition = TaxonomyBlueprint::normalize($definition);

            if ($definition === []) {
                continue;
            }

            $rows[] = [
                'definition' => $definition,
                'terms'      => hi()->terms()->countIn((string) $definition['name']),
                'used'       => $this->typesUsing((string) $definition['name']),
            ];
        }

        admin_head([
            'title'       => 'Taksonomiler',
            'slug'        => 'plugin:hi-types',
            'count'       => count($rows),
            'description' => 'Kategori ve etiketten başka gruplamalar: beceri, sektör, mekân, yıl.',
            'breadcrumb'  => [['label' => 'HiTypes', 'url' => $this->url()], ['label' => 'Taksonomiler']],
            'actions'     => '<a class="btn btn-primary btn-sm" href="'
                . esc_url($this->url(['ekran' => 'taksonomi'])) . '">'
                . admin_icon('plus', 14) . 'Yeni taksonomi</a>',
        ]);

        echo $this->tabs('taksonomi');
        ?>

        <section class="panel">
            <header class="panel-head">
                <div>
                    <h2 class="panel-title">Tanımlı taksonomiler</h2>
                    <p class="panel-sub">Çekirdeğin Kategoriler ve Etiketler taksonomileri burada listelenmez</p>
                </div>
            </header>

            <?php if ($rows !== []) : ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Taksonomi</th>
                                <th>Yol</th>
                                <th>Biçim</th>
                                <th>Kullanan tür</th>
                                <th class="num">Terim</th>
                                <th class="fit"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                $definition = $row['definition'];
                                $key        = (string) $definition['name'];
                                $editUrl    = $this->url(['ekran' => 'taksonomi', 'ad' => $key]);
                                ?>
                                <tr data-status="<?= $definition['public'] ? 'published' : 'draft' ?>">
                                    <td class="edge">
                                        <a class="cell-title" href="<?= esc_url($editUrl) ?>">
                                            <?= esc_html((string) $definition['labels']['plural']) ?>
                                        </a>
                                        <span class="cell-sub"><?= esc_html($key) ?></span>
                                    </td>
                                    <td class="mono small muted">/<?= esc_html((string) $definition['route']) ?></td>
                                    <td class="small muted">
                                        <?= $definition['single'] ? 'Tek seçim' : 'Çoklu seçim' ?><?php
                                        ?><?= $definition['hierarchical'] ? ' · ağaç' : '' ?><?php
                                        ?><?= $definition['color'] ? ' · renkli' : '' ?>
                                    </td>
                                    <td class="small muted">
                                        <?= $row['used'] !== []
                                            ? esc_html(implode(', ', $row['used']))
                                            : '<span class="muted">—</span>' ?>
                                    </td>
                                    <td class="num"><?= (int) $row['terms'] ?></td>
                                    <td class="fit">
                                        <div class="row-acts">
                                            <a class="icon-btn" href="<?= esc_url('terms.php?taksonomi=' . rawurlencode($key)) ?>"
                                               title="Terimleri yönet" aria-label="Terimleri yönet">
                                                <?= admin_icon('folder', 15) ?>
                                            </a>
                                            <a class="icon-btn" href="<?= esc_url($editUrl) ?>"
                                               title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                            <button class="icon-btn" type="submit" form="taksonomi-sil"
                                                    name="ad" value="<?= esc_attr($key) ?>"
                                                    title="Kaldır" aria-label="Kaldır"
                                                <?= ui_confirm('Taksonomi tanımı kaldırılacak ve kullandığı'
                                                    . ' türlerden düşürülecek. Terimler silinmez.') ?>>
                                                <?= admin_icon('trash', 15) ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <?= ui_empty(
                    'tag',
                    'Henüz taksonomi yok',
                    'Yeni bir gruplama tanımlayın — örneğin "Beceri": terimleri Terimler ekranından'
                    . ' yönetilir, içeriğe düzenleyiciden bağlanır.',
                    '<a class="btn btn-primary btn-sm" href="'
                    . esc_url($this->url(['ekran' => 'taksonomi'])) . '">Yeni taksonomi</a>'
                ) ?>
            <?php endif; ?>
        </section>

        <form id="taksonomi-sil" method="post" action="<?= esc_url($this->url()) ?>" hidden>
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="taksonomi-sil">
        </form>

        <?php
        admin_foot();
    }

    private function taxonomyForm(): void
    {
        $name    = Str::slug((string) ($_GET['ad'] ?? ''), '_');
        $stored  = $name !== '' ? $this->store->taxonomy($name) : null;
        $editing = $stored !== null;

        $definition = $editing ? TaxonomyBlueprint::normalize($stored) : TaxonomyBlueprint::blank();

        if ($definition === []) {
            $definition = TaxonomyBlueprint::blank();
            $editing    = false;
        }

        $retry = $this->takeInput('taksonomi-kaydet');

        if ($retry !== null) {
            $definition = TaxonomyBlueprint::draft($retry);
        }

        admin_head([
            'title'       => $editing ? (string) $definition['labels']['plural'] : 'Yeni taksonomi',
            'slug'        => 'plugin:hi-types',
            'narrow'      => true,
            'description' => 'Taksonomi bir gruplama sözleşmesidir; terimleri Terimler ekranından girilir.',
            'breadcrumb'  => [
                ['label' => 'HiTypes', 'url' => $this->url()],
                ['label' => 'Taksonomiler', 'url' => $this->url(['ekran' => 'taksonomiler'])],
                ['label' => $editing ? (string) $definition['labels']['plural'] : 'Yeni'],
            ],
        ]);
        ?>

        <form class="box" method="post" action="<?= esc_url($this->url(['ekran' => 'taksonomi'])) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="taksonomi-kaydet">
            <input type="hidden" name="onceki" value="<?= esc_attr($editing ? $name : '') ?>">

            <header class="box-head"><?= admin_icon('tag', 15) ?><?= $editing ? 'Taksonomiyi düzenle' : 'Yeni taksonomi' ?></header>

            <div class="box-body">
                <?= ui_field('Makine adı', ui_input('ad', (string) $definition['name'], [
                    'id' => 'hit-tax-ad', 'class' => 'input mono', 'required' => true,
                    'maxlength' => '32', 'placeholder' => 'beceri',
                ]), 'Harf, sayı ve alt çizgi. Terimler bu adla saklanır.', 'hit-tax-ad', true) ?>

                <div class="field-row">
                    <?= ui_field('Tekil ad', ui_input('tekil', (string) $definition['labels']['singular'], [
                        'id' => 'hit-tax-tekil', 'placeholder' => 'Beceri',
                    ]), '', 'hit-tax-tekil') ?>

                    <?= ui_field('Çoğul ad', ui_input('cogul', (string) $definition['labels']['plural'], [
                        'id' => 'hit-tax-cogul', 'placeholder' => 'Beceriler',
                    ]), 'Panel menüsünde bu ad görünür.', 'hit-tax-cogul') ?>
                </div>

                <?= ui_field(
                    'Arşiv yolu',
                    '<div class="input-group"><span class="addon">/</span>'
                    . ui_input('rota', (string) $definition['route'], [
                        'id' => 'hit-tax-rota', 'class' => 'input mono', 'placeholder' => 'beceri',
                    ]) . '</div>',
                    'Terim arşivi bu adreste açılır: <code>/beceri/terim-adi</code>.',
                    'hit-tax-rota'
                ) ?>

                <?= ui_field(
                    'Açıklama',
                    '<textarea class="input" id="hit-tax-aciklama" name="aciklama" rows="2">'
                    . esc_html((string) $definition['description']) . '</textarea>',
                    '',
                    'hit-tax-aciklama'
                ) ?>

                <div class="mt-3">
                    <?= ui_switch('tek', (bool) $definition['single'], 'Tek seçim',
                        'Kategori gibi davranır: içerik yalnızca bir terime bağlanır.') ?>

                    <?= ui_switch('hiyerarsik', (bool) $definition['hierarchical'], 'Hiyerarşik',
                        'Terimler üst-alt ilişkisi kurabilir.') ?>

                    <?= ui_switch('renk', (bool) $definition['color'], 'Renk alanı',
                        'Her terime bir renk atanır; temada nişan olarak kullanılır.') ?>

                    <?= ui_switch('acik', (bool) $definition['public'], 'Ön yüzde görünür',
                        'Kapatırsanız terim arşivi oluşmaz.') ?>
                </div>
            </div>

            <footer class="box-foot">
                <a class="btn btn-sm" href="<?= esc_url($this->url(['ekran' => 'taksonomiler'])) ?>">Vazgeç</a>
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit" data-primary-save>
                    <?= $editing ? 'Güncelle' : 'Taksonomiyi oluştur' ?>
                </button>
            </footer>
        </form>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Ayarlar
     * ------------------------------------------------------------------ */

    private function settingsScreen(): void
    {
        $settings = $this->store->settings();
        $values   = $settings->all();

        // Reddedilen içe alma varsa yapıştırılan metin geri basılır.
        $retry   = $this->takeInput('depo-kaydet');
        $pasted  = is_array($retry) ? (array) ($retry['depo'] ?? []) : [];
        $depoT   = array_key_exists('types', $pasted)
            ? (string) $pasted['types']
            : $this->prettyJson($this->store->types());
        $depoX   = array_key_exists('taxonomies', $pasted)
            ? (string) $pasted['taxonomies']
            : $this->prettyJson($this->store->taxonomies());

        admin_head([
            'title'       => 'HiTypes ayarları',
            'slug'        => 'plugin:hi-types',
            'narrow'      => true,
            'description' => 'Bütün tanımlı türler için geçerli davranışlar ve tanım deposu.',
            'breadcrumb'  => [['label' => 'HiTypes', 'url' => $this->url()], ['label' => 'Ayarlar']],
        ]);

        echo $this->tabs('ayar');
        ?>

        <form class="box" method="post" action="<?= esc_url($this->url(['ekran' => 'ayar'])) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="ayar-kaydet">

            <header class="box-head"><?= admin_icon('settings', 15) ?>Genel</header>

            <div class="box-body">
                <div class="field-row">
                    <?= ui_field(
                        'Menü sırası',
                        ui_input('ayar[menu_order]', (string) (int) ($values['menu_order'] ?? 60), [
                            'type' => 'number', 'id' => 'hit-order', 'min' => '0', 'max' => '999',
                        ]),
                        'Yazılar 10, Sayfalar 20 sırasında. Daha büyük değer türleri menüde aşağıya alır.',
                        'hit-order'
                    ) ?>

                    <?= ui_field(
                        'Alan kutusu başlığı',
                        ui_input('ayar[field_group]', (string) ($values['field_group'] ?? 'Alanlar'), [
                            'id' => 'hit-group', 'placeholder' => 'Alanlar',
                        ]),
                        'Grubu belirtilmemiş alanların düzenleyicide toplandığı kutunun başlığı.',
                        'hit-group'
                    ) ?>
                </div>

                <div class="mt-3">
                    <?= ui_switch(
                        'ayar[trash_on_delete]',
                        (bool) ($values['trash_on_delete'] ?? false),
                        'Tür silinince içerikleri çöp kutusuna taşı',
                        'Silme onayındaki kutunun başlangıç durumunu belirler; işlem her koşulda onay ister.'
                    ) ?>
                </div>
            </div>

            <footer class="box-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm btn-primary" type="submit" data-primary-save>
                    <?= admin_icon('save', 14) ?>Kaydet
                </button>
            </footer>
        </form>

        <form class="box" method="post" action="<?= esc_url($this->url(['ekran' => 'ayar'])) ?>">
            <?= hi_csrf_field() ?>
            <input type="hidden" name="islem" value="depo-kaydet">

            <header class="box-head"><?= admin_icon('code', 15) ?>Tanım deposu</header>

            <div class="box-body">
                <p class="hint mb-2">
                    Tanımlar tek ayar satırında (<code>plugin.hi-types.settings</code>) JSON olarak durur.
                    Aşağıdaki metni kopyalayıp başka bir kuruluma yapıştırabilirsiniz; kaydederken her
                    tanım doğrulanır, geçersiz olanlar atlanır. <strong>Kaydetmek mevcut tanımların
                    yerine geçer.</strong>
                </p>

                <?= ui_field(
                    'Tür tanımları',
                    '<textarea class="input mono" id="hit-depo-t" name="depo[types]" rows="8" spellcheck="false">'
                    . esc_html($depoT) . '</textarea>',
                    '',
                    'hit-depo-t'
                ) ?>

                <?= ui_field(
                    'Taksonomi tanımları',
                    '<textarea class="input mono" id="hit-depo-x" name="depo[taxonomies]" rows="5" spellcheck="false">'
                    . esc_html($depoX) . '</textarea>',
                    '',
                    'hit-depo-x'
                ) ?>
            </div>

            <footer class="box-foot">
                <span class="spacer"></span>
                <button class="btn btn-sm" type="submit"
                    <?= ui_confirm('Tanımların tamamı yapıştırılan JSON ile değiştirilecek.') ?>>
                    <?= admin_icon('upload', 14) ?>Tanımları içe al
                </button>
            </footer>
        </form>

        <?php
        admin_foot();
    }

    /* ---------------------------------------------------------------------
     * Parçalar
     * ------------------------------------------------------------------ */

    /**
     * Gönderimi reddeder ama EMEĞİ SAKLAR.
     *
     * Yirmi alan tanımlayıp makine adını yanlış yazan kullanıcı, tek bir hata
     * yüzünden formun tamamını yeniden dolduramaz. Reddedilen POST bir sonraki
     * istekte forma geri basılmak üzere oturuma bırakılır.
     */
    private function reject(string $url, string $message): never
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $input = $_POST;

            unset($input['_token']);

            $_SESSION['hitypes.form'] = $input;
        }

        admin_redirect($url, 'error', $message);
    }

    /**
     * Reddedilmiş gönderimi alır ve kuyruğu temizler (tek kullanımlık).
     *
     * @return array<string, mixed>|null
     */
    private function takeInput(string $action): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $input = $_SESSION['hitypes.form'] ?? null;

        unset($_SESSION['hitypes.form']);

        if (!is_array($input) || (string) ($input['islem'] ?? '') !== $action) {
            return null;
        }

        return $input;
    }

    /**
     * @param array<string, string|int> $params
     */
    private function url(array $params = []): string
    {
        return 'plugin.php?' . http_build_query(array_merge(['eklenti' => $this->store->slug()], $params));
    }

    private function tabs(string $active): string
    {
        return ui_tabs([
            [
                'label'  => 'Türler',
                'url'    => $this->url(),
                'active' => $active === 'tur',
                'count'  => count($this->store->types()),
            ],
            [
                'label'  => 'Taksonomiler',
                'url'    => $this->url(['ekran' => 'taksonomiler']),
                'active' => $active === 'taksonomi',
                'count'  => count($this->store->taxonomies()),
            ],
            [
                'label'  => 'Ayarlar',
                'url'    => $this->url(['ekran' => 'ayar']),
                'active' => $active === 'ayar',
            ],
        ], 'line');
    }

    /**
     * Bir taksonomiyi kullanan tür adları.
     *
     * @return list<string>
     */
    private function typesUsing(string $taxonomy): array
    {
        $names = [];

        foreach ($this->store->types() as $definition) {
            if (in_array($taxonomy, array_map('strval', (array) ($definition['taxonomies'] ?? [])), true)) {
                $names[] = (string) ($definition['name'] ?? '');
            }
        }

        return $names;
    }

    /**
     * Panelin ikon kümesi — seçim listesi için.
     *
     * @return array<string, string>
     */
    private function iconOptions(): array
    {
        $options = [];

        foreach (array_keys(admin_icon_paths()) as $icon) {
            $options[(string) $icon] = (string) $icon;
        }

        ksort($options);

        return $options;
    }

    private function checkbox(string $name, bool $checked, string $label, string $hint = ''): string
    {
        return '<label class="check"><input type="checkbox" name="' . Str::attr($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '>'
            . '<span class="check-body"><strong>' . esc_html($label) . '</strong>'
            . ($hint !== '' ? '<small>' . esc_html($hint) . '</small>' : '')
            . '</span></label>';
    }

    /** @param list<array<string, mixed>> $list */
    private function prettyJson(array $list): string
    {
        return (string) json_encode(
            $list,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    /* ---------------------------------------------------------------------
     * Alan düzenleyici
     *
     * JS'siz de çalışması şart: var olan alanlar + iki boş yuva basılır,
     * anahtarı boş bırakılan yuva sunucuda düşer. JS varsa satır ekleme,
     * satır çıkarma ve alan türüne göre kutu saklama devreye girer.
     * ------------------------------------------------------------------ */

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function fieldGroup(array $fields): string
    {
        $rows  = '';
        $index = 0;

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $rows .= $this->fieldNode((string) $index, $field);
            $index++;
        }

        for ($blank = 0; $blank < 2 && $index < TypeBlueprint::MAX_FIELDS; $blank++, $index++) {
            $rows .= $this->fieldNode((string) $index, []);
        }

        return '<div class="hit-fields" data-hit-group data-hit-token="__i__"'
            . ' data-hit-max="' . TypeBlueprint::MAX_FIELDS . '">'
            . '<div data-hit-list>' . $rows . '</div>'
            . '<template data-hit-template>' . $this->fieldNode('__i__', []) . '</template>'
            . '<div class="hit-add"><button class="btn btn-sm" type="button" data-hit-add>'
            . admin_icon('plus', 14) . 'Alan ekle</button>'
            . '<span class="hint">Boş bırakılan yuvalar kaydedilmez.</span></div>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function fieldNode(string $index, array $field): string
    {
        $prefix = 'alan[' . $index . ']';
        $type    = (string) ($field['type'] ?? 'text');

        if (!isset(TypeBlueprint::FIELD_TYPES[$type])) {
            $type = 'text';
        }

        $default = $field['default'] ?? '';

        $html = '<div class="hit-row" data-hit-row>'
            . '<div class="hit-head">'
            . '<span class="hit-no" data-hit-no aria-hidden="true"></span>'
            . ui_input($prefix . '[key]', (string) ($field['key'] ?? ''), [
                'id' => false, 'class' => 'input mono', 'placeholder' => 'anahtar',
                'maxlength' => '32', 'aria-label' => 'Alan anahtarı', 'data-hit-key' => true,
            ])
            . ui_input($prefix . '[label]', (string) ($field['label'] ?? ''), [
                'id' => false, 'placeholder' => 'Etiket', 'aria-label' => 'Alan etiketi',
                'data-hit-label' => true,
            ])
            . ui_select($prefix . '[type]', TypeBlueprint::FIELD_TYPES, $type, [
                'id' => false, 'aria-label' => 'Alan türü', 'data-hit-type' => true,
            ])
            . '<button class="icon-btn" type="button" data-hit-remove'
            . ' title="Alanı çıkar" aria-label="Alanı çıkar">' . admin_icon('x', 14) . '</button>'
            . '</div>'
            . '<div class="hit-grid">'
            . ui_input($prefix . '[help]', (string) ($field['help'] ?? ''), [
                'id' => false, 'placeholder' => 'Yardım metni', 'aria-label' => 'Yardım metni',
            ])
            . '<span data-hit-when="placeholder">' . ui_input($prefix . '[placeholder]',
                (string) ($field['placeholder'] ?? ''), [
                    'id' => false, 'placeholder' => 'İpucu metni', 'aria-label' => 'İpucu metni',
                ]) . '</span>'
            . ui_input($prefix . '[group]', (string) ($field['group'] ?? ''), [
                'id' => false, 'placeholder' => 'Kutu adı', 'aria-label' => 'Kutu adı',
            ])
            . ui_input($prefix . '[default]', is_scalar($default) ? (string) $default : '', [
                'id' => false, 'placeholder' => 'Varsayılan', 'aria-label' => 'Varsayılan değer',
            ])
            . '<span data-hit-when="rows">' . ui_input($prefix . '[rows]',
                (string) (int) ($field['rows'] ?? 3), [
                    'type' => 'number', 'id' => false, 'min' => '2', 'max' => '20',
                    'aria-label' => 'Satır sayısı',
                ]) . '</span>'
            . '<label class="check hit-req"><input type="checkbox" name="' . Str::attr($prefix . '[required]')
            . '" value="1"' . (!empty($field['required']) ? ' checked' : '') . '>'
            . '<span class="check-body">Zorunlu</span></label>'
            . '</div>'
            . '<div class="hit-when" data-hit-when="options">'
            . '<p class="label">Seçenekler</p>'
            . '<textarea class="input mono" name="' . Str::attr($prefix . '[options]') . '" rows="3"'
            . ' placeholder="deger:Etiket (her satıra bir tane)" aria-label="Seçenekler">'
            . esc_html(TypeBlueprint::optionLines(array_map('strval', (array) ($field['options'] ?? []))))
            . '</textarea>'
            . '<p class="hint">Seçenek girilmezse alan metin alanına döner.</p>'
            . '</div>'
            . '<div class="hit-when" data-hit-when="subfields">'
            . $this->subGroup($index, (array) ($field['fields'] ?? []))
            . '</div>'
            . '</div>';

        return $html;
    }

    /**
     * Yinelenen grubun alt alanları.
     *
     * @param list<array<string, mixed>>|array<int|string, mixed> $subFields
     */
    private function subGroup(string $index, array $subFields): string
    {
        $rows  = '';
        $count = 0;

        foreach ($subFields as $sub) {
            if (!is_array($sub)) {
                continue;
            }

            $rows .= $this->subNode($index, (string) $count, $sub);
            $count++;
        }

        if ($count < TypeBlueprint::MAX_SUBFIELDS) {
            $rows .= $this->subNode($index, (string) $count, []);
        }

        return '<div class="hit-sub" data-hit-group data-hit-token="__j__"'
            . ' data-hit-max="' . TypeBlueprint::MAX_SUBFIELDS . '">'
            . '<p class="label">Grup alanları</p>'
            . '<div data-hit-list>' . $rows . '</div>'
            . '<template data-hit-template>' . $this->subNode($index, '__j__', []) . '</template>'
            . '<div class="hit-add"><button class="btn btn-sm" type="button" data-hit-add>'
            . admin_icon('plus', 14) . 'Satır ekle</button>'
            . '<span class="hint">Alt alan girilmezse alan metin alanına döner.</span></div>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $field
     */
    private function subNode(string $index, string $sub, array $field): string
    {
        $prefix = 'alan[' . $index . '][alt][' . $sub . ']';
        $type   = (string) ($field['type'] ?? 'text');

        // Alt alanda yinelenen grup olamaz: iç içe tekrar desteklenmiyor.
        $types = TypeBlueprint::FIELD_TYPES;
        unset($types['repeater']);

        if (!isset($types[$type])) {
            $type = 'text';
        }

        return '<div class="hit-row is-sub" data-hit-row>'
            . '<div class="hit-head">'
            . '<span class="hit-no" data-hit-no aria-hidden="true"></span>'
            . ui_input($prefix . '[key]', (string) ($field['key'] ?? ''), [
                'id' => false, 'class' => 'input mono', 'placeholder' => 'anahtar',
                'maxlength' => '32', 'aria-label' => 'Alt alan anahtarı', 'data-hit-key' => true,
            ])
            . ui_input($prefix . '[label]', (string) ($field['label'] ?? ''), [
                'id' => false, 'placeholder' => 'Etiket', 'aria-label' => 'Alt alan etiketi',
                'data-hit-label' => true,
            ])
            . ui_select($prefix . '[type]', $types, $type, [
                'id' => false, 'aria-label' => 'Alt alan türü', 'data-hit-type' => true,
            ])
            . '<button class="icon-btn" type="button" data-hit-remove'
            . ' title="Satırı çıkar" aria-label="Satırı çıkar">' . admin_icon('x', 14) . '</button>'
            . '</div>'
            . '<div class="hit-when" data-hit-when="options">'
            . '<textarea class="input mono" name="' . Str::attr($prefix . '[options]') . '" rows="2"'
            . ' placeholder="deger:Etiket (her satıra bir tane)" aria-label="Alt alan seçenekleri">'
            . esc_html(TypeBlueprint::optionLines(array_map('strval', (array) ($field['options'] ?? []))))
            . '</textarea>'
            . '</div>'
            . '</div>';
    }
}
