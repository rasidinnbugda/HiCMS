<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiMedia — türev kaydı, klasör ve etiket tabloları
 *
 * NEDEN AYRI TABLO: çekirdeğin `media.sizes` alanı srcset için TEK format
 * taşıyabilir — aynı genişlikte iki dosya (webp ve avif) olduğunda
 * `BlockRenderer::srcset()` genişliğe göre anahtarladığı için biri diğerini
 * eziyor ve tarayıcıya desteklemediği bir format gidebiliyor. Bu yüzden
 * üretilen her kopya burada tutulur; `sizes` yalnızca birincil kümeyi taşır.
 * `<picture>` çıktısı da bu tablodan kurulur.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        // Üretilen her kopya: hangi medyanın, hangi ölçü anahtarının, hangi formatı.
        $schema->create('media_variants', static function (Blueprint $table): void {
            $table->id();
            $table->integer('media_id', true);
            $table->string('size_key', 20);
            $table->string('format', 8);
            $table->integer('width', true)->default(0);
            $table->integer('height', true)->default(0);
            $table->string('file', 255);
            $table->integer('bytes', true)->default(0);
            $table->timestamp('created_at')->nullable();
            $table->unique(['media_id', 'size_key', 'format'], 'media_variant_unique');
            $table->index('media_id');
            $table->index('format');
        });

        $schema->create('media_folders', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->key('slug');
            $table->timestamp('created_at')->nullable();
            $table->unique('slug', 'media_folder_slug_unique');
        });

        /*
         * Medya başına tek satır: klasör ve etiketler. Etiketler `|a|b|` biçiminde
         * saklanır — sınır işaretleri sayesinde `LIKE '%|a|%'` tam etiket eşler,
         * "a" araması "araba" etiketini yakalamaz.
         */
        $schema->create('media_meta', static function (Blueprint $table): void {
            $table->integer('media_id', true);
            $table->integer('folder_id', true)->default(0);
            $table->string('tags', 255)->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['media_id']);
            $table->index('folder_id');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('media_meta');
        $schema->dropIfExists('media_folders');
        $schema->dropIfExists('media_variants');
    }
};
