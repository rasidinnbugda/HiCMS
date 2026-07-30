<?php

declare(strict_types=1);

namespace HiCMS\Routing;

use HiCMS\Content\ContentType;
use HiCMS\Http\Request;
use HiCMS\Http\Response;
use HiCMS\Kernel;
use HiCMS\Model\Entry;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;
use HiCMS\Theme\ViewContext;

/**
 * Ön yüz denetleyicisi.
 *
 * Rota tablosu içerik türü kaydından **üretilir**: yeni bir içerik türü
 * kaydeden tema/eklenti, rotalarını da otomatik kazanır. Eklentiler kendi
 * rotalarını `Booted` olayında ekleyebilir; yakala-hepsini sayfa rotası her
 * zaman en son denenir.
 */
final class FrontController
{
    public function __construct(private readonly Kernel $app)
    {
    }

    /**
     * İsteği karşılar.
     */
    public function handle(Request $request): Response
    {
        if (!$this->app->isInstalled()) {
            return Response::redirect($this->app->urls()->to('install.php'));
        }

        $this->registerRoutes();

        $this->app->events()->emit('routing.before', $request);

        $match = $this->app->router()->match($request);

        if ($match === null) {
            return $this->notFound();
        }

        /** @var callable $handler */
        $handler = $match['handler'];

        return $handler($match['params'], $request);
    }

    /* ---------------------------------------------------------------------
     * Rota tanımları
     * ------------------------------------------------------------------ */

    private function registerRoutes(): void
    {
        $router = $this->app->router();
        $types  = $this->app->types();

        // Ana sayfa ve sayfalanmış akış
        $router->get('/', fn(array $p): Response => $this->home(1), 'home', 10);
        $router->get('/sayfa/{page:\d+}', fn(array $p): Response => $this->home((int) $p['page']), 'home.paged', 10);

        // Arama
        $router->get('/arama', fn(array $p, Request $r): Response => $this->search($r), 'search', 10);

        // RSS beslemesi
        $router->get('/feed', fn(): Response => $this->feed(), 'feed', 10);

        // Yorum gönderimi
        $router->post('/yorum', fn(array $p, Request $r): Response => $this->submitComment($r), 'comment.submit', 5);

        // Yazar arşivi
        $router->get('/yazar/{slug}', fn(array $p): Response => $this->author((string) $p['slug'], 1), 'author', 20);
        $router->get(
            '/yazar/{slug}/sayfa/{page:\d+}',
            fn(array $p): Response => $this->author((string) $p['slug'], (int) $p['page']),
            'author.paged',
            20
        );

        // Taksonomi arşivleri
        foreach ($types->taxonomies() as $taxonomy) {
            if (!$taxonomy->isPublic || $taxonomy->route === '') {
                continue;
            }

            $base = '/' . $taxonomy->route;

            $router->get(
                $base . '/{slug}',
                fn(array $p): Response => $this->taxonomy($taxonomy->name, (string) $p['slug'], 1),
                'taxonomy.' . $taxonomy->name,
                30
            );

            $router->get(
                $base . '/{slug}/sayfa/{page:\d+}',
                fn(array $p): Response => $this->taxonomy($taxonomy->name, (string) $p['slug'], (int) $p['page']),
                'taxonomy.' . $taxonomy->name . '.paged',
                30
            );
        }

        // İçerik türü arşivleri ve tek kayıt rotaları
        foreach ($types->publicTypes() as $type) {
            if ($type->hasArchive()) {
                $archive = '/' . (string) $type->archive;

                $router->get(
                    $archive,
                    fn(array $p): Response => $this->archive($type, 1),
                    'archive.' . $type->name,
                    40
                );

                $router->get(
                    $archive . '/sayfa/{page:\d+}',
                    fn(array $p): Response => $this->archive($type, (int) $p['page']),
                    'archive.' . $type->name . '.paged',
                    40
                );
            }

            if ($type->route === '') {
                continue;
            }

            if ($this->app->links()->structure() === 'date' && $type->name === 'post') {
                $router->get(
                    '/{year:\d{4}}/{month:\d{2}}/{slug}',
                    fn(array $p): Response => $this->single($type, (string) $p['slug']),
                    'single.' . $type->name,
                    50
                );

                continue;
            }

            $router->get(
                '/' . $type->route . '/{slug}',
                fn(array $p): Response => $this->single($type, (string) $p['slug']),
                'single.' . $type->name,
                50
            );
        }

        // Eklentiler kendi rotalarını buraya ekler.
        $this->app->events()->emit('routing.register', $router);

        // Yakala-hepsini: kök yolda oturan içerik türleri (sayfa).
        $router->get('/{slug}', fn(array $p): Response => $this->rootSlug((string) $p['slug']), 'root', 90);
    }

