<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * Ayarlar, kullanıcılar ve oturum güvenliği tabloları.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        // Site ayarları. `autoload` işaretli olanlar her istekte tek sorguyla okunur.
        $schema->create('options', static function (Blueprint $table): void {
            $table->key('name');
            $table->longText('value')->nullable();
            $table->boolean('autoload')->default(1);
            $table->primary(['name']);
            $table->index('autoload');
        });

        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->key('username');
            $table->key('email');
            $table->string('password_hash', 255);
            $table->string('display_name');
            $table->key('slug');
            $table->string('role', 32)->default('subscriber');
            $table->text('bio')->nullable();
            $table->integer('avatar_id', true)->default(0);
            $table->string('locale', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('preferences')->nullable();
            $table->timestamps();
            $table->timestamp('last_login_at')->nullable();
            $table->unique('username', 'users_username_unique');
            $table->unique('email', 'users_email_unique');
            $table->unique('slug', 'users_slug_unique');
            $table->index('role');
        });

        // "Beni hatırla" çerezleri: seçici açık, doğrulayıcı hash'li saklanır.
        $schema->create('user_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id', true);
            $table->string('selector', 64);
            $table->string('validator_hash', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->unique('selector', 'tokens_selector_unique');
            $table->index('user_id');
            $table->index('expires_at');
        });

        // Kaba kuvvet denemelerini sınırlamak için.
        $schema->create('login_attempts', static function (Blueprint $table): void {
            $table->id();
            $table->ip('ip');
            $table->key('identifier');
            $table->boolean('successful')->default(0);
            $table->timestamp('attempted_at');
            $table->index(['ip', 'attempted_at'], 'attempts_ip_time_idx');
            $table->index(['identifier', 'attempted_at'], 'attempts_id_time_idx');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('login_attempts');
        $schema->dropIfExists('user_tokens');
        $schema->dropIfExists('users');
        $schema->dropIfExists('options');
    }
};
