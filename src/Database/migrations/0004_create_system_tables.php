<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * Denetim günlüğü ve planlı görev kuyruğu.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        // Kim, neyi, ne zaman değiştirdi. Yalnızca eklenir — güncellenmez.
        $schema->create('audit_log', static function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id', true)->default(0);
            $table->string('actor', 191)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->integer('subject_id', true)->default(0);
            $table->string('summary', 255)->nullable();
            $table->json('meta')->nullable();
            $table->ip('ip')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
            $table->index('user_id');
            $table->index('action');
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
        });

        /**
         * Planlı görevler. Sistem cron'una gerek yoktur: her istek, süresi
         * gelmiş en fazla bir görevi yanıt gönderildikten sonra çalıştırır.
         * Gerçek cron varsa `hi-cron.php` ile aynı kuyruk işletilir.
         */
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->key('name');
            $table->json('payload')->nullable();
            $table->timestamp('run_at');
            $table->string('interval_spec', 32)->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('run_at');
            $table->index('name');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('jobs');
        $schema->dropIfExists('audit_log');
    }
};