    /* ---------------------------------------------------------------------
     * İşleyiciler
     * ------------------------------------------------------------------ */

    private function home(int $page): Response
    {
        $view    = $this->app->view();
        $perPage = max(1, (int) $this->app->options()->get('posts_per_page', 8));

        $view->kind    = ViewContext::HOME;
        $view->page    = max(1, $page);
        $view->perPage = $perPage;

        // Manşet yalnızca ilk sayfada.
        $exclude = [];

        if ($view->page === 1 && (bool) $this->app->options()->get('show_featured', true)) {
            $featured = $this->app->content()->get([
                'type'        => 'post',
                'visibleOnly' => true,
                'featured'    => true,
                'perPage'     => 1,
            ]);

            $view->featured = $featured[0] ?? null;

            if ($view->featured !== null) {
                $exclude[] = $view->featured->id;
            }
        }

        $result = $this->app->content()->query([
            'type'        => 'post',
            'visibleOnly' => true,
            'exclude'     => $exclude,
            'page'        => $view->page,
            'perPage'     => $perPage,
        ]);

        $this->fill($view, $result);

        return $this->render();
    }

    private function single(ContentType $type, string $slug): Response
    {
        $entry = $this->app->content()->findBySlug($type->name, $slug);

        if ($entry === null) {
            return $this->notFound();
        }

        if (!$this->isViewable($entry)) {
            return $this->notFound();
        }

        $view              = $this->app->view();
        $view->kind        = ViewContext::SINGLE;
        $view->entry       = $entry;
        $view->entries     = [$entry];
        $view->contentType = $type;
        $view->total       = 1;
        $view->pages       = 1;

        $this->countView($entry);

        return $this->render();
    }

    /**
     * Kök yoldaki kısa ad: sayfa türü veya rota öneki olmayan bir tür.
     */
    private function rootSlug(string $slug): Response
    {
        foreach ($this->app->types()->publicTypes() as $type) {
            if ($type->route !== '') {
                continue;
            }

            $entry = $this->app->content()->findBySlug($type->name, $slug);

            if ($entry === null || !$this->isViewable($entry)) {
                continue;
            }

            $view              = $this->app->view();
            $view->kind        = $type->name === 'page' ? ViewContext::PAGE : ViewContext::SINGLE;
            $view->entry       = $entry;
            $view->entries     = [$entry];
            $view->contentType = $type;
            $view->total       = 1;
            $view->pages       = 1;

            $this->countView($entry);

            return $this->render();
        }

        return $this->notFound();
    }

    private function archive(ContentType $type, int $page): Response
    {
        $view              = $this->app->view();
        $view->kind        = ViewContext::ARCHIVE;
        $view->contentType = $type;
        $view->page        = max(1, $page);
        $view->perPage     = max(1, (int) $this->app->options()->get('posts_per_page', 8));

        $result = $this->app->content()->query([
            'type'        => $type->name,
            'visibleOnly' => true,
            'orderBy'     => $type->orderBy,
            'orderDir'    => $type->orderDir,
            'page'        => $view->page,
            'perPage'     => $view->perPage,
        ]);

        $this->fill($view, $result);

        return $this->render();
    }

