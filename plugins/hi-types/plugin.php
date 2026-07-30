<?php

declare(strict_types=1);

namespace HiTypes;

use HiCMS\Content\Field;
use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Str;

/**
 * HiTypes — panelden içerik türü oluşturucu
 *
 * Çekirdek içerik türü **kayıt API'sini** taşır ama kutudan yalnızca Yazı ve
 * Sayfa gelir. Bu eklenti o API'nin üstüne bir arayüz koyar: panelden tür ve
 * alan tanımlarsınız, tanım ayarlara JSON olarak yazılır ve her istekte
 * `hi_register_content_type()` ile kaydedilir.
 *
 * Eklenti kapatıldığında türler panelde görünmez olur ama **içerik silinmez** —
 * kayıtlar `content` tablosunda durur, eklenti yeniden açıldığında geri gelir.
 */
final class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Tanımlı türleri kaydet — tema yüklenmeden önce olması gerekir ki
        // şablon hiyerarşisi ve rotalar doğru kurulsun.
        foreach ($this->definitions() as $definition) {
            hi_register_content_type($definition, 'plugin:hi-types');
        }

        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-types',
                'label' => 'İçerik Türleri',
                'icon'  => 'grid',
                'url'   => 'plugin.php?eklenti=hi-types',
            ]);
        });

        hi_on('admin.page.hi-types', fn(): null => $this->screen());
    }

    /**
     * Kayıtlı tür tanımları.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $stored = $this->option('types', []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * @param list<array<string, mixed>> $definitions
     */
    private function save(array $definitions): void
    {
        $this->setOption('types', array_values($definitions));
    }

    private function screen(): null
    {
        $selfUrl     = 'plugin.php?eklenti=hi-types';
        $definitions = $this->definitions();
        $editIndex   = isset($_GET['duzenle']) ? (int) $_GET['duzenle'] : -1;

        if (hi()->request()->isPost()) {
            admin_verify($selfUrl);

            $action = (string) ($_POST['islem'] ?? '');

            if ($action === 'delete') {
                $index = (int) ($_POST['sira'] ?? -1);

                if (isset($definitions[$index])) {
                    $name = (string) ($definitions[$index]['name'] ?? '');
                    array_splice($definitions, $index, 1);
                    $this->save($definitions);

                    admin_redirect($selfUrl, 'success', Str::format(
                        '"%s" türü kaldırıldı. İçerikler silinmedi; türü yeniden oluşturursanız geri gelir.',
                        $name
                    ));
                }

                admin_redirect($selfUrl, 'error', 'Tür bulunamadı.');
            }

            if ($action === 'save') {
                $name = Str::slug((string) ($_POST['ad'] ?? ''), '_');

                if ($name === '' || in_array($name, ['post', 'page'], true)) {
                    admin_redirect($selfUrl, 'error', 'Geçersiz tür adı (post ve page ayrılmıştır).');
                }

                $fields = [];

                foreach ((array) ($_POST['alan'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $key = Str::slug((string) ($row['key'] ?? ''), '_');

                    if ($key === '') {
                        continue;
                    }

                    $type = (string) ($row['type'] ?? 'text');

                    $field = [
                        'key'   => $key,
                        'label' => trim((string) ($row['label'] ?? $key)),
                        'type'  => in_array($type, Field::TYPES, true) ? $type : 'text',
                        'help'  => trim((string) ($row['help'] ?? '')),
                    ];

                    // select alanı için "değer:etiket" satırları
                    if ($field['type'] === 'select') {
                        $options = [];

                        foreach (preg_split('/\r\n|\r|\n/', (string) ($row['options'] ?? '')) ?: [] as $line) {
                            $line = trim($line);

                            if ($line === '') {
                                continue;
                            }

                            [$value, $label] = array_pad(explode(':', $line, 2), 2, '');
                            $options[Str::slug($value, '_')] = trim($label) !== '' ? trim($label) : $value;
                        }

                        $field['options'] = $options;
                    }

                    $fields[] = $field;
                }

                $supports = ['blocks'];

                foreach (['excerpt', 'image', 'comments', 'order'] as $support) {
                    if (isset($_POST['destek'][$support])) {
                        $supports[] = $support;
                    }
                }

                $definition = [
                    'name'         => $name,
                    'labels'       => [
                        'singular' => trim((string) ($_POST['tekil'] ?? $name)),
                        'plural'   => trim((string) ($_POST['cogul'] ?? $name)),
                    ],
                    'icon'         => Str::slug((string) ($_POST['ikon'] ?? 'grid')),
                    'route'        => Str::slug((string) ($_POST['rota'] ?? $name)),
                    'archive'      => Str::slug((string) ($_POST['arsiv'] ?? '')),
                    'public'       => isset($_POST['acik']),
                    'hierarchical' => isset($_POST['hiyerarsik']),
                    'supports'     => $supports,
                    'taxonomies'   => array_values(array_map('strval', (array) ($_POST['taksonomi'] ?? []))),
                    'description'  => trim((string) ($_POST['aciklama'] ?? '')),
                    'menu_order'   => 60,
                    'fields'       => $fields,
                ];

                $index = (int) ($_POST['sira'] ?? -1);

                if (isset($definitions[$index])) {
                    $definitions[$index] = $definition;
                    $message = Str::format('"%s" türü güncellendi.', $definition['labels']['plural']);
                } else {
                    $definitions[] = $definition;
                    $message = Str::format('"%s" türü oluşturuldu. Sol menüde görünecek.', $definition['labels']['plural']);
                }

                $this->save($definitions);

                hi()->audit()->record(
                    action: 'types.save',
                    userId: hi()->auth()->id(),
                    actor: (string) hi()->auth()->user()?->displayName,
                    subjectType: 'content_type',
                    summary: $definition['name'],
                );

                admin_redirect($selfUrl, 'success', $message);
            }
        }

        $editing = $definitions[$editIndex] ?? null;

        $fieldTypes = [];

        foreach (Field::TYPES as $type) {
            $fieldTypes[$type] = $type;
        }

        $taxonomyOptions = [];

        foreach (hi()->types()->taxonomies() as $taxonomy) {
            $taxonomyOptions[$taxonomy->name] = $taxonomy->plural;
        }

        admin_head([
            'title'       => 'İçerik türleri',
            'slug'        => 'plugin:hi-types',
            'description' => 'Kod yazmadan yeni içerik türleri ve özel alanlar tanımlayın.',
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiTypes']],
        ]);
        ?>

        <div class="cols-main">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Tanımlı türler</h2>
                            <p class="panel-sub">Çekirdeğin Yazı ve Sayfa türleri burada listelenmez</p>
                        </div>
                    </header>

                    <?php if ($definitions !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Tür</th>
                                        <th>Rota</th>
                                        <th>Arşiv</th>
                                        <th class="num">Alan</th>
                                        <th class="num">Kayıt</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($definitions as $index => $definition) : ?>
                                        <?php $name = (string) ($definition['name'] ?? ''); ?>
                                        <tr>
                                            <td>
                                                <a class="cell-title" href="<?= esc_url($selfUrl . '&duzenle=' . $index) ?>">
                                                    <?= esc_html((string) ($definition['labels']['plural'] ?? $name)) ?>
                                                </a>
                                                <span class="cell-sub mono"><?= esc_html($name) ?></span>
                                            </td>
                                            <td class="mono small muted">/<?= esc_html((string) ($definition['route'] ?? '')) ?></td>
                                            <td class="mono small muted">
                                                <?= ($definition['archive'] ?? '') !== ''
                                                    ? '/' . esc_html((string) $definition['archive']) : '—' ?>
                                            </td>
                                            <td class="num"><?= count((array) ($definition['fields'] ?? [])) ?></td>
                                            <td class="num"><?= (int) hi()->content()->countOfType($name) ?></td>
                                            <td class="fit">
                                                <div class="row-acts">
                                                    <a class="icon-btn" href="<?= esc_url('content.php?tur=' . rawurlencode($name)) ?>"
                                                       title="İçerikleri gör" aria-label="İçerikleri gör">
                                                        <?= admin_icon('list', 15) ?>
                                                    </a>
                                                    <a class="icon-btn" href="<?= esc_url($selfUrl . '&duzenle=' . $index) ?>"
                                                       title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                                    <button class="icon-btn" type="submit" form="type-delete"
                                                            name="sira" value="<?= (int) $index ?>"
                                                            title="Kaldır" aria-label="Kaldır"
                                                        <?= ui_confirm('Tür kaldırılacak. İçerikler silinmez ama panelde görünmez olur.') ?>>
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
                        <?= ui_empty('grid', 'Henüz tür yok',
                            'Sağdaki formdan ilk içerik türünüzü oluşturun — örneğin "Portfolyo".') ?>
                    <?php endif; ?>
                </section>

                <form id="type-delete" method="post" action="<?= esc_url($selfUrl) ?>" hidden>
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="delete">
                </form>
            </div>

            <div>
                <form class="box" method="post" action="<?= esc_url($selfUrl) ?>">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="save">
                    <input type="hidden" name="sira" value="<?= (int) $editIndex ?>">

                    <header class="box-head">
                        <?= admin_icon('grid', 15) ?>
                        <?= $editing !== null ? 'Türü düzenle' : 'Yeni tür' ?>
                    </header>

                    <div class="box-body">
                        <?= ui_field('Makine adı',
                            ui_input('ad', (string) ($editing['name'] ?? ''),
                                ['id' => 't-ad', 'class' => 'input mono', 'required' => true,
                                 'placeholder' => 'portfolyo']),
                            'Yalnızca harf, sayı ve alt çizgi. Sonradan değiştirmek eski bağlantıları kırar.',
                            't-ad', true) ?>

                        <div class="field-row">
                            <?= ui_field('Tekil', ui_input('tekil', (string) ($editing['labels']['singular'] ?? ''),
                                ['id' => 't-tekil', 'placeholder' => 'Proje']), '', 't-tekil') ?>

                            <?= ui_field('Çoğul', ui_input('cogul', (string) ($editing['labels']['plural'] ?? ''),
                                ['id' => 't-cogul', 'placeholder' => 'Portfolyo']), '', 't-cogul') ?>
                        </div>

                        <div class="field-row">
                            <?= ui_field('Tek kayıt yolu',
                                '<div class="input-group"><span class="addon">/</span>'
                                . ui_input('rota', (string) ($editing['route'] ?? ''),
                                    ['id' => 't-rota', 'class' => 'input mono', 'placeholder' => 'proje'])
                                . '</div>', '', 't-rota') ?>

                            <?= ui_field('Arşiv yolu',
                                '<div class="input-group"><span class="addon">/</span>'
                                . ui_input('arsiv', (string) ($editing['archive'] ?? ''),
                                    ['id' => 't-arsiv', 'class' => 'input mono', 'placeholder' => 'portfolyo'])
                                . '</div>', 'Boş bırakılırsa arşiv sayfası oluşmaz.', 't-arsiv') ?>
                        </div>

                        <?= ui_field('İkon', ui_input('ikon', (string) ($editing['icon'] ?? 'grid'),
                            ['id' => 't-ikon', 'class' => 'input mono']),
                            'Panel menüsünde görünecek ikon adı (grid, file, image, star…).', 't-ikon') ?>

                        <?= ui_field('Açıklama',
                            '<textarea class="input" id="t-acik" name="aciklama" rows="2">'
                            . esc_html((string) ($editing['description'] ?? '')) . '</textarea>',
                            '', 't-acik') ?>

                        <hr>

                        <p class="label">Destekler</p>
                        <?php
                        $supports = (array) ($editing['supports'] ?? ['blocks', 'excerpt', 'image']);

                        foreach ([
                            'excerpt'  => ['Özet alanı', 'Liste ve arama sonuçlarında görünür'],
                            'image'    => ['Öne çıkan görsel', ''],
                            'comments' => ['Yorumlar', ''],
                            'order'    => ['Elle sıralama', 'Tarih yerine sıra numarasıyla dizilir'],
                        ] as $key => [$label, $hint]) {
                            echo '<label class="check"><input type="checkbox" name="destek[' . $key . ']" value="1"'
                                . (in_array($key, $supports, true) ? ' checked' : '') . '>'
                                . '<span class="check-body"><strong>' . esc_html($label) . '</strong>'
                                . ($hint !== '' ? '<small>' . esc_html($hint) . '</small>' : '')
                                . '</span></label>';
                        }
                        ?>

                        <div class="mt-3">
                            <p class="label">Taksonomiler</p>
                            <?php
                            $selectedTaxonomies = (array) ($editing['taxonomies'] ?? []);

                            foreach ($taxonomyOptions as $key => $label) {
                                echo '<label class="check"><input type="checkbox" name="taksonomi[]" value="'
                                    . esc_attr($key) . '"'
                                    . (in_array($key, $selectedTaxonomies, true) ? ' checked' : '') . '>'
                                    . '<span class="check-body">' . esc_html($label) . '</span></label>';
                            }
                            ?>
                        </div>

                        <div class="mt-3">
                            <?= ui_switch('acik', (bool) ($editing['public'] ?? true), 'Ön yüzde görünür',
                                'Kapatırsanız yalnızca panelde kullanılır.') ?>

                            <?= ui_switch('hiyerarsik', (bool) ($editing['hierarchical'] ?? false),
                                'Hiyerarşik', 'Kayıtlar üst-alt ilişkisi kurabilir (sayfa gibi).') ?>
                        </div>

                        <hr>

                        <p class="label">Özel alanlar</p>
                        <p class="hint mt-0 mb-2">
                            En fazla 8 alan. Alan türü panel formunu ve doğrulamayı belirler.
                        </p>

                        <?php
                        $fields = (array) ($editing['fields'] ?? []);

                        for ($i = 0; $i < 8; $i++) :
                            $field = $fields[$i] ?? [];
                            ?>
                            <div class="node" style="display:block;padding:10px 12px">
                                <div class="field-row" style="gap:8px">
                                    <?= ui_input('alan[' . $i . '][key]', (string) ($field['key'] ?? ''),
                                        ['class' => 'input mono', 'placeholder' => 'anahtar',
                                         'aria-label' => 'Alan anahtarı ' . ($i + 1)]) ?>

                                    <?= ui_input('alan[' . $i . '][label]', (string) ($field['label'] ?? ''),
                                        ['placeholder' => 'Etiket', 'aria-label' => 'Alan etiketi ' . ($i + 1)]) ?>
                                </div>

                                <div class="field-row mt-1" style="gap:8px">
                                    <?= ui_select('alan[' . $i . '][type]', $fieldTypes,
                                        (string) ($field['type'] ?? 'text'),
                                        ['aria-label' => 'Alan türü ' . ($i + 1)]) ?>

                                    <?= ui_input('alan[' . $i . '][help]', (string) ($field['help'] ?? ''),
                                        ['placeholder' => 'Yardım metni', 'aria-label' => 'Alan yardımı ' . ($i + 1)]) ?>
                                </div>

                                <?php
                                $optionLines = '';

                                foreach ((array) ($field['options'] ?? []) as $value => $label) {
                                    $optionLines .= $value . ':' . $label . "\n";
                                }
                                ?>
                                <textarea class="input mono mt-1" name="alan[<?= $i ?>][options]" rows="2"
                                          placeholder="select için: deger:Etiket (her satıra bir tane)"
                                          aria-label="Alan seçenekleri <?= $i + 1 ?>"><?= esc_html(trim($optionLines)) ?></textarea>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <footer class="box-foot">
                        <?php if ($editing !== null) : ?>
                            <a class="btn btn-sm" href="<?= esc_url($selfUrl) ?>">Vazgeç</a>
                        <?php endif; ?>
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit" data-primary-save>
                            <?= $editing !== null ? 'Güncelle' : 'Türü oluştur' ?>
                        </button>
                    </footer>
                </form>
            </div>
        </div>

        <?php
        admin_foot();

        return null;
    }
}
