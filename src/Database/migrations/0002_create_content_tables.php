<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * İçerik, özel alanlar ve taksonomi tabloları.
 *
 * Tek bir `content` tablosu tüm içerik türlerini taşır: yazı, sayfa ve
 * tema/eklentinin tanımladığı özel türler. Tür ayrımı `type` sütunundadır;
 * böylece yeni bir içerik türü eklemek migration gerektirmez.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->create('content', static function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32)->default('post');
            $table->string('status', 20)->default('draft');
            $table->string('title', 255);
            $table->key('slug');
            $table->text('excerpt')->nullable();

            // Blok ağacı (JSON). HTML yığını değil: her blok kendi türünü ve
            // verisini taşır, nasıl basılacağına tema karar verir.
            $table->longText('blocks')->nullable();

            $table->integer('author_id', true)->default(0);
            $table->integer('parent_id', true)->default(0);
            $table->string('template', 191)->nullable();
            $table->boolean('featured')->default(0);
            $table->integer('media_id', true)->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->integer('views', true)->default(0);
            $table->boolean('comments_open')->default(1);
            $table->integer('position')->default(0);

            $table->unique(['type', 'slug'], 'content_type_slug_unique');
            $table->index(['type', 'status'], 'content_type_status_idx');
            $table->index(['status', 'published_at'], 'content_visible_idx');
            $table->index('author_id');
            $table->index('parent_id');
            $table->index('featured');
        });

        // Özel alanlar. HiTypes eklentisi ve tema alan tanımları buraya yazar.
        $schema->create('content_meta', static function (Blueprint $table): void {
            $table->id();
            $table->integer('entry_id', true);
            $table->key('meta_key');
            $table->longText('meta_value')->nullable();
            $table->unique(['entry_id', 'meta_key'], 'meta_entry_key_unique');
            $table->index('meta_key');
        });

        // Taksonomiler: kategori, etiket ve eklentilerin tanımladıkları.
        $schema->create('terms', static function (Blueprint $table): void {
            $table->id();
            $table->string('taxonomy', 32)->default('category');
            $table->string('name', 191);
            $table->key('slug');
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->integer('parent_id', true)->default(0);
            $table->integer('position')->default(0);
            $table->unique(['taxonomy', 'slug'], 'terms_taxonomy_slug_unique');
            $table->index('taxonomy');
            $table->index('parent_id');
        });

        $schema->create('term_entry', static function (Blueprint $table): void {
            $table->integer('term_id', true);
            $table->integer('entry_id', true);
            $table->integer('position')->default(0);
            $table->primary(['term_id', 'entry_id']);
            $table->index('entry_id');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('term_entry');
        $schema->dropIfExists('terms');
        $schema->dropIfExists('content_meta');
        $schema->dropIfExists('content');
    }
};