    private function taxonomy(string $taxonomy, string $slug, int $page): Response
    {
        $term = $this->app->terms()->findBySlug($taxonomy, $slug);

        if ($term === null) {
            return $this->notFound();
        }

        $view          = $this->app->view();
        $view->kind    = ViewContext::TAXONOMY;
        $view->term    = $term;
        $view->page    = max(1, $page);
        $view->perPage = max(1, (int) $this->app->options()->get('posts_per_page', 8));

        $result = $this->app->content()->query([
            'type'        => 'all',
            'visibleOnly' => true,
            'termId'      => $term->id,
            'page'        => $view->page,
            'perPage'     => $view->perPage,
        ]);

        $this->fill($view, $result);

        return $this->render();
    }

    private function author(string $slug, int $page): Response
    {
        $author = $this->app->users()->findBySlug($slug);

        if ($author === null) {
            return $this->notFound();
        }

        $view          = $this->app->view();
        $view->kind    = ViewContext::AUTHOR;
        $view->author  = $author;
        $view->page    = max(1, $page);
        $view->perPage = max(1, (int) $this->app->options()->get('posts_per_page', 8));

        $result = $this->app->content()->query([
            'type'        => 'post',
            'visibleOnly' => true,
            'author'      => $author->id,
            'page'        => $view->page,
            'perPage'     => $view->perPage,
        ]);

        $this->fill($view, $result);

        return $this->render();
    }

    private function search(Request $request): Response
    {
        $term = trim((string) ($request->query('q') ?? $request->query('s') ?? ''));

        $view              = $this->app->view();
        $view->kind        = ViewContext::SEARCH;
        $view->searchTerm  = mb_substr($term, 0, 120);
        $view->page        = max(1, (int) ($request->query('sayfa') ?? 1));
        $view->perPage     = max(1, (int) $this->app->options()->get('posts_per_page', 8));

        if ($view->searchTerm !== '') {
            $result = $this->app->content()->query([
                'type'        => 'all',
                'visibleOnly' => true,
                'search'      => $view->searchTerm,
                'page'        => $view->page,
                'perPage'     => $view->perPage,
            ]);

            $this->fill($view, $result);
        }

        return $this->render();
    }

    /**
     * Yorum gönderimi. Başarıda içeriğe geri döner.
     */
    private function submitComment(Request $request): Response
    {
        $entryId = $request->int('yazi');
        $entry   = $entryId > 0 ? $this->app->content()->find($entryId) : null;

        if ($entry === null || !$entry->isPublished()) {
            return Response::redirect($this->app->urls()->to())->withStatus(303);
        }

        $target = $this->app->links()->forEntry($entry);

        if (!$this->app->csrf()->verify($request->text('_token'))) {
            return Response::redirect($target . '?yorum=hata#yorum-formu')->withStatus(303);
        }

        $result = $this->app->comments()->submit([
            'entry'    => $entry->id,
            'parent'   => $request->int('ust_yorum'),
            'name'     => $request->text('ad'),
            'email'    => $request->text('eposta'),
            'url'      => $request->text('site'),
            'body'     => $request->text('yorum'),
            'ip'       => $request->ip(),
            'honeypot' => $request->text('web_adresi'),
        ], (bool) $this->app->options()->get('comment_moderation', true));

        if (!$result['ok']) {
            $this->app->events()->emit('comment.rejected', $result['error'], $entry);

            return Response::redirect($target . '?yorum=hata&mesaj=' . rawurlencode($result['error']) . '#yorum-formu')
                ->withStatus(303);
        }

        $this->app->events()->emit('comment.submitted', $result, $entry);

        $flag = $result['status'] === 'approved' ? 'yayinda' : 'beklemede';

        return Response::redirect($target . '?yorum=' . $flag . '#yorumlar')->withStatus(303);
    }

