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

    /*
     * VERİ KAYBI KORUMASI.
     *
     * Blok ağacı yalnızca editor.js'in doldurduğu gizli `bloklar` alanından
     * gelir. 0.2.0'da bu satır `$_POST['bloklar'] ?? '[]'` yazıyordu, yani:
     *
     *   - Alan HİÇ gelmezse    → '[]' → blocks = []  → İÇERİK SİLİNİR
     *   - Alan boş gelirse     → ''   → null → []    → İÇERİK SİLİNİR
     *   - JSON bozuk gelirse   → null → []           → İÇERİK SİLİNİR
     *
     * Üçü de gerçekleşebilir: JS yüklenmeden form gönderilirse, bir eklenti
     * hata verip editörü kurmazsa, tarayıcı alanı kırparsa ya da istek yarıda
     * kesilirse. Sonuç sessiz ve geri dönüşsüz bir içerik silme.
     *
     * Artık üç durum ayrı: yalnızca GEÇERLİ bir dizi geldiğinde bloklar
     * değiştirilir. Aksi hâlde mevcut ağaç KORUNUR ve kullanıcı uyarılır —
     * çünkü "boş içerik kaydetmek istedim" ile "editör çalışmadı" arasındaki
     * farkı sunucu bilemez ve varsayılan davranış veriyi korumak olmalı.
     *
     * İçeriği gerçekten boşaltmak isteyen kullanıcı bunu editörde blokları
     * silerek yapar; o durumda alan '[]' olarak gelir ve geçerli bir dizidir.
     */
    if (array_key_exists('bloklar', $_POST)) {
        $raw     = (string) $_POST['bloklar'];
        $decoded = $raw === '' ? null : json_decode($raw, true);

        if (is_array($decoded)) {
            $entry->blocks = $decoded;
        } elseif (!$isNew) {
            admin_flash(
                'warning',
                'İçerik blokları okunamadı; mevcut içerik korundu. Diğer alanlardaki '
                . 'değişiklikler kaydedildi. Sayfayı yenileyip yeniden deneyin.'
            );
        }
    } elseif (!$isNew) {
        admin_flash(
            'warning',
            'İçerik blokları gönderilmedi; mevcut içerik korundu. Editör yüklenmediyse '
            . 'sayfayı yenileyin.'
        );
    }

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

    /*
     * ÖZEL ALANLAR — GÖNDERİLMEYEN ALAN EZİLMEZ
     *
     * 0.2.0 her alan için koşulsuz `$_POST['alan'][key] ?? null` yazıyordu.
     * Formda karşılığı olmayan bir alan (o sürümde repeater ve media-list) ya da
     * bir eklentinin sonradan bildirdiği ama bu formda basılmayan bir alan,
     * her kaydetmede boş değere düşüyordu — kullanıcı o alana hiç dokunmasa bile.
     *
     * Artık yalnızca POST'ta GERÇEKTEN bulunan alanlar yazılıyor. `switch`
     * istisna: işaretsiz onay kutusu POST'a hiç girmez ve bu "kapalı" demektir,
     * dolayısıyla yokluğu bilgi taşır.
     */
    $meta   = [];
    $posted = (array) ($_POST['alan'] ?? []);

    foreach ($type->fields as $field) {
        $present = array_key_exists($field->key, $posted);

        if (!$present && $field->type !== 'switch') {
            continue;
        }

        $clean = $field->sanitize($present ? $posted[$field->key] : null);

        /*
         * Yinelenen grupta bozuk JSON: temizleyici boş dizi döndürür ama
         * kullanıcının yazım hatası veri kaybına çevrilmemeli. Gelen metin boş
         * DEĞİLKEN sonuç boş çıktıysa yazma atlanır ve kullanıcı uyarılır.
         */
        if (
            $field->type === 'repeater'
            && $clean === []
            && is_string($posted[$field->key] ?? null)
            && trim((string) $posted[$field->key]) !== ''
        ) {
            admin_flash(
                'warning',
                Str::format('"%s" alanı okunamadı (geçersiz JSON); önceki değeri korundu.', $field->label)
            );

            continue;
        }

        $meta[$field->key] = $clean;
    }

    /*
     * ÇAKIŞMA DENETİMİ
     *
     * Formda gizli `beklenen_surum` alanı var ve mevcut bir içerik için bu alanın
     * BULUNMAMASI hata sayılır — geçilmez.
     *
     * 0.2.0 tasarımında denetim `(int) ($_POST['beklenen_surum'] ?? 0)` ile
     * yapılacaktı ve sıfır "denetim yapma" anlamına geliyordu: alanı hiç
     * göndermeyen bir istek — önbellekten açılmış eski bir sayfa, eksik
     * gönderilen bir form, elle kurulmuş bir istek — kilidi tamamen atlıyordu.
     * Yani kilit tam olarak korunması gereken durumda açıktı (fail-open).
     */
    $expected = null;

    if (!$isNew) {
        if (!array_key_exists('beklenen_surum', $_POST) || !ctype_digit((string) $_POST['beklenen_surum'])) {
            admin_flash(
                'error',
                'Form eksik gönderildi (sürüm bilgisi yok); değişiklik kaydedilmedi. '
                . 'Sayfayı yenileyip yeniden deneyin.'
            );

            admin_redirect($type->editUrl($entry->id), 'error', 'Kayıt güvenli biçimde durduruldu.');
        }

        $expected = (int) $_POST['beklenen_surum'];
    }

    $result = $app->content()->save($entry, $meta, $termSelection, $expected);

    if (!$result['ok']) {
        /*
         * Çakışmada kullanıcının yazdığı KAYBOLMAZ: reddedilen yük kendi
         * otomatik kayıt slotuna yazılır ve sürüm geçmişinden geri alınabilir.
         * Slot kullanıcı başına olduğu için diğer kullanıcının kaydını ezmez.
         */
        if (!empty($result['conflict'])) {
            $app->content()->snapshot($entry, $app->auth()->id(), 'autosave');

            admin_flash(
                'error',
                'Bu içerik siz düzenlerken başkası tarafından kaydedildi. Yazdıklarınız '
                . 'kaybolmadı: sürüm geçmişinde "otomatik" kaydı olarak duruyor. '
                . 'Sayfayı yenileyip karşılaştırın.'
            );

            admin_redirect($type->editUrl($entry->id), 'error', '');
        }

        admin_flash('error', $result['error']);
    } else {
        // Kaydedilen her hâl sürüm olarak saklanır; geri dönüş mümkün olsun.
        $saved = $app->content()->find($result['id'], false);

        if ($saved !== null) {
            $app->content()->snapshot($saved, $app->auth()->id(), 'save');
        }

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

    <?php if (!$isNew) : ?>
        <?php
        /*
         * İyimser kilit tabanı. Formu açtığınız andaki sürüm sayacı; kaydetmede
         * sunucu bununla eşleşmezse araya başka bir yazma girmiş demektir ve
         * kayıt reddedilir. Alanın yokluğu da hata sayılır (bkz. yukarısı) —
         * yoksa eksik gönderilen bir istek kilidi atlar.
         */
        ?>
        <input type="hidden" name="beklenen_surum"
               value="<?= (int) $app->content()->revisionOf($entry->id) ?>">
    <?php endif; ?>

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

                                    /*
                                     * media-list ve repeater'ın KENDİ kolları olmak zorunda.
                                     *
                                     * 0.2.0'da ikisi de `default` koluna düşüyordu ve değerleri
                                     * dizi olduğu için `is_scalar($value) ? … : ''` BOŞ bir metin
                                     * kutusu basıyordu. Kullanıcı hiçbir şeye dokunmadan içeriği
                                     * kaydettiğinde o boş değer yazılıyor ve kayıtlı liste
                                     * SİLİNİYORDU — sessiz veri kaybı.
                                     */
                                    'media-list' => '<textarea class="input mono" id="' . esc_attr($inputId)
                                        . '" name="' . esc_attr($name) . '" rows="3">'
                                        . esc_html(is_array($value) ? implode(', ', array_map('strval', $value)) : '')
                                        . '</textarea>',

                                    'repeater' => '<textarea class="input mono" id="' . esc_attr($inputId)
                                        . '" name="' . esc_attr($name) . '" rows="8">'
                                        . esc_html(is_array($value)
                                            ? (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                                | JSON_UNESCAPED_SLASHES)
                                            : '')
                                        . '</textarea>',

                                    default  => ui_input($name, is_scalar($value) ? (string) $value : '', [
                                        'id' => $inputId, 'placeholder' => $field->placeholder,
                                    ]),
                                };

                                // İki tür için biçim ipucu yardım metnine eklenir.
                                $help = $field->help;

                                if ($field->type === 'media-list') {
                                    $help = trim($help . ' Medya kimlikleri, virgül ya da satır sonuyla ayrılmış.');
                                } elseif ($field->type === 'repeater') {
                                    $help = trim($help . ' JSON dizisi. Bozuk JSON kaydedilmez, mevcut değer korunur.');
                                }

                                echo $field->type === 'switch'
                                    ? '<div class="field">' . $control . '</div>'
                                    : ui_field($field->label, $control, esc_html($help), $inputId, $field->required);
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
                        <?php
                        /*
                         * Silme POST ile. 0.2.0'da anahtarı sorgu dizesinde taşıyan
                         * bir bağlantıydı; tarayıcı ön-getirmesi, bir bağlantı
                         * önizleyicisi ya da geçmişten açılan sekme içeriği
                         * silebiliyordu. GET durum değiştirmez.
                         *
                         * Düğme sayfanın altındaki gizli forma gönderilir; iç içe
                         * form yasağı bu şekilde aşılıyor (paneldeki yerleşik kalıp).
                         */
                        ?>
                        <button class="btn btn-sm btn-danger" type="submit"
                                form="content-delete-form"
                                <?= ui_confirm('Bu içerik kalıcı olarak silinecek. Devam edilsin mi?') ?>>
                            <?= admin_icon('trash', 14) ?>Sil
                        </button>
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

