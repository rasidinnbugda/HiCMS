<?php

declare(strict_types=1);

namespace HiSEO;

use HiCMS\Events\Admin\MenuBuilding;
use HiCMS\Events\Content\Saved;
use HiCMS\Http\Response;
use HiCMS\Http\Router;
use HiCMS\Kernel;
use HiCMS\Plugin\Plugin as BasePlugin;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * HiSEO — arama motoru paketi
 *
 * Dört iş yapar:
 *   1. `/sitemap.xml` ve `/robots.txt` üretir (içerik kaydedildikçe tazelenir)
 *   2. Sayfa başına JSON-LD yapısal veri ve paylaşım etiketleri basar
 *   3. 301 yönlendirme yöneticisi — eski siteden taşınırken bağlantıları kurtarır
 *   4. İçerik düzenleyicide kısa bir SEO denetimi gösterir
 *
 * Hiçbir işlev çekirdeğe sızmaz: eklenti kapatıldığında rotalar ve etiketler
 * kaybolur, içerik olduğu gibi kalır.
 */
final class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Rotalar
        hi_on('routing.register', function (Router $router): void {
            $router->get('/sitemap.xml', fn(): Response => $this->sitemap(), 'seo.sitemap', 1);
            $router->get('/robots.txt', fn(): Response => $this->robots(), 'seo.robots', 1);
        });

        // Yönlendirmeler istek çözülmeden önce denetlenir.
        hi_on('routing.before', function ($request): void {
            $this->maybeRedirect((string) $request->path);
        });

        // <head> etiketleri
        hi_on('theme.head', fn(): null => $this->head());

        // Panel menüsü + ekran
        hi_listen(MenuBuilding::class, static function (MenuBuilding $event): void {
            if (!hi()->auth()->can('settings.manage')) {
                return;
            }

            $event->add('system', [
                'slug'  => 'plugin:hi-seo',
                'label' => 'SEO',
                'icon'  => 'target',
                'url'   => 'plugin.php?eklenti=hi-seo',
            ]);
        });

        hi_on('admin.page.hi-seo', fn(): null => $this->screen());

        // İçerik kaydedildiğinde sitemap önbelleğini düşür.
        hi_listen(Saved::class, function (): void {
            $this->setOption('sitemap_stamp', time());
        });
    }

    public function activate(): void
    {
        $this->setOption('json_ld', true);
        $this->setOption('sitemap', true);
    }

    /* ---------------------------------------------------------------------
     * Sitemap ve robots
     * ------------------------------------------------------------------ */

    private function sitemap(): Response
    {
        $urls = [];
        $base = hi()->urls()->to();

        $urls[] = ['loc' => $base, 'priority' => '1.0', 'changefreq' => 'daily'];

        foreach (hi()->types()->publicTypes() as $type) {
            if ($type->hasArchive()) {
                $urls[] = [
                    'loc'        => hi()->links()->forArchive($type),
                    'priority'   => '0.7',
                    'changefreq' => 'weekly',
                ];
            }

            $entries = hi()->content()->get([
                'type'          => $type->name,
                'visibleOnly'   => true,
                'perPage'       => 0,
                'withRelations' => false,
            ]);

            foreach ($entries as $entry) {
                $urls[] = [
                    'loc'        => hi()->links()->forEntry($entry),
                    'lastmod'    => Dates::iso($entry->updatedAt !== '' ? $entry->updatedAt : $entry->publishedAt),
                    'priority'   => $type->name === 'page' ? '0.6' : '0.8',
                    'changefreq' => 'monthly',
                ];
            }
        }

        foreach (hi()->types()->taxonomies() as $taxonomy) {
            if (!$taxonomy->isPublic) {
                continue;
            }

            foreach (hi()->terms()->forTaxonomy($taxonomy->name, true, true) as $term) {
                $urls[] = [
                    'loc'        => hi()->links()->forTerm($term),
                    'priority'   => '0.5',
                    'changefreq' => 'weekly',
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n"
                . '    <loc>' . Str::html($url['loc']) . '</loc>' . "\n"
                . (($url['lastmod'] ?? '') !== '' ? '    <lastmod>' . Str::html($url['lastmod']) . '</lastmod>' . "\n" : '')
                . '    <changefreq>' . Str::html($url['changefreq']) . '</changefreq>' . "\n"
                . '    <priority>' . Str::html($url['priority']) . '</priority>' . "\n"
                . '  </url>' . "\n";
        }

        return Response::xml($xml . '</urlset>');
    }

    private function robots(): Response
    {
        $indexable = (bool) hi_option('search_engine_index', true);

        $body = "User-agent: *\n";

        if (!$indexable) {
            $body .= "Disallow: /\n";
        } else {
            $body .= "Allow: /\n"
                . "Disallow: /admin/\n"
                . "Disallow: /install.php\n"
                . "Disallow: /content/backups/\n"
                . "Disallow: /content/tmp/\n"
                . "Disallow: /arama\n";
        }

        $custom = trim((string) $this->option('robots_extra', ''));

        if ($custom !== '') {
            $body .= "\n" . $custom . "\n";
        }

        $body .= "\nSitemap: " . hi()->urls()->to('sitemap.xml') . "\n";

        return Response::text($body);
    }

    /* ---------------------------------------------------------------------
     * <head> etiketleri
     * ------------------------------------------------------------------ */

    private function head(): null
    {
        echo '<link rel="canonical" href="' . Str::url($this->canonical()) . '">' . "\n";

        if (!$this->option('json_ld', true)) {
            return null;
        }

        $data = $this->structuredData();

        if ($data !== []) {
            echo '<script type="application/ld+json">'
                . (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>' . "\n";
        }

        return null;
    }

    private function canonical(): string
    {
        $view = hi_view();

        return match ($view->kind) {
            'single', 'page' => $view->entry !== null ? hi()->links()->forEntry($view->entry) : hi()->urls()->to(),
            'taxonomy'       => $view->term !== null ? hi()->links()->forTermPage($view->term, $view->page) : hi()->urls()->to(),
            'archive'        => $view->contentType !== null ? hi()->links()->forArchive($view->contentType, $view->page) : hi()->urls()->to(),
            'author'         => $view->author !== null ? hi()->links()->forAuthor($view->author, $view->page) : hi()->urls()->to(),
            default          => hi()->links()->forHome($view->page),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredData(): array
    {
        $view = hi_view();
        $site = hi_site_name();

        if ($view->isSingular() && $view->entry !== null) {
            $entry = $view->entry;

            $data = [
                '@context'      => 'https://schema.org',
                '@type'         => $entry->type === 'page' ? 'WebPage' : 'BlogPosting',
                'headline'      => $entry->title,
                'description'   => $entry->summary(200),
                'url'           => hi()->links()->forEntry($entry),
                'datePublished' => Dates::iso($entry->publishedAt),
                'dateModified'  => Dates::iso($entry->updatedAt !== '' ? $entry->updatedAt : $entry->publishedAt),
                'publisher'     => ['@type' => 'Organization', 'name' => $site],
            ];

            if ($entry->author !== null) {
                $data['author'] = [
                    '@type' => 'Person',
                    'name'  => $entry->author->displayName,
                    'url'   => hi()->links()->forAuthor($entry->author),
                ];
            }

            if ($entry->image !== null) {
                $data['image'] = hi()->urls()->uploads($entry->image->path);
            }

            return $data;
        }

        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebSite',
            'name'        => $site,
            'url'         => hi()->urls()->to(),
            'description' => (string) hi_option('site_description', ''),
        ];
    }

    /* ---------------------------------------------------------------------
     * Yönlendirmeler
     * ------------------------------------------------------------------ */

    private function maybeRedirect(string $path): void
    {
        $db = hi()->db();

        if (!$db->tableExists('seo_redirects')) {
            return;
        }

        $source = '/' . trim($path, '/');

        $row = $db->builder('seo_redirects')->where('source', $source)->first();

        if ($row === null) {
            return;
        }

        $db->update('seo_redirects', [
            'hits'        => (int) $row['hits'] + 1,
            'last_hit_at' => Dates::stamp(),
        ], ['id' => (int) $row['id']]);

        $target = (string) $row['target'];
        $target = preg_match('#^https?://#i', $target) === 1 ? $target : hi()->urls()->to($target);

        header('Location: ' . $target, true, (int) $row['status'] === 302 ? 302 : 301);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Panel ekranı
     * ------------------------------------------------------------------ */

    private function screen(): null
    {
        $db      = hi()->db();
        $selfUrl = 'plugin.php?eklenti=hi-seo';

        if (hi()->request()->isPost()) {
            admin_verify($selfUrl);

            $action = (string) ($_POST['islem'] ?? '');

            if ($action === 'settings') {
                $this->setOption('json_ld', isset($_POST['json_ld']));
                $this->setOption('sitemap', isset($_POST['sitemap']));
                $this->setOption('robots_extra', trim((string) ($_POST['robots_extra'] ?? '')));

                admin_redirect($selfUrl, 'success', 'SEO ayarları kaydedildi.');
            }

            if ($action === 'add-redirect' && $db->tableExists('seo_redirects')) {
                $source = '/' . trim((string) ($_POST['kaynak'] ?? ''), '/');
                $target = trim((string) ($_POST['hedef'] ?? ''));

                if ($source === '/' || $target === '') {
                    admin_redirect($selfUrl, 'error', 'Kaynak ve hedef zorunludur.');
                }

                $exists = $db->builder('seo_redirects')->where('source', $source)->exists();

                if ($exists) {
                    admin_redirect($selfUrl, 'error', 'Bu kaynak yol için zaten bir yönlendirme var.');
                }

                $db->insert('seo_redirects', [
                    'source'     => $source,
                    'target'     => $target,
                    'status'     => (int) ($_POST['kod'] ?? 301) === 302 ? 302 : 301,
                    'created_at' => Dates::stamp(),
                    'updated_at' => Dates::stamp(),
                ]);

                admin_redirect($selfUrl, 'success', 'Yönlendirme eklendi.');
            }

            if ($action === 'delete-redirect' && $db->tableExists('seo_redirects')) {
                $db->delete('seo_redirects', ['id' => (int) ($_POST['id'] ?? 0)]);

                admin_redirect($selfUrl, 'success', 'Yönlendirme silindi.');
            }
        }

        $redirects = $db->tableExists('seo_redirects')
            ? $db->builder('seo_redirects')->orderBy('id', 'desc')->limit(100)->get()
            : [];

        // Kısa denetim: alternatif metni olmayan görseller, özeti olmayan yazılar.
        $missingExcerpt = hi()->content()->query([
            'type'          => 'post',
            'visibleOnly'   => true,
            'perPage'       => 0,
            'withRelations' => false,
        ]);

        $noExcerpt = 0;

        foreach ($missingExcerpt['items'] as $entry) {
            if (trim($entry->excerpt) === '') {
                $noExcerpt++;
            }
        }

        $noAlt = 0;

        foreach (hi()->mediaRepo()->recent(200, 'image/') as $item) {
            if (trim($item->alt) === '') {
                $noAlt++;
            }
        }

        admin_head([
            'title'       => 'SEO',
            'slug'        => 'plugin:hi-seo',
            'description' => 'Sitemap, yapısal veri ve yönlendirme yönetimi.',
            'breadcrumb'  => [['label' => 'Eklentiler', 'url' => 'plugins.php'], ['label' => 'HiSEO']],
        ]);

        echo ui_metrics([
            ['label' => 'Sitemap', 'value' => $this->option('sitemap', true) ? 'açık' : 'kapalı',
             'note' => 'sitemap.xml', 'href' => hi()->urls()->to('sitemap.xml')],
            ['label' => 'Yapısal veri', 'value' => $this->option('json_ld', true) ? 'açık' : 'kapalı',
             'note' => 'JSON-LD'],
            ['label' => 'Özeti eksik yazı', 'value' => (string) $noExcerpt,
             'note' => $noExcerpt > 0 ? 'arama sonucu metni zayıf kalır' : 'sorun yok'],
            ['label' => 'Alt metni eksik görsel', 'value' => (string) $noAlt,
             'note' => $noAlt > 0 ? 'erişilebilirlik ve SEO kaybı' : 'sorun yok'],
        ]);
        ?>

        <div class="cols-main mt-3">
            <div>
                <section class="panel">
                    <header class="panel-head">
                        <div>
                            <h2 class="panel-title">301 yönlendirmeler</h2>
                            <p class="panel-sub">Eski adresleri yeni sayfalara taşıyın</p>
                        </div>
                    </header>

                    <?php if ($redirects !== []) : ?>
                        <div class="table-wrap">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Kaynak</th>
                                        <th>Hedef</th>
                                        <th>Kod</th>
                                        <th class="num">İsabet</th>
                                        <th class="fit"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($redirects as $row) : ?>
                                        <tr>
                                            <td class="mono small"><?= esc_html((string) $row['source']) ?></td>
                                            <td class="mono small dim"><?= esc_html((string) $row['target']) ?></td>
                                            <td class="small"><?= (int) $row['status'] ?></td>
                                            <td class="num"><?= (int) $row['hits'] ?></td>
                                            <td class="fit">
                                                <form method="post" action="<?= esc_url($selfUrl) ?>">
                                                    <?= hi_csrf_field() ?>
                                                    <input type="hidden" name="islem" value="delete-redirect">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                                    <button class="icon-btn" type="submit" aria-label="Sil"
                                                        <?= ui_confirm('Bu yönlendirme silinecek.') ?>>
                                                        <?= admin_icon('trash', 15) ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <?= ui_empty('link', 'Yönlendirme yok', 'Sağdaki formdan ilk yönlendirmeyi ekleyin.') ?>
                    <?php endif; ?>
                </section>
            </div>

            <div>
                <section class="box">
                    <header class="box-head"><?= admin_icon('plus', 15) ?>Yönlendirme ekle</header>
                    <form method="post" action="<?= esc_url($selfUrl) ?>">
                        <?= hi_csrf_field() ?>
                        <input type="hidden" name="islem" value="add-redirect">

                        <div class="box-body">
                            <?= ui_field('Kaynak yol',
                                '<div class="input-group"><span class="addon">/</span>'
                                . ui_input('kaynak', '', ['id' => 'r-src', 'class' => 'input mono',
                                    'placeholder' => 'eski-yazi']) . '</div>',
                                'Site köküne göre eski adres.', 'r-src') ?>

                            <?= ui_field('Hedef',
                                ui_input('hedef', '', ['id' => 'r-dst', 'class' => 'input mono',
                                    'placeholder' => 'yazi/yeni-adres']),
                                'Göreli yol ya da tam adres.', 'r-dst') ?>

                            <?= ui_field('Kod', ui_select('kod', ['301' => '301 — kalıcı', '302' => '302 — geçici'],
                                '301', ['id' => 'r-code']), '', 'r-code') ?>
                        </div>

                        <footer class="box-foot">
                            <span class="spacer"></span>
                            <button class="btn btn-sm btn-primary" type="submit">Ekle</button>
                        </footer>
                    </form>
                </section>

                <section class="box">
                    <header class="box-head"><?= admin_icon('settings', 15) ?>Ayarlar</header>
                    <form method="post" action="<?= esc_url($selfUrl) ?>">
                        <?= hi_csrf_field() ?>
                        <input type="hidden" name="islem" value="settings">

                        <div class="box-body">
                            <?= ui_switch('sitemap', (bool) $this->option('sitemap', true),
                                'sitemap.xml üret', 'Tüm görünür içerikleri listeler.') ?>

                            <?= ui_switch('json_ld', (bool) $this->option('json_ld', true),
                                'JSON-LD yapısal veri', 'Arama sonuçlarında zengin görünüm sağlar.') ?>

                            <div class="mt-3">
                                <?= ui_field('robots.txt eki',
                                    '<textarea class="input mono" id="r-robots" name="robots_extra" rows="4">'
                                    . esc_html((string) $this->option('robots_extra', '')) . '</textarea>',
                                    'Otomatik kuralların altına eklenir.', 'r-robots') ?>
                            </div>
                        </div>

                        <footer class="box-foot">
                            <span class="spacer"></span>
                            <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                        </footer>
                    </form>
                </section>

                <section class="box">
                    <header class="box-head"><?= admin_icon('info', 15) ?>Adresler</header>
                    <div class="box-body">
                        <ul class="kv">
                            <li><span class="k">Sitemap</span>
                                <span class="v"><a href="<?= esc_url(hi()->urls()->to('sitemap.xml')) ?>" target="_blank" rel="noopener">aç</a></span></li>
                            <li><span class="k">robots.txt</span>
                                <span class="v"><a href="<?= esc_url(hi()->urls()->to('robots.txt')) ?>" target="_blank" rel="noopener">aç</a></span></li>
                            <li><span class="k">RSS</span>
                                <span class="v"><a href="<?= esc_url(hi()->urls()->to('feed')) ?>" target="_blank" rel="noopener">aç</a></span></li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <?php
        admin_foot();

        return null;
    }
}
