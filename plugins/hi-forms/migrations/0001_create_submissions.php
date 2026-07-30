<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiForms — gönderi tablosu
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->create('form_submissions', static function (Blueprint $table): void {
            $table->id();
            $table->key('form');
            $table->json('payload');
            $table->string('subject', 255)->nullable();
            $table->ip('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('is_read')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['form', 'created_at'], 'submissions_form_time_idx');
            $table->index('is_read');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('form_submissions');
    }
};
