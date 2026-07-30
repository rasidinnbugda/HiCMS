<?php

declare(strict_types=1);

/**
 * HiAdmin — Taksonomi yönetimi (kategori, etiket ve eklenti taksonomileri)
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Model\Term;
use HiCMS\Support\Str;

admin_require('terms.manage');

$name     = (string) ($_GET['taksonomi'] ?? 'category');
$taxonomy = $app->types()->taxonomy($name);

if ($taxonomy === null) {
    admin_redirect('terms.php?taksonomi=category', 'error', 'Bilinmeyen taksonomi.');
}

$selfUrl = 'terms.php?taksonomi=' . rawurlencode($name);
$editId  = (int) ($_GET['duzenle'] ?? 0);

/* -------------------------------------------------------------------------
 * Kaydetme / silme
 * ---------------------------------------------------------------------- */

if ($app->request()->isPost()) {
    admin_verify($selfUrl);

    $action = (string) ($_POST['islem'] ?? 'save');

    if ($action === 'delete') {
        $id   = (int) ($_POST['id'] ?? 0);
        $term = $app->terms()->find($id);

        if ($term !== null) {
            $app->terms()->delete($id);

            $app->audit()->record(
                action: 'terms.delete',
                userId: $app->auth()->id(),
                actor: (string) $app->auth()->user()?->displayName,
                subjectType: 'term:' . $name,
                subjectId: $id,
                summary: Str::format('%s silindi: %s', $taxonomy->singular, $term->name),
                ip: $app->request()->ip(),
            );

            admin_redirect($selfUrl, 'success', Str::format('"%s" silindi.', $term->name));
        }

        admin_redirect($selfUrl, 'error', 'Terim bulunamadı.');
    }

    $id   = (int) ($_POST['id'] ?? 0);
    $term = $id > 0 ? $app->terms()->find($id) : new Term();

    if ($term === null) {
        admin_redirect($selfUrl, 'error', 'Terim bulunamadı.');
    }

    $term->taxonomy    = $name;
    $term->name        = trim((string) ($_POST['ad'] ?? ''));
    $term->slug        = trim((string) ($_POST['kisa_ad'] ?? ''));
    $term->description = trim((string) ($_POST['aciklama'] ?? ''));
    $term->color       = $taxonomy->hasColor ? trim((string) ($_POST['renk'] ?? '')) : '';
    $term->parentId    = $taxonomy->hierarchical ? (int) ($_POST['ebeveyn'] ?? 0) : 0;

    if ($term->name === '') {
        admin_redirect($selfUrl, 'error', 'Ad zorunludur.');
    }

    if ($id > 0) {
        $app->terms()->update($term);
        $message = Str::format('"%s" güncellendi.', $term->name);
    } else {
        $app->terms()->create($term);
        $message = Str::format('"%s" eklendi.', $term->name);
    }

    $app->audit()->record(
        action: $id > 0 ? 'terms.update' : 'terms.create',
        userId: $app->auth()->id(),
        actor: (string) $app->auth()->user()?->displayName,
        subjectType: 'term:' . $name,
        subjectId: $term->id,
        summary: $taxonomy->singular . ': ' . $term->name,
        ip: $app->request()->ip(),
    );

    admin_redirect($selfUrl, 'success', $message);
}

/* -------------------------------------------------------------------------
 * Görünüm
 * ---------------------------------------------------------------------- */

$terms   = $app->terms()->forTaxonomy($name);
$editing = $editId > 0 ? $app->terms()->find($editId) : null;

$parentOptions = ['0' => '— Üst yok —'];

foreach ($terms as $option) {
    if ($editing === null || $option->id !== $editing->id) {
        $parentOptions[(string) $option->id] = $option->name;
    }
}

$page = [
    'title'       => $taxonomy->plural,
    'slug'        => 'terms:' . $name,
    'description' => $taxonomy->single
        ? 'Her içerik yalnızca bir ' . mb_strtolower($taxonomy->singular) . ' alır.'
        : 'Bir içerik birden çok ' . mb_strtolower($taxonomy->singular) . ' taşıyabilir.',
];

admin_head($page);
?>

