<?php

declare(strict_types=1);

/**
 * HiAdmin — Bileşenler
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('appearance.manage');

$areas = $app->widgets()->areas();
$types = $app->widgets()->types();

if ($app->request()->isPost()) {
    admin_verify('widgets.php');

    $action = (string) ($_POST['islem'] ?? '');
    $areaId = (string) ($_POST['alan'] ?? '');

    if (!isset($areas[$areaId])) {
        admin_redirect('widgets.php', 'error', 'Bileşen alanı bulunamadı.');
    }

    $widgets = $app->widgets()->widgetsIn($areaId);

    switch ($action) {
        case 'add':
            $type = (string) ($_POST['tur'] ?? '');

            if (!$app->widgets()->hasType($type)) {
                admin_redirect('widgets.php', 'error', 'Bilinmeyen bileşen türü.');
            }

            $widgets[] = ['type' => $type, 'title' => (string) ($types[$type]['label'] ?? ''), 'settings' => []];

            $app->widgets()->saveArea($areaId, $widgets);

            admin_redirect('widgets.php#alan-' . $areaId, 'success', 'Bileşen eklendi.');

        case 'save':
            $updated = [];

            foreach ((array) ($_POST['bilesen'] ?? []) as $index => $data) {
                if (!is_array($data) || !isset($widgets[(int) $index])) {
                    continue;
                }

                $updated[] = [
                    'type'     => $widgets[(int) $index]['type'],
                    'title'    => (string) ($data['baslik'] ?? ''),
                    'settings' => (array) ($data['ayar'] ?? []),
                ];
            }

            $app->widgets()->saveArea($areaId, $updated);

            admin_redirect('widgets.php#alan-' . $areaId, 'success', 'Bileşenler kaydedildi.');

        case 'remove':
            $index = (int) ($_POST['sira'] ?? -1);

            if (isset($widgets[$index])) {
                array_splice($widgets, $index, 1);
                $app->widgets()->saveArea($areaId, $widgets);
            }

            admin_redirect('widgets.php#alan-' . $areaId, 'success', 'Bileşen kaldırıldı.');

        case 'move':
            $index  = (int) ($_POST['sira'] ?? -1);
            $target = $index + (int) ($_POST['yon'] ?? 0);

            if (isset($widgets[$index], $widgets[$target])) {
                [$widgets[$index], $widgets[$target]] = [$widgets[$target], $widgets[$index]];
                $app->widgets()->saveArea($areaId, $widgets);
            }

            admin_redirect('widgets.php#alan-' . $areaId);
    }
}

$page = [
    'title'       => 'Bileşenler',
    'slug'        => 'widgets',
    'description' => 'Bileşen alanları temada tanımlanır; içeriğini buradan düzenlersiniz.',
];

admin_head($page);
?>

<?php if ($areas === []) : ?>
    <section class="panel">
        <?= ui_empty('layout', 'Bileşen alanı yok',
            'Etkin tema hi_register_widget_area() ile alan bildirmemiş.',
            '<a class="btn btn-sm" href="appearance.php">Temalara git</a>') ?>
    </section>
<?php else : ?>
    <div class="grid" style="grid-template-columns: 280px minmax(0, 1fr); align-items: start;">
        <section class="box">
            <header class="box-head"><?= admin_icon('grid', 15) ?>Kullanılabilir bileşenler</header>
            <div class="box-body col">
                <p class="hint mt-0 mb-2">Eklemek istediğiniz alanı seçin, sonra bileşene tıklayın.</p>

                <?= ui_field('Alan',
                    ui_select('hedef_alan', array_map(
                        static fn(array $area): string => $area['name'],
                        $areas
                    ), array_key_first($areas), ['id' => 'w-area', 'form' => 'widget-add']),
                    '', 'w-area') ?>

                <?php foreach ($types as $type => $definition) : ?>
                    <button class="btn btn-block" type="submit" form="widget-add" name="tur"
                            value="<?= esc_attr($type) ?>" style="justify-content:flex-start">
                        <?= admin_icon((string) $definition['icon'], 15) ?>
                        <?= esc_html((string) $definition['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <div>
            <?php foreach ($areas as $areaId => $area) : ?>
                <?php $widgets = $app->widgets()->widgetsIn($areaId); ?>
                <section class="panel" id="alan-<?= esc_attr($areaId) ?>">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title"><?= esc_html($area['name']) ?></h2>
                            <p class="panel-sub">
                                <?= $area['description'] !== ''
                                    ? esc_html($area['description'])
                                    : '<span class="mono">' . esc_html($areaId) . '</span>' ?>
                            </p>
                        </div>
                        <div class="panel-actions">
                            <span class="pill<?= $widgets !== [] ? ' is-ok' : '' ?>">
                                <?= esc_html(Str::format('%d bileşen', count($widgets))) ?>
                            </span>
                        </div>
                    </header>

                    <?php if ($widgets !== []) : ?>
                        <form method="post" action="widgets.php">
                            <?= hi_csrf_field() ?>
                            <input type="hidden" name="alan" value="<?= esc_attr($areaId) ?>">
                            <input type="hidden" name="islem" value="save">

                            <div class="panel-body col">
                                <?php foreach ($widgets as $index => $widget) : ?>
                                    <?php $definition = $types[$widget['type']] ?? null; ?>
                                    <article class="block">
                                        <div class="block-bar">
                                            <span class="block-kind">
                                                <?= admin_icon((string) ($definition['icon'] ?? 'block'), 15) ?>
                                                <?= esc_html((string) ($definition['label'] ?? $widget['type'])) ?>
                                            </span>
                                            <span class="spacer"></span>

                                            <button class="icon-btn" type="submit" name="islem" value="move"
                                                    form="widget-row-<?= esc_attr($areaId) ?>-<?= (int) $index ?>"
                                                    title="Yukarı" aria-label="Yukarı"
                                                    <?= $index === 0 ? 'disabled' : '' ?>
                                                    onclick="document.getElementById('yon-<?= esc_attr($areaId) ?>-<?= (int) $index ?>').value='-1'">
                                                <?= admin_icon('chevron-left', 14) ?>
                                            </button>

                                            <button class="icon-btn" type="submit" name="islem" value="move"
                                                    form="widget-row-<?= esc_attr($areaId) ?>-<?= (int) $index ?>"
                                                    title="Aşağı" aria-label="Aşağı"
                                                    <?= $index === count($widgets) - 1 ? 'disabled' : '' ?>
                                                    onclick="document.getElementById('yon-<?= esc_attr($areaId) ?>-<?= (int) $index ?>').value='1'">
                                                <?= admin_icon('chevron-right', 14) ?>
                                            </button>

                                            <button class="icon-btn" type="submit" name="islem" value="remove"
                                                    form="widget-row-<?= esc_attr($areaId) ?>-<?= (int) $index ?>"
                                                    title="Kaldır" aria-label="Kaldır"
                                                <?= ui_confirm('Bu bileşen alandan kaldırılacak.') ?>>
                                                <?= admin_icon('trash', 14) ?>
                                            </button>
                                        </div>

                                        <div class="block-body">
                                            <?= ui_field('Başlık',
                                                ui_input('bilesen[' . $index . '][baslik]', (string) $widget['title'],
                                                    ['id' => 'wt-' . $areaId . '-' . $index]),
                                                'Boş bırakırsanız başlık gösterilmez.',
                                                'wt-' . $areaId . '-' . $index) ?>

                                            <?php foreach ((array) ($definition['fields'] ?? []) as $field) : ?>
                                                <?php
                                                $key   = (string) ($field['key'] ?? '');
                                                $name  = 'bilesen[' . $index . '][ayar][' . $key . ']';
                                                $value = $widget['settings'][$key] ?? ($field['default'] ?? '');
                                                $fieldId = 'wf-' . $areaId . '-' . $index . '-' . $key;

                                                $control = match ((string) ($field['type'] ?? 'text')) {
                                                    'richtext', 'textarea' =>
                                                        '<textarea class="input" id="' . esc_attr($fieldId) . '" name="'
                                                        . esc_attr($name) . '" rows="4">'
                                                        . esc_html(is_scalar($value) ? (string) $value : '') . '</textarea>',
                                                    'number' => ui_input($name, (string) $value,
                                                        ['type' => 'number', 'id' => $fieldId]),
                                                    'switch' => ui_switch($name, (bool) $value,
                                                        (string) ($field['label'] ?? '')),
                                                    'select' => ui_select($name, (array) ($field['options'] ?? []),
                                                        (string) $value, ['id' => $fieldId]),
                                                    default  => ui_input($name, is_scalar($value) ? (string) $value : '',
                                                        ['id' => $fieldId]),
                                                };

                                                echo (string) ($field['type'] ?? '') === 'switch'
                                                    ? '<div class="field">' . $control . '</div>'
                                                    : ui_field((string) ($field['label'] ?? $key), $control, '', $fieldId);
                                                ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <footer class="panel-foot">
                                <span class="spacer"></span>
                                <button class="btn btn-sm btn-primary" type="submit">Bu alanı kaydet</button>
                            </footer>
                        </form>

                        <?php /* Satır işlemleri için ayrı formlar: iç içe form olmaz. */ ?>
                        <?php foreach ($widgets as $index => $widget) : ?>
                            <form id="widget-row-<?= esc_attr($areaId) ?>-<?= (int) $index ?>"
                                  method="post" action="widgets.php" hidden>
                                <?= hi_csrf_field() ?>
                                <input type="hidden" name="alan" value="<?= esc_attr($areaId) ?>">
                                <input type="hidden" name="sira" value="<?= (int) $index ?>">
                                <input type="hidden" name="yon" id="yon-<?= esc_attr($areaId) ?>-<?= (int) $index ?>" value="0">
                            </form>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="panel-body">
                            <?= ui_empty('layout', 'Bu alan boş', 'Soldaki listeden bileşen ekleyin.') ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>

    <form id="widget-add" method="post" action="widgets.php" hidden>
        <?= hi_csrf_field() ?>
        <input type="hidden" name="islem" value="add">
        <?php /* Alan seçimi soldaki select ile yapılır; adı burada beklenir. */ ?>
        <input type="hidden" name="alan" id="widget-add-area" value="<?= esc_attr((string) array_key_first($areas)) ?>">
    </form>

    <script>
        /* Soldaki alan seçimi gizli forma yansır. */
        (function () {
            var select = document.getElementById('w-area');
            var target = document.getElementById('widget-add-area');
            if (!select || !target) return;

            select.addEventListener('change', function () { target.value = select.value; });
        })();
    </script>
<?php endif; ?>

<?php admin_foot(); ?>
