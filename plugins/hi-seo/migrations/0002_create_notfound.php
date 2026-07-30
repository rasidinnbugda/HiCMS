<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiSEO — bulunamayan adres günlüğü
 *
 * Yönlendirme yöneticisinin beslendiği yer. Ziyaretçinin 404 aldığı her yol
 * burada birikir; panel her satır için içerik başlıklarına en yakın eşleşmeyi
 * önerir ve tek tıkla yönlendirmeye çevirir.
 *
 * `path` UNIQUE: aynı adres ikinci kez istendiğinde yeni satır açılmaz,
 * `hits` artar. Böylece tablo bir bot taramasıyla şişmez.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->create('seo_notfound', static function (Blueprint $table): void {
            $table->id();
            $table->key('path');
            $table->string('referrer', 255)->nullable();
            $table->integer('hits', true)->default(1);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
            $table->unique('path', 'seo_notfound_path_unique');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('seo_notfound');
    }
};