<div class="grid" style="grid-template-columns: 320px minmax(0, 1fr); align-items: start;">
    <?php /* Ekleme / düzenleme formu */ ?>
    <form class="panel" method="post" action="<?= esc_url($selfUrl) ?>">
        <?= hi_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing?->id ?? 0) ?>">

        <header class="panel-head">
            <div>
                <h2 class="panel-title">
                    <?= $editing !== null ? esc_html($taxonomy->singular) . ' düzenle' : 'Yeni ' . esc_html(mb_strtolower($taxonomy->singular)) ?>
                </h2>
            </div>
        </header>

        <div class="panel-body">
            <?= ui_field('Ad', ui_input('ad', $editing?->name ?? '', ['id' => 'ad', 'required' => true]), '', 'ad', true) ?>

            <?= ui_field(
                'Kısa ad',
                ui_input('kisa_ad', $editing?->slug ?? '', ['id' => 'kisa_ad', 'class' => 'input mono']),
                'Boş bırakırsanız addan üretilir.',
                'kisa_ad'
            ) ?>

            <?php if ($taxonomy->hierarchical) : ?>
                <?= ui_field('Üst terim',
                    ui_select('ebeveyn', $parentOptions, (string) ($editing?->parentId ?? 0), ['id' => 'ebeveyn']),
                    '', 'ebeveyn') ?>
            <?php endif; ?>

            <?php if ($taxonomy->hasColor) : ?>
                <div class="field">
                    <label class="label" for="renk">Renk</label>
                    <div class="color-field">
                        <input type="color" id="renk" name="renk"
                               value="<?= esc_attr($editing?->color !== '' ? (string) $editing?->color : '#95389e') ?>">
                        <input class="input mono" type="text" readonly
                               value="<?= esc_attr($editing?->color !== '' ? (string) $editing?->color : '#95389e') ?>">
                    </div>
                    <p class="hint">Temada etiket ve nokta işaretlerinde kullanılır.</p>
                </div>
            <?php endif; ?>

            <?= ui_field(
                'Açıklama',
                '<textarea class="input" id="aciklama" name="aciklama" rows="3">'
                . esc_html($editing?->description ?? '') . '</textarea>',
                'Arşiv sayfasının üstünde gösterilir.',
                'aciklama'
            ) ?>
        </div>

        <footer class="panel-foot">
            <?php if ($editing !== null) : ?>
                <a class="btn btn-sm" href="<?= esc_url($selfUrl) ?>">Vazgeç</a>
            <?php endif; ?>
            <span class="spacer"></span>
            <button class="btn btn-sm btn-primary" type="submit">
                <?= $editing !== null ? 'Güncelle' : 'Ekle' ?>
            </button>
        </footer>
    </form>

    <?php /* Liste */ ?>
    <section class="panel">
        <header class="panel-head">
            <div>
                <h2 class="panel-title">Mevcut <?= esc_html(mb_strtolower($taxonomy->plural)) ?></h2>
                <p class="panel-sub">Sayılar yalnızca yayında olan içerikleri kapsar</p>
            </div>
        </header>

        <?php if ($terms !== []) : ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Ad</th>
                            <th>Kısa ad</th>
                            <th>Açıklama</th>
                            <th class="num">İçerik</th>
                            <th class="fit"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $term) : ?>
                            <tr>
                                <td>
                                    <a class="cell-title" href="<?= esc_url($selfUrl . '&duzenle=' . $term->id) ?>">
                                        <?php if ($taxonomy->hasColor && $term->color !== '') : ?>
                                            <span class="dot" style="--term-color: <?= esc_attr($term->color) ?>"></span>
                                        <?php endif; ?>
                                        <?= esc_html($term->name) ?>
                                    </a>
                                    <?php if ($term->parentId > 0) : ?>
                                        <span class="cell-sub">alt terim</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono small muted"><?= esc_html($term->slug) ?></td>
                                <td class="small dim" style="max-width:360px">
                                    <?= esc_html(Str::limit($term->description, 90)) ?>
                                </td>
                                <td class="num"><?= (int) $term->count ?></td>
                                <td class="fit">
                                    <div class="row-acts">
                                        <a class="icon-btn" href="<?= esc_url($selfUrl . '&duzenle=' . $term->id) ?>"
                                           title="Düzenle" aria-label="Düzenle"><?= admin_icon('edit', 15) ?></a>
                                        <a class="icon-btn" href="<?= esc_url($app->links()->forTerm($term)) ?>"
                                           target="_blank" rel="noopener" title="Arşivi görüntüle"
                                           aria-label="Arşivi görüntüle"><?= admin_icon('eye', 15) ?></a>
                                        <form method="post" action="<?= esc_url($selfUrl) ?>" style="display:inline">
                                            <?= hi_csrf_field() ?>
                                            <input type="hidden" name="islem" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $term->id ?>">
                                            <button class="icon-btn" type="submit" title="Sil" aria-label="Sil"
                                                <?= ui_confirm(Str::format('"%s" silinecek. İçerikler silinmez, yalnızca bağı kopar. Devam edilsin mi?', $term->name)) ?>>
                                                <?= admin_icon('trash', 15) ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <?= ui_empty(
                $taxonomy->name === 'tag' ? 'tag' : 'folder',
                'Henüz ' . mb_strtolower($taxonomy->singular) . ' yok',
                'Soldaki formdan ilkini ekleyin.'
            ) ?>
        <?php endif; ?>
    </section>
</div>

<?php admin_foot(); ?>
