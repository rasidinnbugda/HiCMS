<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * Sürüm geçmişi, otomatik kayıt slotu, çöp kutusu ve çakışma sayacı.
 *
 * Bu migration YALNIZCA şema işlemi yapar; `$db` üzerinden tek satır dahi
 * yazmaz. Sebep: `build/smoke.php` veritabanı bilgisi olmayan bir Connection
 * ile tüm migration'ları kuru çalıştırıyor. Schema kuru çalıştırmayı biliyor
 * ama ham `$db->insert()` bağlanmayı dener, bağlanamaz ve duman testi ölümcül
 * hatayla düşer. Daha kötüsü: veritabanı erişilebilir bir geliştirme
 * makinesinde "veritabanısız" test gerçek tabloya satır yazar.
 *
 * Budama görevi de bu yüzden eklenmiyor. Yeni bir `jobs` satırı yazmak yerine
 * budama, her kurulumda zaten bulunan `core.prune_logs` görevine eklendi
 * (bkz. src/Kernel.php).
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        /*
         * Sürüm tablosu içeriğin TAM kopyasını taşır, fark değil.
         *
         * Depolama maliyeti hesabı: tipik bir yazının blok ağacı 2-8 KB. Kayıt
         * başına 20 sürüm tutulursa yazı başına en fazla ~160 KB, bin yazılık
         * bir sitede ~160 MB. Fark (diff) saklamak bunu beşe bölerdi ama geri
         * yükleme zincir çözmeyi gerektirir ve zincirin ortasındaki bir kayıt
         * bozulursa sonrası kurtarılamaz. Tam kopya, geri yüklemeyi tek satır
         * okumaya indiriyor; budama da maliyeti sınırlıyor.
         */
        $schema->create('revisions', static function (Blueprint $table): void {
            $table->id();
            $table->integer('entry_id', true)->default(0);
            $table->integer('user_id', true)->default(0);

            /*
             * kind: 'save'     — kullanıcı kaydetti
             *       'autosave' — arka planda alındı, kullanıcı başına TEK slot
             *       'restore'  — bir sürüm geri yüklendi
             *
             * 'autosave' ayrı bir tür olmak zorunda: her otomatik kayıt sürüm
             * üretirse liste otuz saniyede bir dolan gürültüye dönüşür.
             */
            $table->string('kind', 16)->default('save');

            $table->string('title', 255);
            $table->text('excerpt')->nullable();
            $table->longText('blocks')->nullable();
            $table->string('status', 20)->default('draft');

            // İçeriğin o anki sayacı: çakışma çözümünde hangi tabana göre
            // yazıldığını gösterir.
            $table->integer('revision_no', true)->default(0);

            $table->timestamp('created_at')->nullable();

            $table->index(['entry_id', 'id'], 'rev_entry_idx');

            /*
             * Otomatik kayıt slotu için UNIQUE indeks KOYULMADI.
             *
             * Doğal aday (entry_id, user_id, kind) 'save' sürümlerini de
             * kullanıcı başına bire indirirdi — geçmişin tamamını yok eder.
             * MySQL koşullu (partial) indeks desteklemediği için "yalnızca
             * kind='autosave' iken tek" kuralı şemada ifade edilemiyor;
             * kural depoda uygulanıyor (ContentRepository::autosave()).
             *
             * Slotun KULLANICI BAŞINA olması kritik: tek slot olsaydı iki kişi
             * aynı içeriği açtığında birinin otomatik kaydı diğerinin üzerine
             * yazardı — yani tam olarak çakışmanın gerçekleştiği senaryoda
             * veri kaybı.
             */
            $table->index(['entry_id', 'user_id', 'kind'], 'rev_slot_idx');
        });

        /*
         * revision_no: iyimser kilit sayacı.
         *
         * Depoda `WHERE id = ? AND revision_no = ?` ile artırılıyor; eşleşme
         * yoksa araya başka bir yazma girmiş demektir. Sayacın VERİTABANI
         * düzeyinde (revision_no = revision_no + 1) artması şart: 0.2.0'da
         * bulkStatus() tek UPDATE ile yazıyor ve save()'i hiç çağırmıyor, yani
         * PHP tarafında artırılan bir sayaç o yolu göremezdi.
         *
         * trashed_at: çöp kutusu. Silme artık iki aşamalı — durum 'trash'a
         * çekilir ve damga atılır; kalıcı silme ayrı bir işlem.
         */
        $schema->addColumns('content', static function (Blueprint $table): void {
            $table->integer('revision_no', true)->default(0);
            $table->timestamp('trashed_at')->nullable();
        });

        $schema->addIndex('content', 'trashed_at', false, 'content_trashed_idx');
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('revisions');
        $schema->dropColumn('content', 'revision_no');
        $schema->dropColumn('content', 'trashed_at');
    }
};
