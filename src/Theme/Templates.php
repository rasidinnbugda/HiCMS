<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use HiCMS\Events\Dispatcher;
use HiCMS\Events\Render\TemplateResolving;

/**
 * Şablon çözümleme ve yükleme.
 *
 * Şablon hiyerarşisi `ViewContext` tarafından üretilir, `TemplateResolving`
 * olayıyla eklentilere açılır ve ilk var olan dosya kullanılır. Şablonlara veri
 * küresel değişkenle değil, `$data` dizisiyle geçer.
 */
final class Templates
{
    /** @var array<string, string> Aynı istekte tekrar dosya sistemi taraması yapmamak için */
    private array $resolvedCache = [];

    public function __construct(
        private readonly ThemeManager $theme,
        private readonly Dispatcher $events,
        private readonly ViewContext $view,
    ) {
    }

    /**
     * Geçerli bağlam için şablon dosyasını bulur.
     */
    public function resolve(): string
    {
        $event = $this->events->dispatch(
            new TemplateResolving($this->view->templateCandidates(), $this->view->kind)
        );

        return $this->locate($event->candidates);
    }

    /**
     * Aday listesinden ilk var olan dosyanın tam yolunu döndürür.
     *
     * @param list<string> $candidates
     */
    public function locate(array $candidates): string
    {
        $key = implode('|', $candidates);

        if (isset($this->resolvedCache[$key])) {
            return $this->resolvedCache[$key];
        }

        foreach ($candidates as $candidate) {
            $candidate = ltrim(str_replace(['..', '\\'], ['', '/'], $candidate), '/');

            if ($candidate === '') {
                continue;
            }

            $path = $this->theme->directory($candidate);

            if (is_readable($path)) {
                return $this->resolvedCache[$key] = $path;
            }
        }

        return $this->resolvedCache[$key] = '';
    }

    /**
     * Şablon dosyasını çalıştırır.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $file, array $data = []): void
    {
        if ($file === '' || !is_readable($file)) {
            return;
        }

        // $data ve $view şablon içinde erişilebilir olur.
        $view = $this->view;

        require $file;
    }

    /**
     * Şablon parçası yükler: `parts/{slug}-{name}.php` → `parts/{slug}.php`
     *
     * @param array<string, mixed> $data
     */
    public function part(string $slug, string $name = '', array $data = []): void
    {
        $candidates = [];

        if ($name !== '') {
            $candidates[] = "parts/{$slug}-{$name}.php";
            $candidates[] = "{$slug}-{$name}.php";
        }

        $candidates[] = "parts/{$slug}.php";
        $candidates[] = "{$slug}.php";

        $file = $this->locate($candidates);

        if ($file === '') {
            return;
        }

        $this->events->emit('theme.before_part', $slug, $name);
        $this->render($file, $data);
        $this->events->emit('theme.after_part', $slug, $name);
    }

    public function header(string $name = ''): void
    {
        $this->render($this->locate($name !== '' ? ["header-{$name}.php", 'header.php'] : ['header.php']));
    }

    public function footer(string $name = ''): void
    {
        $this->render($this->locate($name !== '' ? ["footer-{$name}.php", 'footer.php'] : ['footer.php']));
    }

    public function sidebar(string $name = ''): void
    {
        $this->render($this->locate($name !== '' ? ["sidebar-{$name}.php", 'sidebar.php'] : ['sidebar.php']));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function comments(array $data = []): void
    {
        $this->render($this->locate(['comments.php']), $data);
    }

    public function exists(string $template): bool
    {
        return $this->locate([$template]) !== '';
    }

    public function context(): ViewContext
    {
        return $this->view;
    }
}
