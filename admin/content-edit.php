<?php

declare(strict_types=1);

/**
 * HiAdmin — İçerik düzenleyici (blok editörü)
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

admin_require('content.read');

$id    = (int) ($_GET['id'] ?? 0);
$entry = $id > 0 ? $app->content()->find($id) : null;

if ($id > 0 && $entry === null) {
    admin_redirect('content.php', 'error', 'İçerik bulunamadı.');
}

$typeName = $entry !== null ? $entry->type : (string) ($_GET['tur'] ?? 'post');
$type     = $app->types()->get($typeName);

if ($type === null) {
    admin_redirect('content.php', 'error', 'Bilinmeyen içerik türü.');
}

$isNew = $entry === null;

if ($isNew) {
    admin_require('content.create');

    $entry           = new Entry();
    $entry->type     = $typeName;
    $entry->authorId = $app->auth()->id();
    $entry->status   = 'draft';
} elseif (!$app->auth()->canEdit($entry->authorId)) {
    admin_deny('Bu içeriği yalnızca yazarı veya bir editör düzenleyebilir.');
}

$taxonomies = $app->types()->taxonomiesFor($type);

/* -------------------------------------------------------------------------
 * Kaydetme
 * ---------------------------------------------------------------------- */

if ($app->request()->isPost()) {
    admin_verify($isNew ? $type->editUrl() : $type->editUrl($entry->id));

    $requested = (string) ($_POST['durum'] ?? 'draft');

    if ($requested === 'published' && !$app->auth()->can('content.publish')) {
        $requested = 'pending';
        admin_flash('warning', 'Yayınlama yetkiniz olmadığı için içerik incelemeye gönderildi.');
    }

    $entry->title   = trim((string) ($_POST['baslik'] ?? ''));
    $entry->slug    = trim((string) ($_POST['kisa_ad'] ?? ''));
    $entry->excerpt = trim((string) ($_POST['ozet'] ?? ''));
    $entry->status  = in_array($requested, $type->statuses, true) ? $requested : 'draft';

    $blocks        = json_decode((string) ($_POST['bloklar'] ?? '[]'), true);
    $entry->blocks = is_array($blocks) ? $blocks : [];

    $entry->featured     = isset($_POST['one_cikan']);
    $entry->commentsOpen = isset($_POST['yorumlar']);
    $entry->mediaId      = (int) ($_POST['gorsel'] ?? 0);
    $entry->template     = trim((string) ($_POST['sablon'] ?? ''));
    $entry->parentId     = (int) ($_POST['ebeveyn'] ?? 0);
    $entry->position     = (int) ($_POST['sira'] ?? 0);

    if ($app->auth()->can('content.edit_others')) {
        $entry->authorId = (int) ($_POST['yazar'] ?? $entry->authorId);
    }

    $when = trim((string) ($_POST['tarih'] ?? ''));

    if ($when !== '') {
        $entry->publishedAt = Dates::stamp(str_replace('T', ' ', $when));
    }

    // Taksonomi seçimleri
    $termSelection = [];

    foreach ($taxonomies as $taxonomy) {
        $selected = array_map('intval', (array) ($_POST['terim'][$taxonomy->name] ?? []));

        if ($taxonomy->single) {
            $selected = array_slice(array_filter($selected), 0, 1);
        }

        // Serbest giriş (etiket alanı): virgülle ayrılmış adlar
        $free = trim((string) ($_POST['yeni_terim'][$taxonomy->name] ?? ''));

        if ($free !== '') {
            foreach (explode(',', $free) as $name) {
                $created = $app->terms()->findOrCreateByName($taxonomy->name, trim($name));

                if ($created !== null) {
                    $selected[] = $created->id;
                }
            }
        }

        $termSelection[$taxonomy->name] = array_values(array_unique(array_filter($selected)));
    }

    // Özel alanlar
    $meta = [];

    foreach ($type->fields as $field) {
        $meta[$field->key] = $field->sanitize($_POST['alan'][$field->key] ?? null);
    }

    $result = $app->content()->save($entry, $meta, $termSelection);

    if (!$result['ok']) {
        admin_flash('error', $result['error']);
    } else {
        $app->audit()->record(
            action: $isNew ? 'content.create' : 'content.update',
            userId: $app->auth()->id(),
            actor: (string) $app->auth()->user()?->displayName,
            subjectType: $typeName,
            subjectId: $result['id'],
            summary: Str::format('%s: %s', $type->singular, $entry->title),
            ip: $app->request()->ip(),
        );

        admin_redirect(
            $type->editUrl($result['id']),
            'success',
            $isNew ? $type->singular . ' oluşturuldu.' : 'Değişiklikler kaydedildi.'
        );
    }
}

