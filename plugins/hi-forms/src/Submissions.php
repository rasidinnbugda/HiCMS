<?php

declare(strict_types=1);

namespace HiForms;

use HiCMS\Database\Blueprint;
use HiCMS\Support\Dates;

/**
 * Gönderi deposu.
 *
 * Üç hâl var: `new` (okunmadı), `read` (okundu), `spam` (karantina). Eski
 * `is_read` bayrağı durumla birlikte yazılmaya devam ediyor — 0001 migration'ı
 * onun üzerinde bir indeks kurmuş ve kayıtları elle inceleyen biri hâlâ ona
 * bakıyor olabilir.
 */
final class Submissions
{
    public const TABLE = 'form_submissions';

    public const STATUSES = ['new' => 'Yeni', 'read' => 'Okundu', 'spam' => 'İstenmeyen'];

    private static ?bool $ready = null;

    private static ?bool $hasStatus = null;

    /**
     * Tablo ve sütunlar yerinde mi?
     *
     * Sütun eksikse (eklenti dosyaları güncellendi ama bekleyen migration
     * çalıştırılmadı) burada eklenir: gönderi kaybetmek bir ALTER TABLE'dan
     * pahalıdır. Migration yine birincil yol — bu yalnızca ağ.
     */
    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        if (!hi()->db()->tableExists(self::TABLE)) {
            return self::$ready = false;
        }

        if (!self::hasStatus()) {
            hi()->schema()->addColumns(self::TABLE, static function (Blueprint $table): void {
                $table->string('status', 20)->default('new');
                $table->boolean('notified')->default(0);
            });

            hi()->schema()->addIndex(self::TABLE, 'status', false, 'submissions_status_idx');

            self::$hasStatus = true;
        }

