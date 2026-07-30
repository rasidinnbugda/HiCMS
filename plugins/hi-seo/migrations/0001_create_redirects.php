<?php

declare(strict_types=1);

use HiCMS\Database\Blueprint;
use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * HiSEO — yönlendirme tablosu
 *
 * Eski siteden taşınırken kırılan bağlantıları kurtarmak için.
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        $schema->create('seo_redirects', static function (Blueprint $table): void {
            $table->id();
            $table->key('source');
            $table->string('target', 500);
            $table->smallInteger('status')->default(301);
            $table->integer('hits', true)->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
            $table->unique('source', 'seo_source_unique');
        });
    }

    public function down(Schema $schema, Connection $db): void
    {
        $schema->dropIfExists('seo_redirects');
    }
};
