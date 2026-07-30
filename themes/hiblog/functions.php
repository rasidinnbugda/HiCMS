<?php

declare(strict_types=1);

/**
 * HiBlog — Tema işlevleri
 *
 * Tasarım kararı: arayüz (başlık, gezinti, üst veri) sans; yalnızca okuma
 * gövdesi serif. Böylece modern bir görünüm korunurken uzun metin okunaklı
 * kalır. Kart çerçevesi kullanılmaz — ayrımı kılçizgi ve boşluk yapar.
 *
 * @package HiBlog
 */

use HiCMS\Events\System\ThemeLoaded;

/**
 * Tema kurulumu: destekler, menü konumları, bileşen alanları.
 */
hi_listen(ThemeLoaded::class, static function (): void {
    hi_theme_support('featured-image');
    hi_theme_support('dark-mode');
    hi_theme_support('comments');
    hi_theme_support('widgets');
    hi_theme_support('blocks');

    hi_register_menus([
        'primary' => 'Ana Menü',
        'footer'  => 'Alt Menü',
    ]);

    hi_register_widget_area([
        'id'          => 'sidebar',
        'name'        => 'Yan Sütun',
        'description' => 'Ana sayfa ve arşivlerde içeriğin sağında görünür.',
    ]);

    hi_register_widget_area([
        'id'          => 'footer',
        'name'        => 'Alt Bilgi',
        'description' => 'Site alt bilgisinin ilk sütununda görünür.',
    ]);
});

/**
 * Yazı tipleri ve stil dosyaları.
 *
 * İki aile: Inter (arayüz) + Source Serif 4 (gövde). Üçüncü bir aile
 * eklenmedi — her ek aile ilk boyamayı geciktirir.
 */
hi_listen(ThemeLoaded::class, static function (): void {
    hi_enqueue_style(
        'hiblog-fonts',
        'https://fonts.googleapis.com/css2'
        . '?family=Inter:wght@400;450;500;600'
        . '&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400'
        . '&display=swap'
    );

    hi_enqueue_style('hiblog', hi_asset('assets/css/style.css'), ['hiblog-fonts']);
    hi_enqueue_script('hiblog', hi_asset('assets/js/theme.js'));
});

/**
 * Üst veri satırı: kategori · tarih · okuma süresi
 *
 * BÜYÜK HARF kullanılmaz; küçük punto ve gri ton yeterli hiyerarşi sağlıyor.
 */
function hiblog_meta(?HiCMS\Model\Entry $entry = null, bool $withReadingTime = true): string
{
    $parts = [];

    $term = hi_the_term('category', 'entry-term', $entry);

    if ($term !== '') {
        $parts[] = $term;
    }

    $parts[] = '<time datetime="' . esc_attr(hi_date_iso($entry)) . '">' . hi_date($entry) . '</time>';

    if ($withReadingTime) {
        $parts[] = hi_reading_time($entry) . ' dk okuma';
    }

    return '<div class="entry-meta">' . implode('<span class="sep" aria-hidden="true">·</span>', $parts) . '</div>';
}

/**
 * Tema ikonları — satır içi SVG, harici kütüphane yok.
 */
function hiblog_icon(string $name, int $size = 16): string
{
    $icons = [
        'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close'     => '<path d="M18 6 6 18M6 6l12 12"/>',
        'reply'     => '<path d="M9 14 4 9l5-5"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'mail'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'compass'   => '<circle cx="12" cy="12" r="9"/><path d="m16 8-2.1 6.4L7.5 16.5l2.1-6.4z"/>',
        'star'      => '<path d="m12 3 2.9 5.9 6.1.9-4.5 4.3 1.1 6.1L12 17.5 6.4 20.2l1.1-6.1L3 9.8l6.1-.9z"/>',
        'inbox'     => '<path d="M3 12h5l2 3h4l2-3h5"/><path d="M5.5 5h13l2.5 7v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6z"/>',
        'x-social'  => '<path d="M4 4l7.5 9.5L4.5 20h2.2l5.8-6.3 4.7 6.3H21l-7.8-10L20 4h-2.2l-5.4 5.9L8 4H4z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>',
        'linkedin'  => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
        'github'    => '<path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.9a3.4 3.4 0 0 0-.9-2.6c3.1-.35 4.9-2 4.9-5.5a5.3 5.3 0 0 0-1.5-3.7 5 5 0 0 0-.1-3.8s-1.4.6-3.4 2a13.4 13.4 0 0 0-7 0C6.4 2.4 5 1.8 5 1.8a5 5 0 0 0-.1 3.8A5.3 5.3 0 0 0 3.4 9.3c0 3.5 1.8 5.15 4.9 5.5a3.4 3.4 0 0 0-.9 2.6V22"/>',
        'youtube'   => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 6 3-6 3z"/>',
    ];

    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}

/**
 * Sosyal bağlantılar — ayarlarda dolu olanlar basılır.
 */
function hiblog_social(): string
{
    $social = (array) hi_option('social', []);

    $labels = [
        'x'         => ['X', 'x-social'],
        'instagram' => ['Instagram', 'instagram'],
        'linkedin'  => ['LinkedIn', 'linkedin'],
        'github'    => ['GitHub', 'github'],
        'youtube'   => ['YouTube', 'youtube'],
    ];

    $links = '';

    foreach ($labels as $key => [$label, $icon]) {
        $url = trim((string) ($social[$key] ?? ''));

        if ($url === '') {
            continue;
        }

        $links .= '<a class="social" href="' . esc_url($url) . '" target="_blank" rel="noopener me"'
            . ' aria-label="' . esc_attr($label) . '">' . hiblog_icon($icon, 17) . '</a>';
    }

    return $links !== '' ? '<div class="social-row">' . $links . '</div>' : '';
}

/**
 * Arşiv başlığı — bağlama göre.
 *
 * @return array{kicker: string, title: string, text: string}
 */
function hiblog_archive_header(): array
{
    if (hi_is_taxonomy()) {
        $term = hi_queried_term();

        return [
            'kicker' => hi()->types()->taxonomy($term?->taxonomy ?? '')?->singular ?? 'Arşiv',
            'title'  => $term?->name ?? '',
            'text'   => $term?->description ?? '',
        ];
    }

    if (hi_is_author()) {
        $author = hi_queried_author();

        return [
            'kicker' => 'Yazar',
            'title'  => $author?->displayName ?? '',
            'text'   => $author?->bio ?? '',
        ];
    }

    if (hi_is_search()) {
        return [
            'kicker' => 'Arama',
            'title'  => hi_search_term() !== '' ? '“' . hi_search_term() . '”' : 'Yazılarda ara',
            'text'   => hi_search_term() !== '' ? __f('%d sonuç bulundu.', hi_found()) : '',
        ];
    }

    $type = hi_queried_type();

    return [
        'kicker' => 'Arşiv',
        'title'  => $type?->plural ?? 'Tüm içerikler',
        'text'   => $type?->description ?? '',
    ];
}

/**
 * Arama motorlarına kapalıysa noindex bas.
 */
hi_on('theme.head', static function (): void {
    if (!hi_option('search_engine_index', true)) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }

    // Ziyaretçi tercihi yoksa sitenin varsayılan şeması uygulanır.
    $scheme = (string) hi_option('default_scheme', 'auto');

    if (in_array($scheme, ['light', 'dark'], true)) {
        echo '<meta name="hiblog-default-scheme" content="' . esc_attr($scheme) . '">' . "\n";
    }
});