/* -------------------------------------------------------------------------
 * Editör verisi
 * ---------------------------------------------------------------------- */

$blockTypes = [];

foreach ($app->blocks()->all() as $blockType => $definition) {
    $blockTypes[$blockType] = [
        'label'  => $definition['label'],
        'icon'   => $definition['icon'],
        'group'  => $definition['group'],
        'fields' => $definition['fields'],
    ];
}

// Editörde kullanılacak medya kayıtları: seçili olanlar + son yüklenenler.
$mediaMap = [];

foreach ($app->mediaRepo()->recent(60, 'image/') as $item) {
    $mediaMap[$item->id] = [
        'id'    => $item->id,
        'url'   => $app->urls()->uploads($item->path),
        'title' => $item->title !== '' ? $item->title : $item->filename,
        'alt'   => $item->alt,
    ];
}

if ($entry->mediaId > 0 && !isset($mediaMap[$entry->mediaId]) && $entry->image !== null) {
    $mediaMap[$entry->mediaId] = [
        'id'    => $entry->image->id,
        'url'   => $app->urls()->uploads($entry->image->path),
        'title' => $entry->image->title,
        'alt'   => $entry->image->alt,
    ];
}

// Editörün ihtiyaç duyduğu ikonlar
$iconNames = ['drag', 'plus', 'x', 'trash', 'grid', 'chevron-down', 'chevron-left', 'chevron-right', 'image', 'block'];

foreach ($blockTypes as $definition) {
    $iconNames[] = (string) $definition['icon'];
}

$iconMap = [];

foreach (array_unique($iconNames) as $name) {
    $iconMap[$name] = admin_icon($name, 15);
}

$page = [
    'title'      => $isNew ? 'Yeni ' . mb_strtolower($type->singular) : $type->singular . ' düzenle',
    'slug'       => 'content:' . $typeName,
    'breadcrumb' => [
        ['label' => $type->plural, 'url' => $type->adminUrl()],
        ['label' => $isNew ? 'Yeni' : Str::limit($entry->title, 40)],
    ],
    'wide'    => true,
    'actions' => ($isNew ? '' :
            '<a class="btn" href="' . esc_url($app->links()->forEntry($entry)) . '" target="_blank" rel="noopener">'
            . admin_icon('eye', 15) . 'Önizle</a>')
        . '<button class="btn btn-primary" type="submit" form="entry-form" data-primary-save>'
        . admin_icon('check', 15)
        . ($entry->status === 'published' ? 'Güncelle' : 'Kaydet') . '</button>',
];

admin_head($page);
?>

