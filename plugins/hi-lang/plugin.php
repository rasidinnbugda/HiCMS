<?php

declare(strict_types=1);

namespace HiLang;

use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Http\Router;
use HiCMS\Model\Entry;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Str;

/**
 * HiLang — içerik çokluluğu
 *
 * Çekirdek arayüz çevirisini zaten yapıyor (kaynak metinler Türkçe, dil
 * dosyaları hedef dile eşliyor). Eksik olan **içeriğin** dil sürümleriydi; bu
 * eklenti onu ekler.
 *
 * Yaklaşım basit ve geri dönüşü kolay: her içerik `content_meta` üzerinde iki
 * değer taşır — `locale` (dili) ve `translation_group` (aynı içeriğin diğer dil
 * sürümlerini birbirine bağlayan anahtar). Yeni tablo açılmaz; eklenti
 * kapatıldığında içerik olduğu gibi kalır, yalnızca dil bağları görünmez olur.
 *
 * İkincil diller `/en/...` gibi önekli adreslerden servis edilir.
 */
final class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Dil değiştirici bileşen
        hi_register_widget_type('language-switcher', [
            'label'  => 'Dil Değiştirici',
            'icon'   => 'globe',
            'fields' => [
                ['key' => 'style', 'type' => 'select', 'label' => 'Biçim', 'default' => 'inline',
                 'options' => ['inline' => 'Yan yana', 'list' => 'Liste']],
            ],
            'render' => fn(array $settings): string => $this->switcher((string) ($settings['style'] ?? 'inline')),
        ]);

        // İkincil dil rotaları: /en, /en/yazi/{slug} gibi
        hi_on('routing.register', function (Router $router): void {
            foreach ($this->secondaryLocales() as $code => $locale) {
                $prefix = '/' . $locale['prefix'];

                $router->get($prefix, function () use ($code): mixed {
                    return $this->localeHome($code);
                }, 'lang.home.' . $code, 8);

                $router->get($prefix . '/{path:.+}', function (array $params) use ($code): mixed {
                    return $this->localeRoute($code, (string) $params['path']);
                }, 'lang.route.' . $code, 9);
            }
        });

        // hreflang etiketleri
        hi_on('theme.head', fn(): null => $this->hreflang());

        // Panel: içerik düzenleyicide dil kutusu için kanca noktası ve yönetim ekranı
        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-lang',
                'label' => 'Diller',
                'icon'  => 'globe',
                'url'   => 'plugin.php?eklenti=hi-lang',
            ]);
        });

        hi_on('admin.page.hi-lang', fn(): null => $this->screen());
    }

    public function activate(): void
    {
        if ($this->option('locales') === null) {
            $this->setOption('locales', [
                'tr_TR' => ['label' => 'Türkçe', 'prefix' => '', 'primary' => true],
                'en_US' => ['label' => 'English', 'prefix' => 'en', 'primary' => false],
            ]);
        }
    }

    /* ---------------------------------------------------------------------
     * Dil tanımları
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{label: string, prefix: string, primary: bool}>
     */
    public function locales(): array
    {
        $stored = $this->option('locales', []);

        if (!is_array($stored) || $stored === []) {
            return ['tr_TR' => ['label' => 'Türkçe', 'prefix' => '', 'primary' => true]];
        }

        $clean = [];

        foreach ($stored as $code => $locale) {
            if (!is_array($locale)) {
                continue;
            }

            $clean[(string) $code] = [
                'label'   => (string) ($locale['label'] ?? $code),
                'prefix'  => Str::slug((string) ($locale['prefix'] ?? '')),
                'primary' => !empty($locale['primary']),
            ];
        }

        return $clean;
    }

    public function primaryLocale(): string
    {
        foreach ($this->locales() as $code => $locale) {
            if ($locale['primary']) {
                return $code;
            }
        }

        return (string) array_key_first($this->locales());
    }

    /**
     * @return array<string, array{label: string, prefix: string, primary: bool}>
     */
    public function secondaryLocales(): array
    {
        return array_filter(
            $this->locales(),
            static fn(array $locale): bool => !$locale['primary'] && $locale['prefix'] !== ''
        );
    }

    /* ---------------------------------------------------------------------
     * Yönlendirme
     * ------------------------------------------------------------------ */

    /**
     * `/en` → o dildeki içerik akışı.
     */
    private function localeHome(string $code): mixed
    {
        hi()->translator()->setLocale($code);

        $view          = hi_view();
        $view->kind    = 'home';
        $view->perPage = max(1, (int) hi_option('posts_per_page', 8));

        $entries = $this->entriesIn($code, $view->perPage);

        $view->entries = $entries;
        $view->total   = count($entries);
        $view->pages   = 1;
        $view->rewind();

        return $this->render();
    }

    /**
     * `/en/yazi/{slug}` → ikincil dildeki tek içerik.
     */
    private function localeRoute(string $code, string $path): mixed
    {
        hi()->translator()->setLocale($code);

        $segments = explode('/', trim($path, '/'));
        $slug     = (string) end($segments);

        foreach (hi()->types()->publicTypes() as $type) {
            $entry = hi()->content()->findBySlug($type->name, $slug, true);

            if ($entry === null) {
                continue;
            }

            $view              = hi_view();
            $view->kind        = $type->name === 'page' ? 'page' : 'single';
            $view->entry       = $entry;
            $view->entries     = [$entry];
            $view->contentType = $type;
            $view->total       = 1;
            $view->pages       = 1;
            $view->rewind();

            return $this->render();
        }

        $view       = hi_view();
        $view->kind = 'notfound';

        return $this->render(404);
    }

    private function render(int $status = 200): mixed
    {
        $template = hi()->templates()->resolve();

        if ($template === '') {
            return \HiCMS\Http\Response::html('<h1>Tema şablonu bulunamadı</h1>', 500);
        }

        return \HiCMS\Http\Response::deferred(static function () use ($template): void {
            hi()->templates()->render($template);
        }, $status);
    }

    /**
     * Belirli dildeki içerikler.
     *
     * @return list<Entry>
     */
    private function entriesIn(string $code, int $limit): array
    {
        $db     = hi()->db();
        $prefix = $db->prefix();

        $rows = $db->select(
            sprintf(
                'SELECT c.* FROM `%1$scontent` c
                 INNER JOIN `%1$scontent_meta` m ON m.entry_id = c.id AND m.meta_key = \'locale\'
                 WHERE c.type = \'post\' AND c.status = \'published\'
                   AND (c.published_at IS NULL OR c.published_at <= :now)
                   AND m.meta_value = :locale
                 ORDER BY c.published_at DESC
                 LIMIT %2$d',
                $prefix,
                max(1, $limit)
            ),
            ['now' => date('Y-m-d H:i:s'), 'locale' => json_encode($code)]
        );

        $entries = array_map([Entry::class, 'fromRow'], $rows);

        hi()->content()->hydrate($entries);

        return $entries;
    }

    /* ---------------------------------------------------------------------
     * hreflang ve değiştirici
     * ------------------------------------------------------------------ */

    private function hreflang(): null
    {
        $entry = hi_view()->entry;

        if ($entry === null) {
            return null;
        }

        foreach ($this->translationsOf($entry) as $code => $translation) {
            $locale = $this->locales()[$code] ?? null;

            if ($locale === null) {
                continue;
            }

            $url = $locale['prefix'] !== ''
                ? hi()->urls()->to($locale['prefix'] . '/' . ltrim(hi()->links()->pathForEntry($translation), '/'))
                : hi()->links()->forEntry($translation);

            printf(
                '<link rel="alternate" hreflang="%s" href="%s">' . "\n",
                Str::attr(str_replace('_', '-', $code)),
                Str::url($url)
            );
        }

        return null;
    }

    /**
     * Bir içeriğin dil sürümleri.
     *
     * @return array<string, Entry>
     */
    public function translationsOf(Entry $entry): array
    {
        $group = (string) ($entry->meta['translation_group'] ?? '');

        if ($group === '') {
            return [];
        }

        $db     = hi()->db();
        $prefix = $db->prefix();

        $rows = $db->select(
            sprintf(
                'SELECT c.*, m2.meta_value AS entry_locale
                 FROM `%1$scontent` c
                 INNER JOIN `%1$scontent_meta` m ON m.entry_id = c.id AND m.meta_key = \'translation_group\'
                 LEFT JOIN `%1$scontent_meta` m2 ON m2.entry_id = c.id AND m2.meta_key = \'locale\'
                 WHERE m.meta_value = :group AND c.status = \'published\'',
                $prefix
            ),
            ['group' => json_encode($group)]
        );

        $translations = [];

        foreach ($rows as $row) {
            $code = trim((string) json_decode((string) ($row['entry_locale'] ?? '""'), true));

            if ($code === '') {
                $code = $this->primaryLocale();
            }

            $translations[$code] = Entry::fromRow($row);
        }

        return $translations;
    }

    private function switcher(string $style): string
    {
        $entry        = hi_view()->entry;
        $translations = $entry !== null ? $this->translationsOf($entry) : [];
        $current      = hi()->translator()->locale();

        $items = '';

        foreach ($this->locales() as $code => $locale) {
            $target = $translations[$code] ?? null;

            if ($target !== null) {
                $url = $locale['prefix'] !== ''
                    ? hi()->urls()->to($locale['prefix'] . '/' . ltrim(hi()->links()->pathForEntry($target), '/'))
                    : hi()->links()->forEntry($target);
            } else {
                $url = $locale['prefix'] !== '' ? hi()->urls()->to($locale['prefix']) : hi()->urls()->to();
            }

            $isCurrent = $code === $current;

            $items .= sprintf(
                '<a class="lang-link%s" href="%s"%s hreflang="%s">%s</a>',
                $isCurrent ? ' is-current' : '',
                Str::url($url),
                $isCurrent ? ' aria-current="true"' : '',
                Str::attr(str_replace('_', '-', $code)),
                Str::html($locale['label'])
            );
        }

        if ($items === '') {
            return '';
        }

        return '<nav class="lang-switcher is-' . Str::attr($style) . '" aria-label="Dil seçimi">'
            . $items . '</nav>';
    }

    /* ---------------------------------------------------------------------
     * Panel
     * ------------------------------------------------------------------ */

    private function screen(): null
    {
        $selfUrl = 'plugin.php?eklenti=hi-lang';

        if (hi()->request()->isPost()) {
            admin_verify($selfUrl);

            $action = (string) ($_POST['islem'] ?? '');

            if ($action === 'locales') {
                $locales = [];
                $primary = (string) ($_POST['birincil'] ?? 'tr_TR');

                foreach ((array) ($_POST['dil'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $code = trim((string) ($row['code'] ?? ''));

                    if ($code === '' || preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) !== 1) {
                        continue;
                    }

                    $locales[$code] = [
                        'label'   => trim((string) ($row['label'] ?? $code)),
                        'prefix'  => Str::slug((string) ($row['prefix'] ?? '')),
                        'primary' => $code === $primary,
                    ];
                }

                if ($locales === []) {
                    admin_redirect($selfUrl, 'error', 'En az bir dil tanımlamalısınız.');
                }

                $this->setOption('locales', $locales);

                admin_redirect($selfUrl, 'success', 'Diller kaydedildi.');
            }

            if ($action === 'link') {
                $entryId = (int) ($_POST['icerik'] ?? 0);
                $code    = trim((string) ($_POST['dil_kodu'] ?? ''));
                $group   = trim((string) ($_POST['grup'] ?? ''));

                if ($entryId <= 0 || $code === '') {
                    admin_redirect($selfUrl, 'error', 'İçerik ve dil zorunludur.');
                }

                hi()->content()->saveMeta($entryId, [
                    'locale'            => $code,
                    'translation_group' => $group !== '' ? Str::slug($group) : 'grup-' . $entryId,
                ]);

                admin_redirect($selfUrl, 'success', 'İçeriğin dil bilgisi kaydedildi.');
            }
        }

        $locales = $this->locales();
        $entries = hi()->content()->query([
            'type'    => 'all',
            'status'  => 'all',
            'perPage' => 40,
            'orderBy' => 'updated_at',
        ]);

        admin_head([
            'title'       => 'Diller',
            'slug'        => 'plugin:hi-lang',
            'description' => 'Dil tanımları ve içeriklerin dil bağları.',
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiLang']],
        ]);
        ?>

        <div class="cols-main">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">İçeriklerin dilleri</h2>
                            <p class="panel-sub">
                                Aynı çeviri grubuna verilen içerikler birbirinin dil sürümü sayılır
                            </p>
                        </div>
                    </header>

                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>İçerik</th>
                                    <th>Dil</th>
                                    <th>Çeviri grubu</th>
                                    <th class="fit"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries['items'] as $entry) : ?>
                                    <?php
                                    $entryLocale = (string) ($entry->meta['locale'] ?? '');
                                    $group       = (string) ($entry->meta['translation_group'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <a class="cell-title" href="content-edit.php?id=<?= (int) $entry->id ?>">
                                                <?= esc_html(Str::limit($entry->title, 52)) ?>
                                            </a>
                                            <span class="cell-sub"><?= esc_html($entry->type) ?></span>
                                        </td>
                                        <td class="small">
                                            <?php if ($entryLocale !== '') : ?>
                                                <?= esc_html((string) ($locales[$entryLocale]['label'] ?? $entryLocale)) ?>
                                            <?php else : ?>
                                                <span class="muted">belirtilmemiş</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="mono small muted"><?= esc_html($group !== '' ? $group : '—') ?></td>
                                        <td class="fit">
                                            <form class="row" method="post" action="<?= esc_url($selfUrl) ?>">
                                                <?= hi_csrf_field() ?>
                                                <input type="hidden" name="islem" value="link">
                                                <input type="hidden" name="icerik" value="<?= (int) $entry->id ?>">

                                                <?= ui_select('dil_kodu', array_combine(
                                                    array_keys($locales),
                                                    array_map(static fn(array $l): string => $l['label'], $locales)
                                                ), $entryLocale !== '' ? $entryLocale : $this->primaryLocale(),
                                                    ['class' => 'input select w-auto',
                                                     'aria-label' => 'Dil']) ?>

                                                <?= ui_input('grup', $group, [
                                                    'class' => 'input mono', 'placeholder' => 'çeviri-grubu',
                                                    'aria-label' => 'Çeviri grubu', 'style' => 'width:150px',
                                                ]) ?>

                                                <button class="btn btn-sm" type="submit">Kaydet</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div>
                <form class="box" method="post" action="<?= esc_url($selfUrl) ?>">
                    <?= hi_csrf_field() ?>
                    <input type="hidden" name="islem" value="locales">

                    <header class="box-head"><?= admin_icon('globe', 15) ?>Diller</header>

                    <div class="box-body">
                        <p class="hint mt-0 mb-2">
                            Birincil dil öneksiz sunulur. İkincil diller <code>/en/…</code> gibi
                            önekli adreslerden servis edilir.
                        </p>

                        <?php
                        $rows = array_values($locales);
                        $codes = array_keys($locales);

                        for ($i = 0; $i < 4; $i++) :
                            $code   = $codes[$i] ?? '';
                            $locale = $rows[$i] ?? ['label' => '', 'prefix' => '', 'primary' => false];
                            ?>
                            <div class="node" style="display:block;padding:10px 12px">
                                <div class="field-row" style="gap:8px">
                                    <?= ui_input('dil[' . $i . '][code]', $code, [
                                        'class' => 'input mono', 'placeholder' => 'tr_TR',
                                        'aria-label' => 'Dil kodu ' . ($i + 1),
                                    ]) ?>
                                    <?= ui_input('dil[' . $i . '][label]', (string) $locale['label'], [
                                        'placeholder' => 'Türkçe', 'aria-label' => 'Dil adı ' . ($i + 1),
                                    ]) ?>
                                </div>
                                <div class="field-row mt-1" style="gap:8px;align-items:center">
                                    <?= ui_input('dil[' . $i . '][prefix]', (string) $locale['prefix'], [
                                        'class' => 'input mono', 'placeholder' => 'önek (boş = birincil)',
                                        'aria-label' => 'Adres öneki ' . ($i + 1),
                                    ]) ?>
                                    <label class="check">
                                        <input type="radio" name="birincil" value="<?= esc_attr($code) ?>"
                                            <?= !empty($locale['primary']) ? 'checked' : '' ?>>
                                        <span class="check-body">Birincil</span>
                                    </label>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <footer class="box-foot">
                        <span class="spacer"></span>
                        <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                    </footer>
                </form>

                <section class="box">
                    <header class="box-head"><?= admin_icon('info', 15) ?>Nasıl çalışır</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Arayüz çevirisi</span><span class="v">çekirdek</span></li>
                            <li><span class="k">Dil dosyaları</span><span class="v mono">src/languages/</span></li>
                            <li><span class="k">İçerik dili</span><span class="v mono">locale</span></li>
                            <li><span class="k">Bağ anahtarı</span><span class="v mono">translation_group</span></li>
                        </ul>
                        <p class="hint">
                            Dil değiştiriciyi göstermek için <strong>Görünüm → Bileşenler</strong> bölümünden
                            "Dil Değiştirici" bileşenini bir alana ekleyin.
                        </p>
                    </div>
                </section>
            </div>
        </div>

        <?php
        admin_foot();

        return null;
    }
}
