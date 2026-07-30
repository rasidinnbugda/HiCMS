<?php

declare(strict_types=1);

/**
 * HiAdmin — Menüler
 *
 * Sıralama ve düzenleme sunucu tarafında yapılır: her işlem bir form gönderimi.
 * Böylece JavaScript kapalıyken de menü yönetilebilir.
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Support\Str;

admin_require('appearance.manage');

$menus     = $app->menus()->all();
$currentId = (string) ($_GET['menu'] ?? array_key_first($menus) ?? '');

$selfUrl = static fn(string $menu = ''): string => 'menus.php' . ($menu !== '' ? '?menu=' . rawurlencode($menu) : '');

if ($app->request()->isPost()) {
    admin_verify($selfUrl($currentId));

    $action = (string) ($_POST['islem'] ?? '');
    $menuId = (string) ($_POST['menu'] ?? $currentId);
    $menu   = $app->menus()->get($menuId);
    $items  = $menu['items'] ?? [];

    switch ($action) {
        case 'create':
            $name = trim((string) ($_POST['ad'] ?? ''));

            if ($name === '') {
                admin_redirect($selfUrl($currentId), 'error', 'Menü adı zorunludur.');
            }

            $newId = $app->menus()->save('', $name, []);

            admin_redirect($selfUrl($newId), 'success', Str::format('"%s" menüsü oluşturuldu.', $name));

        case 'rename':
            if ($menu === null) {
                admin_redirect($selfUrl(), 'error', 'Menü bulunamadı.');
            }

            $app->menus()->save($menuId, trim((string) ($_POST['ad'] ?? $menu['name'])), $items);

            admin_redirect($selfUrl($menuId), 'success', 'Menü adı güncellendi.');

        case 'delete':
            $app->menus()->delete($menuId);

            admin_redirect($selfUrl(), 'success', 'Menü silindi.');

        case 'add':
            if ($menu === null) {
                admin_redirect($selfUrl(), 'error', 'Önce bir menü oluşturun.');
            }

            $kind = (string) ($_POST['tur'] ?? 'custom');

            if ($kind === 'entry') {
                $entry = $app->content()->find((int) ($_POST['hedef'] ?? 0), false);

                if ($entry === null) {
                    admin_redirect($selfUrl($menuId), 'error', 'İçerik bulunamadı.');
                }

                $type  = $app->types()->get($entry->type);
                $label = trim((string) ($_POST['etiket'] ?? '')) !== ''
                    ? trim((string) $_POST['etiket']) : $entry->title;

                $items[] = [
                    'label' => $label,
                    'url'   => ltrim($app->links()->pathForEntry($entry), '/'),
                    'type'  => 'entry',
                    'ref'   => $entry->id,
                ];
            } elseif ($kind === 'term') {
                $term = $app->terms()->find((int) ($_POST['hedef_terim'] ?? 0));

                if ($term === null) {
                    admin_redirect($selfUrl($menuId), 'error', 'Terim bulunamadı.');
                }

                $taxonomy = $app->types()->taxonomy($term->taxonomy);

                $items[] = [
                    'label' => trim((string) ($_POST['etiket'] ?? '')) !== ''
                        ? trim((string) $_POST['etiket']) : $term->name,
                    'url'   => ($taxonomy?->route ?? $term->taxonomy) . '/' . $term->slug,
                    'type'  => 'term',
                    'ref'   => $term->id,
                ];
            } else {
                $label = trim((string) ($_POST['etiket'] ?? ''));
                $url   = trim((string) ($_POST['adres'] ?? ''));

                if ($label === '') {
                    admin_redirect($selfUrl($menuId), 'error', 'Bağlantı metni zorunludur.');
                }

                $items[] = [
                    'label'   => $label,
                    'url'     => $url,
                    'type'    => 'custom',
                    'new_tab' => isset($_POST['yeni_sekme']),
                ];
            }

            $app->menus()->save($menuId, (string) $menu['name'], $items);

            admin_redirect($selfUrl($menuId), 'success', 'Öğe eklendi.');

        case 'move':
            $index = (int) ($_POST['sira'] ?? -1);
            $delta = (int) ($_POST['yon'] ?? 0);
            $target = $index + $delta;

            if ($menu !== null && isset($items[$index], $items[$target])) {
                [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
                $app->menus()->save($menuId, (string) $menu['name'], $items);
            }

            admin_redirect($selfUrl($menuId));

        case 'remove':
            $index = (int) ($_POST['sira'] ?? -1);

            if ($menu !== null && isset($items[$index])) {
                array_splice($items, $index, 1);
                $app->menus()->save($menuId, (string) $menu['name'], $items);
            }

            admin_redirect($selfUrl($menuId), 'success', 'Öğe kaldırıldı.');

        case 'locations':
            $app->menus()->assign(array_map('strval', (array) ($_POST['konum'] ?? [])));

            admin_redirect($selfUrl($currentId), 'success', 'Menü konumları kaydedildi.');
    }
}

$current     = $app->menus()->get($currentId);
$locations   = $app->menus()->locations();
$assignments = $app->menus()->assignments();

$pageOptions = [];

foreach ($app->types()->publicTypes() as $type) {
    foreach ($app->content()->options($type->name, 60) as $entryId => $title) {
        $pageOptions[(string) $entryId] = $type->singular . ': ' . $title;
    }
}

$termOptions = [];

foreach ($app->types()->taxonomies() as $taxonomy) {
    foreach ($app->terms()->forTaxonomy($taxonomy->name, false) as $term) {
        $termOptions[(string) $term->id] = $taxonomy->singular . ': ' . $term->name;
    }
}

$page = [
    'title'       => 'Menüler',
    'slug'        => 'menus',
    'description' => 'Menü oluşturun, öğeleri sıralayın ve temanın konumlarına atayın.',
];

admin_head($page);
?>

<div class="grid" style="grid-template-columns: 300px minmax(0, 1fr); align-items: start;">
    <div>
        <section class="box">
            <header class="box-head"><?= admin_icon('list', 15) ?>Menüler</header>
            <div class="box-body col">
                <?php foreach ($menus as $id => $menu) : ?>
                    <a class="btn btn-block<?= $id === $currentId ? ' btn-primary' : '' ?>"
                       href="<?= esc_url($selfUrl((string) $id)) ?>" style="justify-content:space-between">
                        <span><?= esc_html($menu['name']) ?></span>
                        <span class="small"><?= count($menu['items']) ?></span>
                    </a>
                <?php endforeach; ?>

                <?php if ($menus === []) : ?>
                    <p class="muted small mb-0">Henüz menü yok.</p>
                <?php endif; ?>
            </div>
            <footer class="box-foot">
                <form class="row" method="post" action="<?= esc_url($selfUrl($currentId)) ?>" style="width:100%">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="create">
                    <label class="sr-only" for="yeni-menu">Menü adı</label>
                    <input class="input" type="text" id="yeni-menu" name="ad" placeholder="Yeni menü adı" required>
                    <button class="btn btn-sm" type="submit"><?= admin_icon('plus', 14) ?></button>
                </form>
            </footer>
        </section>

        <section class="box">
            <header class="box-head"><?= admin_icon('layout', 15) ?>Konumlar</header>
            <form method="post" action="<?= esc_url($selfUrl($currentId)) ?>">
                <?= hi_csrf_field() ?>
                <input type="hidden" name="islem" value="locations">

                <div class="box-body">
                    <?php if ($locations !== []) : ?>
                        <?php
                        $menuChoices = ['' => '— seçilmedi —'];

                        foreach ($menus as $id => $menu) {
                            $menuChoices[(string) $id] = (string) $menu['name'];
                        }

                        foreach ($locations as $location => $label) {
                            echo ui_field(
                                $label,
                                ui_select('konum[' . $location . ']', $menuChoices,
                                    (string) ($assignments[$location] ?? ''), ['id' => 'loc-' . $location]),
                                '<span class="mono">' . esc_html($location) . '</span>',
                                'loc-' . $location
                            );
                        }
                        ?>
                    <?php else : ?>
                        <p class="muted small mb-0">Etkin tema menü konumu bildirmemiş.</p>
                    <?php endif; ?>
                </div>

                <?php if ($locations !== []) : ?>
                    <footer class="box-foot">
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit">Konumları kaydet</button>
                    </footer>
                <?php endif; ?>
            </form>
        </section>
    </div>

    <div>
        <?php if ($current !== null) : ?>
            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2 class="panel-title"><?= esc_html($current['name']) ?></h2>
                        <p class="panel-sub"><?= count($current['items']) ?> öğe</p>
                    </div>
                    <div class="panel-actions">
                        <form class="row" method="post" action="<?= esc_url($selfUrl($currentId)) ?>">
                            <?= hi_csrf_field() ?>
                            <input type="hidden" name="menu" value="<?= esc_attr($currentId) ?>">
                            <input type="hidden" name="islem" value="delete">
                            <button class="btn btn-sm btn-danger" type="submit"
                                <?= ui_confirm('Bu menü silinecek. Devam edilsin mi?') ?>>
                                <?= admin_icon('trash', 14) ?>Menüyü sil
                            </button>
                        </form>
                    </div>
                </header>

                <div class="panel-body">
                    <?php if ($current['items'] !== []) : ?>
                        <ul class="sortable">
                            <?php foreach ($current['items'] as $index => $item) : ?>
                                <li>
                                    <div class="node">
                                        <span class="node-grip" aria-hidden="true"><?= admin_icon('drag', 15) ?></span>
                                        <div class="node-body">
                                            <strong><?= esc_html((string) $item['label']) ?></strong>
                                            <small>/<?= esc_html((string) $item['url']) ?></small>
                                        </div>
                                        <span class="node-kind"><?= esc_html((string) ($item['type'] ?? 'custom')) ?></span>

                                        <form class="row" method="post" action="<?= esc_url($selfUrl($currentId)) ?>">
                                            <?= hi_csrf_field() ?>
                                            <input type="hidden" name="menu" value="<?= esc_attr($currentId) ?>">
                                            <input type="hidden" name="sira" value="<?= (int) $index ?>">

                                            <button class="icon-btn" type="submit" name="islem" value="move"
                                                    formaction="<?= esc_url($selfUrl($currentId)) ?>"
                                                    title="Yukarı" aria-label="Yukarı taşı"
                                                    <?= $index === 0 ? 'disabled' : '' ?>
                                                    onclick="this.form.yon.value='-1'"><?= admin_icon('chevron-left', 14) ?></button>

                                            <button class="icon-btn" type="submit" name="islem" value="move"
                                                    title="Aşağı" aria-label="Aşağı taşı"
                                                    <?= $index === count($current['items']) - 1 ? 'disabled' : '' ?>
                                                    onclick="this.form.yon.value='1'"><?= admin_icon('chevron-right', 14) ?></button>

                                            <button class="icon-btn" type="submit" name="islem" value="remove"
                                                    title="Kaldır" aria-label="Kaldır"><?= admin_icon('trash', 14) ?></button>

                                            <input type="hidden" name="yon" value="0">
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <?= ui_empty('list', 'Menü boş', 'Aşağıdaki formdan sayfa, kategori veya özel bağlantı ekleyin.') ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <header class="panel-head"><div><h2 class="panel-title">Öğe ekle</h2></div></header>
                <form method="post" action="<?= esc_url($selfUrl($currentId)) ?>">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="menu" value="<?= esc_attr($currentId) ?>">
                    <input type="hidden" name="islem" value="add">

                    <div class="panel-body">
                        <div class="grid grid-3">
                            <div>
                                <h3 class="h3 mb-2">İçerik</h3>
                                <?= ui_field('Sayfa veya yazı',
                                    ui_select('hedef', ['' => '— seçin —'] + $pageOptions, '', ['id' => 'm-entry']),
                                    '', 'm-entry') ?>
                                <button class="btn btn-sm btn-block" type="submit" name="tur" value="entry">
                                    İçerik ekle
                                </button>
                            </div>

                            <div>
                                <h3 class="h3 mb-2">Taksonomi</h3>
                                <?= ui_field('Kategori veya etiket',
                                    ui_select('hedef_terim', ['' => '— seçin —'] + $termOptions, '', ['id' => 'm-term']),
                                    '', 'm-term') ?>
                                <button class="btn btn-sm btn-block" type="submit" name="tur" value="term">
                                    Terim ekle
                                </button>
                            </div>

                            <div>
                                <h3 class="h3 mb-2">Özel bağlantı</h3>
                                <?= ui_field('Metin', ui_input('etiket', '', ['id' => 'm-label']), '', 'm-label') ?>
                                <?= ui_field('Adres', ui_input('adres', '', ['id' => 'm-url', 'placeholder' => 'iletisim veya https://…']), '', 'm-url') ?>
                                <label class="check mb-2">
                                    <input type="checkbox" name="yeni_sekme" value="1">
                                    <span class="check-body">Yeni sekmede aç</span>
                                </label>
                                <button class="btn btn-sm btn-block" type="submit" name="tur" value="custom">
                                    Bağlantı ekle
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </section>
        <?php else : ?>
            <section class="panel">
                <?= ui_empty('list', 'Menü seçilmedi', 'Soldaki panelden bir menü oluşturun ya da seçin.') ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php admin_foot(); ?>