    /**
     * RSS beslemesi.
     */
    private function feed(): Response
    {
        $entries = $this->app->content()->get([
            'type'        => 'post',
            'visibleOnly' => true,
            'perPage'     => (int) $this->app->options()->get('feed_count', 15),
        ]);

        $site  = $this->app->siteName();
        $base  = $this->app->urls()->to();
        $links = $this->app->links();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
        $xml .= '<title>' . Str::html($site) . '</title>' . "\n";
        $xml .= '<link>' . Str::html($base) . '</link>' . "\n";
        $xml .= '<description>' . Str::html((string) $this->app->options()->get('site_description', '')) . '</description>' . "\n";
        $xml .= '<language>tr</language>' . "\n";
        $xml .= '<atom:link href="' . Str::html($links->forFeed()) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($entries as $entry) {
            $url = $links->forEntry($entry);

            $xml .= '<item>' . "\n";
            $xml .= '<title>' . Str::html($entry->title) . '</title>' . "\n";
            $xml .= '<link>' . Str::html($url) . '</link>' . "\n";
            $xml .= '<guid isPermaLink="true">' . Str::html($url) . '</guid>' . "\n";
            $xml .= '<pubDate>' . date('r', (int) strtotime($entry->publishedAt ?? $entry->createdAt)) . '</pubDate>' . "\n";
            $xml .= '<description><![CDATA[' . $entry->summary(300) . ']]></description>' . "\n";
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel></rss>';

        return Response::xml($xml);
    }

    private function notFound(): Response
    {
        $view       = $this->app->view();
        $view->kind = ViewContext::NOTFOUND;

        return $this->render()->withStatus(404);
    }

    /* ---------------------------------------------------------------------
     * Yardımcılar
     * ------------------------------------------------------------------ */

    /**
     * Şablonu çalıştıran yanıt üretir.
     */
    private function render(): Response
    {
        $template = $this->app->templates()->resolve();

        if ($template === '') {
            return Response::html(
                '<h1>Tema şablonu bulunamadı</h1><p>Etkin temada <code>index.php</code> yok. '
                . 'Panelden başka bir tema seçin veya temayı yeniden kurun.</p>',
                500
            );
        }

        return Response::deferred(function () use ($template): void {
            $this->app->templates()->render($template);
        });
    }

    /**
     * @param array{items: list<Entry>, total: int, page: int, pages: int, perPage: int} $result
     */
    private function fill(ViewContext $view, array $result): void
    {
        $view->entries = $result['items'];
        $view->total   = $result['total'];
        $view->pages   = $result['pages'];
        $view->page    = $result['page'];
        $view->perPage = $result['perPage'];
        $view->rewind();
    }

    /**
     * Taslak ve özel içerik yalnızca yetkili kullanıcıya ya da geçerli önizleme
     * anahtarıyla görünür.
     */
    private function isViewable(Entry $entry): bool
    {
        if ($entry->isPublished()) {
            return true;
        }

        $token = (string) ($this->app->request()->query('onizleme') ?? '');

        if ($token !== '' && hash_equals($this->previewToken($entry), $token)) {
            return true;
        }

        return $this->app->auth()->canEdit($entry->authorId);
    }

    public function previewToken(Entry $entry): string
    {
        return substr(hash_hmac(
            'sha256',
            'preview|' . $entry->id . '|' . $entry->updatedAt,
            (string) $this->app->config()->get('keys.app', 'hicms')
        ), 0, 32);
    }

    /**
     * Görüntülenme sayacı. Bot ve yazarın kendi ziyaretleri sayılmaz.
     */
    private function countView(Entry $entry): void
    {
        if ($this->app->auth()->check()) {
            return;
        }

        $agent = strtolower($this->app->request()->userAgent());

        foreach (['bot', 'crawler', 'spider', 'preview', 'curl', 'wget'] as $needle) {
            if (str_contains($agent, $needle)) {
                return;
            }
        }

        $this->app->content()->incrementViews($entry->id);
    }
}