<form id="entry-form" method="post" action="<?= esc_url($isNew ? $type->editUrl() : $type->editUrl($entry->id)) ?>" data-guard>
    <?= hi_csrf_field() ?>

    <div class="cols-editor">
        <div>
            <label class="sr-only" for="baslik">Başlık</label>
            <input class="editor-title" type="text" id="baslik" name="baslik" data-slug-from
                   value="<?= esc_attr($entry->title) ?>" placeholder="Başlık yazın" autocomplete="off"
                   <?= $isNew ? 'autofocus' : '' ?>>

            <div class="slug-row">
                <?= admin_icon('link', 14) ?>
                <span class="mono"><?= esc_html(rtrim($app->urls()->to(), '/') . '/' . ($type->route !== '' ? $type->route . '/' : '')) ?></span>
                <label class="sr-only" for="kisa_ad">Kısa ad</label>
                <input class="slug-input" type="text" id="kisa_ad" name="kisa_ad" data-slug-to
                       value="<?= esc_attr($entry->slug) ?>" placeholder="kisa-ad">
            </div>

            <?php if ($type->hasBlocks) : ?>
                <div id="block-editor"></div>
                <input type="hidden" name="bloklar" id="blocks-json" value="">

                <div class="editor-status">
                    <span><strong data-count-words>0</strong> kelime</span>
                    <span aria-hidden="true">·</span>
                    <span><strong data-count-minutes>1</strong> dk okuma</span>
                    <span class="spacer"></span>
                    <span>
                        <?= $isNew ? 'Henüz kaydedilmedi' : 'Son kayıt: ' . esc_html(Dates::ago($entry->updatedAt)) ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($type->hasExcerpt) : ?>
                <section class="panel mt-3">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">Özet</h2>
                            <p class="panel-sub">Liste ve arama sonuçlarında görünen kısa açıklama</p>
                        </div>
                    </header>
                    <div class="panel-body">
                        <label class="sr-only" for="ozet">Özet</label>
                        <textarea class="input" id="ozet" name="ozet" rows="3" maxlength="400"
                                  placeholder="Boş bırakırsanız içerikten otomatik üretilir."><?= esc_html($entry->excerpt) ?></textarea>
                        <p class="hint">Arama motorları için 155 karakter idealdir.</p>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($type->hasFields()) : ?>
                <?php foreach ($type->fieldGroups() as $groupName => $fields) : ?>
                    <section class="panel">
                        <header class="panel-head">
                            <div><h2 class="panel-title"><?= esc_html($groupName) ?></h2></div>
                        </header>
                        <div class="panel-body">
                            <?php foreach ($fields as $field) : ?>
                                <?php
                                $value = $entry->meta($field->key, $field->default);
                                $name  = 'alan[' . $field->key . ']';
                                $inputId = 'alan-' . $field->key;

                                $control = match ($field->type) {
                                    'textarea', 'richtext' => '<textarea class="input" id="' . esc_attr($inputId)
                                        . '" name="' . esc_attr($name) . '" rows="' . $field->rows . '">'
                                        . esc_html(is_scalar($value) ? (string) $value : '') . '</textarea>',
                                    'code' => '<textarea class="input mono" id="' . esc_attr($inputId)
                                        . '" name="' . esc_attr($name) . '" rows="' . $field->rows . '">'
                                        . esc_html(is_scalar($value) ? (string) $value : '') . '</textarea>',
                                    'lines' => '<textarea class="input" id="' . esc_attr($inputId)
                                        . '" name="' . esc_attr($name) . '" rows="' . $field->rows . '">'
                                        . esc_html(is_array($value) ? implode("\n", $value) : (string) $value)
                                        . '</textarea>',
                                    'select' => ui_select($name, $field->options, (string) $value, ['id' => $inputId]),
                                    'switch' => ui_switch($name, (bool) $value, $field->label, $field->help),
                                    'number' => ui_input($name, (string) $value, ['type' => 'number', 'id' => $inputId]),
                                    'date'   => ui_input($name, (string) $value, ['type' => 'date', 'id' => $inputId]),
                                    'datetime' => ui_input($name, (string) $value, ['type' => 'datetime-local', 'id' => $inputId]),
                                    'color'  => '<div class="color-field"><input type="color" name="' . esc_attr($name)
                                        . '" id="' . esc_attr($inputId) . '" value="'
                                        . esc_attr((string) ($value !== '' ? $value : '#95389e')) . '"></div>',
                                    'media'  => ui_input($name, (string) $value, [
                                        'type' => 'number', 'id' => $inputId, 'placeholder' => 'Medya kimliği',
                                    ]),
                                    default  => ui_input($name, is_scalar($value) ? (string) $value : '', [
                                        'id' => $inputId, 'placeholder' => $field->placeholder,
                                    ]),
                                };

                                echo $field->type === 'switch'
                                    ? '<div class="field">' . $control . '</div>'
                                    : ui_field($field->label, $control, esc_html($field->help), $inputId, $field->required);
                                ?>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php /* ------------------------------------------------------------
             Yan panel
        ------------------------------------------------------------ */ ?>
        <div>
            <section class="box">
                <header class="box-head"><?= admin_icon('upload', 15) ?>Yayın</header>
                <div class="box-body">
                    <?php
                    $statusLabels = [
                        'draft'     => 'Taslak',
                        'pending'   => 'İnceleme bekliyor',
                        'published' => 'Yayında',
                        'private'   => 'Özel',
                    ];

                    $available = array_intersect_key($statusLabels, array_flip($type->statuses));

                    if (!$app->auth()->can('content.publish')) {
                        unset($available['published']);
                    }

                    echo ui_field('Durum', ui_select('durum', $available, $entry->status, ['id' => 'durum']), '', 'durum');

                    echo ui_field(
                        'Yayın tarihi',
                        ui_input('tarih', Dates::forInput($entry->publishedAt), [
                            'type' => 'datetime-local', 'id' => 'tarih',
                        ]),
                        'İleri bir tarih girerseniz içerik o tarihte görünür olur.',
                        'tarih'
                    );

                    if ($app->auth()->can('content.edit_others')) {
                        $authors = [];

                        foreach ($app->users()->all() as $candidate) {
                            $authors[(string) $candidate->id] = $candidate->displayName
                                . ' — ' . $app->roles()->label($candidate->role);
                        }

                        echo ui_field('Yazar', ui_select('yazar', $authors, (string) $entry->authorId, ['id' => 'yazar']), '', 'yazar');
                    }

                    if ($type->name === 'post') {
                        echo ui_switch('one_cikan', $entry->featured, 'Öne çıkar',
                            'Ana sayfada manşet alanında gösterilir.');
                    }

                    if ($type->hierarchical) {
                        $parents = ['0' => '— Üst yok —'];

                        foreach ($app->content()->parentOptions($type->name, $entry->id) as $parentId => $label) {
                            $parents[(string) $parentId] = $label;
                        }

                        echo '<div class="mt-3">' . ui_field('Üst sayfa',
                            ui_select('ebeveyn', $parents, (string) $entry->parentId, ['id' => 'ebeveyn']), '', 'ebeveyn')
                            . '</div>';
                    }
                    ?>
                </div>
                <footer class="box-foot">
                    <?php if (!$isNew && $app->auth()->can('content.delete')) : ?>
                        <a class="btn btn-sm btn-danger" href="content-delete.php?id=<?= (int) $entry->id ?>&amp;_t=<?= esc_attr(hi()->csrf()->token()) ?>"
                           <?= ui_confirm('Bu içerik kalıcı olarak silinecek. Devam edilsin mi?') ?>>
                            <?= admin_icon('trash', 14) ?>Sil
                        </a>
                    <?php endif; ?>
                    <span class="spacer"></span>
                    <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                </footer>
            </section>

            <?php if ($type->hasImage) : ?>
                <section class="box">
                    <header class="box-head"><?= admin_icon('image', 15) ?>Öne çıkan görsel</header>
                    <div class="box-body">
                        <button class="thumb-pick" type="button" data-modal="#media-modal" id="thumb-pick">
                            <?php if ($entry->image !== null) : ?>
                                <img src="<?= esc_url($app->urls()->uploads($entry->image->path)) ?>"
                                     alt="<?= esc_attr($entry->image->alt) ?>">
                            <?php else : ?>
                                <?= admin_icon('image', 20) ?>
                                <span>Görsel seç</span>
                            <?php endif; ?>
                        </button>
                        <input type="hidden" name="gorsel" id="gorsel" value="<?= (int) $entry->mediaId ?>">
                        <p class="hint">Önerilen: 1600×900, 300 KB altı.</p>
                    </div>
                </section>
            <?php endif; ?>

            <?php foreach ($taxonomies as $taxonomy) : ?>
                <?php
                $terms    = $app->terms()->forTaxonomy($taxonomy->name, false);
                $selected = array_map(static fn(object $t): int => $t->id, $entry->termsIn($taxonomy->name));
                ?>
                <section class="box">
                    <header class="box-head">
                        <?= admin_icon($taxonomy->name === 'tag' ? 'tag' : 'folder', 15) ?>
                        <?= esc_html($taxonomy->plural) ?>
                    </header>
                    <div class="box-body">
                        <?php if ($terms !== []) : ?>
                            <div style="max-height:220px;overflow-y:auto">
                                <?php foreach ($terms as $term) : ?>
                                    <label class="check">
                                        <input type="<?= $taxonomy->single ? 'radio' : 'checkbox' ?>"
                                               name="terim[<?= esc_attr($taxonomy->name) ?>][]"
                                               value="<?= (int) $term->id ?>"
                                               <?= in_array($term->id, $selected, true) ? 'checked' : '' ?>>
                                        <span class="check-body">
                                            <?php if ($taxonomy->hasColor && $term->color !== '') : ?>
                                                <span class="dot" style="--term-color: <?= esc_attr($term->color) ?>"></span>
                                            <?php endif; ?>
                                            <?= esc_html($term->name) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p class="muted small">Henüz <?= esc_html(mb_strtolower($taxonomy->plural)) ?> yok.</p>
                        <?php endif; ?>

                        <?php if (!$taxonomy->single) : ?>
                            <div class="field mt-2 mb-0">
                                <label class="label" for="yeni-<?= esc_attr($taxonomy->name) ?>">Yeni ekle</label>
                                <input class="input" type="text" id="yeni-<?= esc_attr($taxonomy->name) ?>"
                                       name="yeni_terim[<?= esc_attr($taxonomy->name) ?>]"
                                       placeholder="Virgülle ayırın">
                            </div>
                        <?php elseif ($app->auth()->can('terms.manage')) : ?>
                            <a class="btn btn-sm btn-ghost mt-2" href="<?= esc_url($taxonomy->adminUrl()) ?>">
                                <?= admin_icon('plus', 14) ?>Yeni <?= esc_html(mb_strtolower($taxonomy->singular)) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($type->hasComments) : ?>
                <section class="box">
                    <header class="box-head"><?= admin_icon('message', 15) ?>Tartışma</header>
                    <div class="box-body">
                        <?= ui_switch('yorumlar', $entry->commentsOpen, 'Yorumlara izin ver',
                            'Kapatırsanız bu içerikte yorum formu görünmez.') ?>
                        <?php if (!$isNew && $entry->commentCount > 0) : ?>
                            <p class="hint">
                                <a href="comments.php?icerik=<?= (int) $entry->id ?>">
                                    <?= esc_html(Str::format('%d yorumu görüntüle', $entry->commentCount)) ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!$isNew) : ?>
                <section class="box">
                    <header class="box-head"><?= admin_icon('info', 15) ?>Bilgi</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Oluşturuldu</span><span class="v"><?= esc_html(Dates::format($entry->createdAt, 'j M Y')) ?></span></li>
                            <li><span class="k">Güncellendi</span><span class="v"><?= esc_html(Dates::ago($entry->updatedAt)) ?></span></li>
                            <li><span class="k">Okunma</span><span class="v"><?= esc_html(Str::number($entry->views)) ?></span></li>
                            <li><span class="k">Blok</span><span class="v"><?= count($entry->blocks) ?></span></li>
                        </ul>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php /* Medya seçme penceresi */ ?>
