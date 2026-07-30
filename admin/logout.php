<?php

declare(strict_types=1);

/**
 * HiAdmin — Çıkış
 *
 * @package HiCMS
 */

require __DIR__ . '/includes/bootstrap.php';

/*
 * Çıkış POST ile ve anahtar doğrulamasıyla yapılır.
 *
 * 0.2.0'da bu dosya hiçbir doğrulama yapmadan GET ile çıkış yapıyordu: bir
 * saldırganın kullanıcıya `<img src="…/admin/logout.php">` göstermesi yeterli
 * oluyordu, kullanıcı farkında olmadan oturumdan düşüyordu. Zarar veri kaybı
 * değil ama istenmeden gerçekleşen bir durum değişikliği — CSRF'in tanımı bu.
 *
 * GET isteği reddedilmez, onay ekranına çevrilir: eski bir yer imi ya da
 * önbellekten gelen bir istek kullanıcıyı sessizce çıkarmaz, sorar.
 */
if (!$app->request()->isPost()) {
    admin_head([
        'title'      => 'Çıkış',
        'slug'       => 'logout',
        'narrow'     => true,
        'breadcrumb' => [['label' => 'Panel', 'url' => 'index.php'], ['label' => 'Çıkış']],
    ]);
    ?>
    <section class="panel">
        <div class="panel-body">
            <p>Oturumu kapatmak istediğinizden emin misiniz?</p>

            <form method="post" action="logout.php" class="row" style="gap: var(--s2); margin-top: var(--s3)">
                <?= hi_csrf_field() ?>
                <button class="btn btn-primary" type="submit">Çıkış yap</button>
                <a class="btn" href="index.php">Vazgeç</a>
            </form>
        </div>
    </section>
    <?php
    admin_foot();
    exit;
}

admin_verify('index.php');

$app->auth()->logout();

admin_redirect('login.php', 'success', 'Çıkış yapıldı.');