<?php if (!$isNew && $app->auth()->can('content.delete')) : ?>
    <?php
    /*
     * Silme formu. Düzenleme formunun İÇİNDE olamaz (iç içe form yasak), o
     * yüzden dışarıda duruyor ve silme düğmesi form="content-delete-form" ile
     * buraya gönderiyor.
     */
    ?>
    <form id="content-delete-form" method="post" action="content-delete.php" hidden>
        <?= hi_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $entry->id ?>">
    </form>
<?php endif; ?>

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

<?php
/*
 * EDİTÖR VERİSİ BETİK DEĞİL, VERİ.
 *
 * 0.3.0'a kadar bu blok `window.HI_EDITOR = {…}` yazan satır içi bir betikti
 * ve `<main>` içindeydi. Anında sayfa geçişi (nav.js) bölgeyi değiştirirken
 * gelen işaretlemedeki <script> etiketleri ÇALIŞMAZ — HTML standardı böyle.
 * Yani editöre geçildiğinde veri hiç kurulmuyor ve blok editörü ölüyordu.
 *
 * `type="application/json"` bir betik değil veri taşıyıcısıdır: çalıştırılmaz
 * ama DOM'a bir öğe olarak girer, dolayısıyla bölge değişiminden sonra da
 * okunabilir. editor.js onu ayrıştırıyor.
 *
 * İzin listesi de buradan gelir: richtext.js kendi satır içi etiket tablosunu
 * taşıyordu; sunucudaki allowlist (src/Support/Html.php) değişince istemci
 * sessizce ayrışır ve kullanıcı uyguladığı biçimin kaydedildikten sonra
 * kaybolduğunu görür. Tek kaynak sunucu.
 */
?>
<script type="application/json" id="hi-editor-data">
    <?= esc_json([
        'types'   => $blockTypes,
        'blocks'  => $entry->blocks,
        'media'   => $mediaMap,
        'icons'   => $iconMap,
        'allowed' => HiCMS\Support\Html::allowed(),
    ]) ?>
</script>

<?php // richtext.js editor.js'ten ÖNCE: editör alan kurarken HiRichText hazır olmalı. ?>
<script src="<?= esc_attr(admin_asset('assets/js/richtext.js')) ?>"></script>
<script src="<?= esc_attr(admin_asset('assets/js/editor.js')) ?>"></script>
<script>
    /* Öne çıkan görsel seçimi: medya penceresini editörden bağımsız kullanır. */
    (function () {
        var pick = document.getElementById('thumb-pick');
        var field = document.getElementById('gorsel');
        var modal = document.getElementById('media-modal');
        if (!pick || !field || !modal) return;

        var media = (window.HI_EDITOR || {}).media || {};

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
