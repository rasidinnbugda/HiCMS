<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiForms — gönderi durumu ve bildirim izi
 *
 * 0001'de tek bayrak vardı: `is_read`. İstenmeyen gönderiyi SİLMEK yerine
 * karantinaya almak için üçüncü bir hâl gerekiyor (yeni / okundu / istenmeyen),
 * o yüzden durum ayrı bir sütuna taşındı.
 *
 * `is_read` GERİYE UYUMLULUK için yerinde bırakıldı ve durumla birlikte yazılır:
 * eski kayıtları okuyan bir kod (ya da elle yazılmış bir sorgu) bozulmasın.
 *
 * `notified` bildirimin gerçekten gidip gitmediğini tutar. Bunu saklamak
 * gerekiyor çünkü `mail()` sessizce başarısız olabilir ve panelde "bildirim
 * gitti mi?" sorusunun tahmine dayalı bir cevabı olmamalı.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->addColumns('form_submissions', static function (Blueprint $table): void {
            $table->string('status', 20)->default('new');
            $table->boolean('notified')->default(0);
        });

        /*
         * Kuru çalıştırmada (build/smoke.php) veritabanı yok: yalnızca üretilen
         * SQL toplanıyor. Buradan sonrası gerçek bağlantı ister.
         */
        if ($schema->isDryRun()) {
            return;
        }

        $schema->addIndex('form_submissions', 'status', false, 'submissions_status_idx');

        // Var olan kayıtların durumu eski bayraktan türetilir.
        if ($db->columnExists('form_submissions', 'is_read')) {
            $db->statement(
                'UPDATE `' . $db->t('form_submissions') . '` SET status = ? WHERE is_read = 1',
                ['read']
            );
        }
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropColumn('form_submissions', 'status');
        $schema->dropColumn('form_submissions', 'notified');
    }
};