<div class="modal" id="media-modal" hidden role="dialog" aria-modal="true" aria-labelledby="media-modal-title">
    <div class="modal-box is-wide">
        <header class="modal-head">
            <h2 id="media-modal-title">Görsel seç</h2>
            <button class="icon-btn" type="button" data-close aria-label="Kapat"><?= admin_icon('x', 16) ?></button>
        </header>
        <div class="modal-body">
            <?php if ($mediaMap !== []) : ?>
                <div class="media-grid">
                    <?php foreach ($mediaMap as $item) : ?>
                        <button class="media-item" type="button" data-media-id="<?= (int) $item['id'] ?>">
                            <span class="media-thumb">
                                <img src="<?= esc_url($item['url']) ?>" alt="<?= esc_attr($item['alt']) ?>" loading="lazy">
                            </span>
                            <span class="media-meta">
                                <strong><?= esc_html($item['title']) ?></strong>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <?= ui_empty('image', 'Medya kitaplığı boş', 'Önce medya bölümünden görsel yükleyin.',
                    '<a class="btn btn-sm btn-primary" href="media.php">Medyaya git</a>') ?>
            <?php endif; ?>
        </div>
        <footer class="modal-foot">
            <a class="btn btn-sm btn-ghost" href="media.php"><?= admin_icon('upload', 14) ?>Yeni yükle</a>
            <span class="spacer"></span>
            <button class="btn btn-sm" type="button" data-close>Vazgeç</button>
        </footer>
    </div>
