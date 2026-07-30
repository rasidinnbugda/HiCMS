<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * Medya kitaplığı ve yorumlar.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->create('media', static function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 255);
            $table->string('path', 255);
            $table->string('mime', 100);
            $table->integer('size', true)->default(0);
            $table->integer('width', true)->default(0);
            $table->integer('height', true)->default(0);
            $table->string('alt', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->integer('author_id', true)->default(0);

            // Üretilmiş türevler: {"medium":{"file":"…-800.webp","width":800,…}}
            // Çekirdek burayı yalnızca okur; doldurmak HiMedia eklentisinin işi.
            $table->json('sizes')->nullable();

            $table->timestamps();
            $table->index('mime');
            $table->index('author_id');
            $table->index('created_at');
        });

        $schema->create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->integer('entry_id', true);
            $table->integer('parent_id', true)->default(0);
            $table->integer('user_id', true)->default(0);
            $table->string('author_name', 191);
            $table->key('author_email');
            $table->string('author_url', 255)->nullable();
            $table->ip('author_ip')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['entry_id', 'status'], 'comments_entry_status_idx');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('comments');
        $schema->dropIfExists('media');
    }
};
