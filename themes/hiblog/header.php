<?php

declare(strict_types=1);

/**
 * HiBlog — Sayfa başı
 *
 * @package HiBlog
 */
?>
<!DOCTYPE html>
<html lang="tr" data-scheme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= hi_document_title() ?></title>
    <meta name="description" content="<?= hi_meta_description() ?>">

    <meta property="og:site_name" content="<?= esc_attr(hi_site_name()) ?>">
    <meta property="og:title" content="<?= hi_document_title() ?>">
    <meta property="og:description" content="<?= hi_meta_description() ?>">
    <meta property="og:type" content="<?= hi_is_singular() ? 'article' : 'website' ?>">
    <meta property="og:url" content="<?= esc_url(hi_is_singular() ? hi_permalink() : hi_url()) ?>">
    <?php if (hi_is_singular() && hi_has_thumbnail()) : ?>
        <meta property="og:image" content="<?= esc_url(hi_thumbnail_url()) ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">

    <link rel="alternate" type="application/rss+xml" title="<?= esc_attr(hi_site_name()) ?>"
          href="<?= esc_url(hi_url('feed')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <?php /* Şemayı boyamadan önce uygula: sayfa geçişlerinde beyaz parlama olmasın. */ ?>
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('hiblog-scheme');
                var dark = stored
                    ? stored === 'dark'
                    : matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-scheme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>

    <?php hi_head(); ?>
</head>
<body <?= hi_body_class() ?>>

<a class="skip" href="#content">İçeriğe geç</a>

<header class="site-header">
    <div class="wrap header-inner">
        <div class="site-brand">
            <?php if (hi_is_home()) : ?>
                <h1 class="site-title"><a href="<?= esc_url(hi_url()) ?>"><?= esc_html(hi_site_name()) ?></a></h1>
            <?php else : ?>
                <p class="site-title"><a href="<?= esc_url(hi_url()) ?>"><?= esc_html(hi_site_name()) ?></a></p>
            <?php endif; ?>

            <?php if (hi_site_tagline() !== '') : ?>
                <p class="site-tagline"><?= esc_html(hi_site_tagline()) ?></p>
            <?php endif; ?>
        </div>

        <span class="header-gap"></span>

        <nav class="site-nav" aria-label="Ana menü">
            <?php if (hi_has_menu('primary')) : ?>
                <?= hi_menu('primary') ?>
            <?php else : ?>
                <ul class="nav-menu">
                    <li><a href="<?= esc_url(hi_url()) ?>">Anasayfa</a></li>
                    <?php foreach (array_slice(hi_all_terms('category'), 0, 3) as $navTerm) : ?>
                        <li><a href="<?= esc_url(hi_term_url($navTerm)) ?>"><?= esc_html($navTerm->name) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </nav>

        <div class="header-tools">
            <a class="icon-button" href="<?= esc_url(hi_url('arama')) ?>" aria-label="Ara" title="Ara">
                <?= hiblog_icon('search', 18) ?>
            </a>

            <button class="icon-button" type="button" data-scheme-toggle aria-pressed="false"
                    aria-label="Karanlık temaya geç">
                <span data-icon="moon"><?= hiblog_icon('moon', 18) ?></span>
                <span data-icon="sun" hidden><?= hiblog_icon('sun', 18) ?></span>
            </button>

            <button class="icon-button nav-toggle" type="button" data-nav-toggle aria-expanded="false"
                    aria-label="Menüyü aç">
                <span data-icon="open"><?= hiblog_icon('menu', 19) ?></span>
                <span data-icon="close" hidden><?= hiblog_icon('close', 19) ?></span>
            </button>
        </div>
    </div>
</header>

<main id="content">