</div>

<script>
    window.HI_EDITOR = {
        types:  <?= esc_json($blockTypes) ?>,
        blocks: <?= esc_json($entry->blocks) ?>,
        media:  <?= esc_json($mediaMap) ?>,
        icons:  <?= esc_json($iconMap) ?>
    };
</script>
<script src="assets/js/editor.js?v=<?= esc_attr(HiCMS\Kernel::VERSION) ?>"></script>
<script>
    /* Öne çıkan görsel seçimi: medya penceresini editörden bağımsız kullanır. */
    (function () {
        var pick = document.getElementById('thumb-pick');
        var field = document.getElementById('gorsel');
        var modal = document.getElementById('media-modal');
        if (!pick || !field || !modal) return;

        var media = window.HI_EDITOR.media || {};

        pick.addEventListener('click', function () {
            function choose(event) {
                var item = event.target.closest('[data-media-id]');
                if (!item) return;

                var id = item.getAttribute('data-media-id');
                field.value = id;

                var record = media[id];
                pick.innerHTML = record
                    ? '<img src="' + record.url + '" alt="">'
                    : '<span>Seçildi (#' + id + ')</span>';

                modal.hidden = true;
                document.body.style.overflow = '';
                modal.removeEventListener('click', choose);
            }

            modal.addEventListener('click', choose);
        });
    })();
</script>

<?php admin_foot(); ?>