        return self::$ready = true;
    }

    /**
     * `status` sütunu var mı?
     *
     * Sonuç istek boyunca belleklenir. `Connection::columnExists()` her çağrıda
     * bir information_schema sorgusu yapıyor ve bu denetim menü kurulurken —
     * yani HER panel sayfasında — çağrılıyor.
     */
    private static function hasStatus(): bool
    {
        return self::$hasStatus ??= hi()->db()->columnExists(self::TABLE, 'status');
    }

    /* ---------------------------------------------------------------------
     * Yazma
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, string> $payload
     */
    public static function store(
        string $form,
        array $payload,
        string $subject,
        string $ip,
        string $userAgent,
        string $status = 'new',
    ): int {
        if (!self::ready()) {
            return 0;
        }

        return hi()->db()->insert(self::TABLE, [
            'form'       => $form,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            'subject'    => mb_substr($subject, 0, 250),
            'ip'         => mb_substr($ip, 0, 45),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'status'     => isset(self::STATUSES[$status]) ? $status : 'new',
            'is_read'    => $status === 'new' ? 0 : 1,
            'notified'   => 0,
            'created_at' => Dates::stamp(),
        ]);
    }

    public static function markNotified(int $id): void
    {
        if ($id > 0 && self::ready()) {
            hi()->db()->update(self::TABLE, ['notified' => 1], ['id' => $id]);
        }
    }

    /**
     * @param list<int> $ids
     */
    public static function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

        if ($ids === [] || !isset(self::STATUSES[$status]) || !self::ready()) {
            return 0;
        }

        return hi()->db()->builder(self::TABLE)
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'is_read' => $status === 'new' ? 0 : 1]);
    }

    /**
     * @param list<int> $ids
     */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));

        if ($ids === [] || !self::ready()) {
            return 0;
        }

        return hi()->db()->builder(self::TABLE)->whereIn('id', $ids)->delete();
    }

    public static function emptySpam(): int
    {
        if (!self::ready()) {
            return 0;
        }

        return hi()->db()->builder(self::TABLE)->where('status', 'spam')->delete();
    }

    /** Bir form tanımı silindiğinde gönderileri KORUNUR; yalnızca istenirse silinir. */
    public static function deleteByForm(string $form): int
    {
        if ($form === '' || !self::ready()) {
            return 0;
        }

        return hi()->db()->builder(self::TABLE)->where('form', $form)->delete();
    }

    /* ---------------------------------------------------------------------
     * Okuma
     * ------------------------------------------------------------------ */

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public static function paginate(string $status, string $form, string $search, int $page, int $perPage = 25): array
    {
        if (!self::ready()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'pages' => 1];
        }

        $query = hi()->db()->builder(self::TABLE)->orderBy('id', 'desc');

        /*
         * "Tümü" istenmeyeni GÖSTERMEZ. Karantinanın anlamı listeyi kirletmemesi;
         * kendi sekmesinde durur.
         */
        if (isset(self::STATUSES[$status])) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'spam');
        }

        if ($form !== '') {
            $query->where('form', $form);
        }

        if ($search !== '') {
            $query->whereAnyLike(['subject', 'payload'], $search);
        }

        return $query->paginate($perPage, $page);
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $counts = ['all' => 0, 'new' => 0, 'read' => 0, 'spam' => 0];

        if (!self::ready()) {
            return $counts;
        }

        foreach (array_keys(self::STATUSES) as $status) {
            $counts[$status] = hi()->db()->builder(self::TABLE)->where('status', $status)->count();
        }

        $counts['all'] = $counts['new'] + $counts['read'];

        return $counts;
    }

    /** Menüdeki nişan için: okunmamış sayısı. */
    public static function unread(): int
    {
        $db = hi()->db();

        if (!$db->tableExists(self::TABLE)) {
            return 0;
        }

        /*
         * Menü HER panel sayfasında kurulur; burada sütun EKLEME denemesi
         * yapılmaz (menü kurulumu şema değiştirecek yer değil). Sütun yoksa eski
         * bayrağa düşülür — sayı yine doğru çıkar.
         */
        return self::hasStatus()
            ? $db->builder(self::TABLE)->where('status', 'new')->count()
            : $db->builder(self::TABLE)->where('is_read', 0)->count();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0 || !self::ready()) {
            return null;
        }

        return hi()->db()->builder(self::TABLE)->where('id', $id)->first();
    }

    /**
     * Bir formun tüm gönderileri — CSV dışa aktarımı için.
     *
     * @return list<array<string, mixed>>
     */
    public static function allOf(string $form): array
    {
        if (!self::ready()) {
            return [];
        }

        $query = hi()->db()->builder(self::TABLE)->where('status', '!=', 'spam')->orderBy('id', 'asc');

        if ($form !== '') {
            $query->where('form', $form);
        }

        return $query->get();
    }

    /**
     * Kayıttaki JSON yükünü çözer.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function payload(array $row): array
    {
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $payload = [];

        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $payload;
    }

    /**
     * Yükü form tanımına göre sıralar ve etiketler.
     *
     * Tanım değişmiş olabilir: eski kayıtta artık tanımda olmayan anahtarlar
     * bulunabilir. Onlar DÜŞÜRÜLMEZ, listenin sonuna kendi anahtarlarıyla
     * eklenir — yoksa gönderi sessizce eksik görünür.
     *
     * @param array<string, mixed> $row
     * @return list<array{key: string, label: string, value: string}>
     */
    public static function labelled(array $row): array
    {
        return self::lines((string) ($row['form'] ?? ''), self::payload($row));
    }

    /**
     * @param array<string, string> $payload
     * @return list<array{key: string, label: string, value: string}>
     */
    public static function lines(string $formSlug, array $payload): array
    {
        $form  = Forms::find($formSlug);
        $lines = [];

        foreach ((array) ($form['fields'] ?? []) as $field) {
            $key = (string) $field['key'];

            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $lines[] = ['key' => $key, 'label' => (string) $field['label'], 'value' => $payload[$key]];

            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            $lines[] = ['key' => $key, 'label' => $key, 'value' => $value];
        }

        return $lines;
    }
}
