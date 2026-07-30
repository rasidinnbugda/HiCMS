<?php

declare(strict_types=1);

use HiCMS\Database\Connection;
use HiCMS\Database\Schema;

/**
 * Ön yüzün en sık sorgusunu karşılayan bileşik indeks ve sıralama indeksleri.
 *
 * Yalnızca şema işlemi yapar; `$db` üzerinden veri yazmaz (bkz. 0005).
 */
return new class {
    public function up(Schema $schema, Connection $db): void
    {
        /*
         * ANA SAYFA VE ARŞİV SORGUSU
         *
         *   WHERE type = ? AND status = 'published'
         *         AND (published_at IS NULL OR published_at <= now)
         *   ORDER BY published_at DESC, id DESC
         *
         * Var olan indeksler bunu karşılamıyordu:
         *   (type, status)      → eşitlikleri karşılıyor ama SIRALAMAYI değil;
         *                         MySQL filesort'a düşüyor.
         *   (status, published_at) → `type` süzgecini kapsamıyor.
         *
         * (type, status, published_at) üçlüsü eşitlikleri VE sıralamayı aynı
         * indeksten karşılıyor: filesort kalkıyor.
         *
         * `id` sıralamanın ikinci anahtarı ama InnoDB ikincil indekslerde
         * birincil anahtarı zaten taşıdığı için ayrıca eklenmesi gerekmiyor.
         */
        $schema->addIndex('content', ['type', 'status', 'published_at'], false, 'content_feed_idx');

        /*
         * Panelde sıralanabilen sütunlar. 0.2.0'da üçü de indekssizdi:
         * başlığa göre sıralama, "son güncellenenler" ve "en çok okunanlar"
         * her seferinde tam tablo sıralaması yapıyordu.
         *
         * Tür ile birlikte indeksleniyorlar, çünkü panel listesi her zaman tek
         * bir içerik türü içinde sıralıyor.
         */
        $schema->addIndex('content', ['type', 'updated_at'], false, 'content_type_updated_idx');
        $schema->addIndex('content', ['type', 'views'], false, 'content_type_views_idx');
        $schema->addIndex('content', ['type', 'title'], false, 'content_type_title_idx');

        /*
         * Yorum sayfalamasında ve denetim günlüğünde en sık kullanılan sıralar.
         */
        $schema->addIndex('comments', ['status', 'created_at'], false, 'comments_status_created_idx');
    }

    public function down(Schema $schema, Connection $db): void
    {
        // İndeks düşürmek şemayı bozmaz; geri alma gerekliyse elle yapılır.
        // Blueprint indeks düşürme desteklemediği için burada bir şey yapılmıyor.
    }
};
