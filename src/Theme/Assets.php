<?php

declare(strict_types=1);

namespace HiCMS\Theme;

use HiCMS\Support\Str;

/**
 * Varlık (CSS/JS) kuyruğu.
 *
 * Bağımlılıklar sıralanır: `deps` verilen dosyalar kendinden önce basılır.
 * Aynı dosya iki kez kuyruğa alınsa da bir kez çıkar — bir tema ve bir eklenti
 * aynı kütüphaneyi istediğinde çift yükleme olmaz.
 */
final class Assets
{
    /** @var array<string, array{src: string, deps: list<string>, version: string, media: string, inline: string}> */
    private array $styles = [];

    /** @var array<string, array{src: string, deps: list<string>, version: string, footer: bool, inline: string, module: bool}> */
    private array $scripts = [];

    /** @var list<array{href: string, as: string, type: string, crossorigin: bool}> */
    private array $preloads = [];

    /** @var list<string> */
    private array $headHtml = [];

    public function __construct(private readonly string $defaultVersion = '1.0.0')
    {
    }

    /**
     * @param list<string> $deps
     */
    public function style(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        string $media = 'all',
    ): void {
        $this->styles[$handle] = [
            'src'     => $src,
            'deps'    => $deps,
            'version' => $version ?? $this->defaultVersion,
            'media'   => $media,
            'inline'  => $this->styles[$handle]['inline'] ?? '',
        ];
    }

    /**
     * @param list<string> $deps
     */
    public function script(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        bool $footer = true,
        bool $module = false,
    ): void {
        $this->scripts[$handle] = [
            'src'     => $src,
            'deps'    => $deps,
            'version' => $version ?? $this->defaultVersion,
            'footer'  => $footer,
            'inline'  => $this->scripts[$handle]['inline'] ?? '',
            'module'  => $module,
        ];
    }

    public function inlineStyle(string $handle, string $css): void
    {
        $this->styles[$handle]['inline'] = ($this->styles[$handle]['inline'] ?? '') . $css;

        // Bağlı bir dosya yoksa yalnızca satır içi stil olarak basılır.
        $this->styles[$handle]['src']     ??= '';
        $this->styles[$handle]['deps']    ??= [];
        $this->styles[$handle]['version'] ??= $this->defaultVersion;
        $this->styles[$handle]['media']   ??= 'all';
    }

    public function inlineScript(string $handle, string $js): void
    {
        $this->scripts[$handle]['inline'] = ($this->scripts[$handle]['inline'] ?? '') . $js;

        $this->scripts[$handle]['src']     ??= '';
        $this->scripts[$handle]['deps']    ??= [];
        $this->scripts[$handle]['version'] ??= $this->defaultVersion;
        $this->scripts[$handle]['footer']  ??= true;
        $this->scripts[$handle]['module']  ??= false;
    }

    public function preload(string $href, string $as = 'font', string $type = '', bool $crossorigin = true): void
    {
        $this->preloads[] = ['href' => $href, 'as' => $as, 'type' => $type, 'crossorigin' => $crossorigin];
    }

    /**
     * <head> içine ham HTML ekler (meta etiketleri, yapısal veri).
     */
    public function head(string $html): void
    {
        $this->headHtml[] = $html;
    }

    public function dequeueStyle(string $handle): void
    {
        unset($this->styles[$handle]);
    }

    public function dequeueScript(string $handle): void
    {
        unset($this->scripts[$handle]);
    }

    public function hasStyle(string $handle): bool
    {
        return isset($this->styles[$handle]);
    }

    /**
     * <head> çıktısı.
     */
    public function renderHead(): string
    {
        $html = '';

        foreach ($this->preloads as $preload) {
            $html .= '<link rel="preload" href="' . Str::url($preload['href']) . '" as="' . Str::attr($preload['as']) . '"'
                . ($preload['type'] !== '' ? ' type="' . Str::attr($preload['type']) . '"' : '')
                . ($preload['crossorigin'] ? ' crossorigin' : '') . ">\n";
        }

        foreach ($this->sort($this->styles) as $handle => $style) {
            if (($style['src'] ?? '') !== '') {
                $html .= '<link rel="stylesheet" id="' . Str::attr($handle) . '-css" href="'
                    . Str::url($this->versioned($style['src'], $style['version'])) . '"'
                    . ($style['media'] !== 'all' ? ' media="' . Str::attr($style['media']) . '"' : '')
                    . ">\n";
            }

            if (($style['inline'] ?? '') !== '') {
                $html .= '<style id="' . Str::attr($handle) . '-inline">' . $style['inline'] . "</style>\n";
            }
        }

        foreach ($this->sort($this->scripts) as $handle => $script) {
            if ($script['footer']) {
                continue;
            }

            $html .= $this->scriptTag($handle, $script);
        }

        foreach ($this->headHtml as $extra) {
            $html .= $extra . "\n";
        }

        return $html;
    }

    /**
     * </body> öncesi çıktı.
     */
    public function renderFooter(): string
    {
        $html = '';

        foreach ($this->sort($this->scripts) as $handle => $script) {
            if (empty($script['footer'])) {
                continue;
            }

            $html .= $this->scriptTag($handle, $script);
        }

        return $html;
    }

    /**
     * @param array{src?: string, version?: string, module?: bool, inline?: string} $script
     */
    private function scriptTag(string $handle, array $script): string
    {
        $html = '';

        if (($script['src'] ?? '') !== '') {
            $html .= '<script id="' . Str::attr($handle) . '-js" src="'
                . Str::url($this->versioned($script['src'], (string) ($script['version'] ?? ''))) . '"'
                . (!empty($script['module']) ? ' type="module"' : ' defer')
                . "></script>\n";
        }

        if (($script['inline'] ?? '') !== '') {
            $html .= '<script id="' . Str::attr($handle) . '-inline">' . $script['inline'] . "</script>\n";
        }

        return $html;
    }

    private function versioned(string $src, string $version): string
    {
        if ($version === '' || str_contains($src, 'v=')) {
            return $src;
        }

        return $src . (str_contains($src, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    /**
     * Bağımlılıklara göre sıralar (basit derinlik-öncelikli çözüm).
     *
     * @param array<string, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private function sort(array $items): array
    {
        $sorted  = [];
        $visited = [];

        $visit = static function (string $handle) use (&$visit, &$sorted, &$visited, $items): void {
            if (isset($visited[$handle]) || !isset($items[$handle])) {
                return;
            }

            $visited[$handle] = true;

            foreach ((array) ($items[$handle]['deps'] ?? []) as $dependency) {
                $visit((string) $dependency);
            }

            $sorted[$handle] = $items[$handle];
        };

        foreach (array_keys($items) as $handle) {
            $visit((string) $handle);
        }

        return $sorted;
    }
}
