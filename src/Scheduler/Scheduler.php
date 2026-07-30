<?php

declare(strict_types=1);

namespace HiCMS\Scheduler;

use Closure;
use HiCMS\Database\Connection;
use HiCMS\Support\Dates;
use Throwable;

/**
 * Planlı görevler.
 *
 * Sistem cron'una ihtiyaç duymaz: yanıt istemciye gönderildikten sonra, süresi
 * gelmiş **en fazla bir** görev çalıştırılır (`tick()`). Böylece ziyaretçi
 * bekletilmez. Gerçek cron kurulabiliyorsa `hi-cron.php` aynı kuyruğu tam
 * kapasiteyle işletir.
 *
 * Görev kilitlenir (`locked_at`), böylece eşzamanlı istekler aynı görevi iki kez
 * çalıştırmaz. Üç kez başarısız olan görev kuyruktan düşürülür ve hatası
 * saklanır.
 */
final class Scheduler
{
    /** @var array<string, Closure> */
    private array $handlers = [];

    private const LOCK_TIMEOUT = 300;
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Bir görev adına işleyici bağlar.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    public function handle(string $name, callable $handler): void
    {
        $this->handlers[$name] = Closure::fromCallable($handler);
    }

    public function hasHandler(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /** @return list<string> */
    public function handlerNames(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Tek seferlik görev planlar.
     *
     * @param array<string, mixed> $payload
     */
    public function once(string $name, string $runAt = 'now', array $payload = []): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return $this->db->insert('jobs', [
            'name'          => $name,
            'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'run_at'        => Dates::stamp($runAt),
            'interval_spec' => null,
            'attempts'      => 0,
            'created_at'    => Dates::stamp(),
        ]);
    }

    /**
     * Yinelenen görev planlar. Aynı adda yinelenen görev varsa yenisi eklenmez.
     *
     * @param array<string, mixed> $payload
     */
    public function every(string $name, string $interval = '1 hour', array $payload = []): int
    {
        if (!$this->ready()) {
            return 0;
        }

        $exists = $this->db->builder('jobs')
            ->where('name', $name)
            ->whereNull('interval_spec', true)
            ->exists();

        if ($exists) {
            return 0;
        }

        return $this->db->insert('jobs', [
            'name'          => $name,
            'payload'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'run_at'        => Dates::stamp('+' . $interval),
            'interval_spec' => $interval,
            'attempts'      => 0,
            'created_at'    => Dates::stamp(),
        ]);
    }

    public function forget(string $name): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return $this->db->builder('jobs')->where('name', $name)->delete();
    }

    /**
     * Bekleyen görevleri listeler (panelde göstermek için).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 50): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->builder('jobs')->orderBy('run_at')->limit($limit)->get();
    }

    public function dueCount(): int
    {
        if (!$this->ready()) {
            return 0;
        }

        return $this->db->builder('jobs')
            ->whereRaw('run_at <= :now', ['now' => Dates::stamp()])
            ->count();
    }

    /**
     * Süresi gelmiş görevleri çalıştırır.
     *
     * @return array{ran: int, names: list<string>, errors: list<string>}
     */
    public function run(int $max = 1): array
    {
        if (!$this->ready()) {
            return ['ran' => 0, 'names' => [], 'errors' => []];
        }

        $ran    = 0;
        $names  = [];
        $errors = [];

        for ($i = 0; $i < $max; $i++) {
            $job = $this->claim();

            if ($job === null) {
                break;
            }

            $name    = (string) $job['name'];
            $payload = json_decode((string) ($job['payload'] ?? '{}'), true);
            $payload = is_array($payload) ? $payload : [];

            if (!isset($this->handlers[$name])) {
                // İşleyicisi olmayan görev (eklenti devre dışı bırakılmış olabilir):
                // yinelenense ertele, değilse düşür.
                $this->reschedule($job, 'İşleyici bulunamadı.');
                continue;
            }

            try {
                ($this->handlers[$name])($payload);

                $this->complete($job);
                $ran++;
                $names[] = $name;
            } catch (Throwable $exception) {
                $errors[] = $name . ': ' . $exception->getMessage();
                $this->fail($job, $exception->getMessage());
            }
        }

        return ['ran' => $ran, 'names' => $names, 'errors' => $errors];
    }

    /**
     * Yanıt gönderildikten sonra çağrılır — ziyaretçiyi bekletmez.
     */
    public function tick(): void
    {
        /*
         * Oturum kilidi ÖNCE bırakılır. Aksi hâlde aşağıdaki görev — günlük
         * budama, güncelleme denetimi, geçici dosya temizliği — veritabanı işi
         * yaparken oturum dosyasının kilidini elinde tutar ve kullanıcının
         * sıradaki isteği bu işin bitmesini bekler. Yanıt zaten gönderildiği
         * için oturuma yazacak bir şey kalmadı.
         */
        if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $this->run(1);
    }

    /**
     * Sıradaki görevi kilitleyerek alır.
     *
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        $now       = Dates::stamp();
        $lockLimit = Dates::stamp('-' . self::LOCK_TIMEOUT . ' seconds');

        $job = $this->db->builder('jobs')
            ->whereRaw('run_at <= :now', ['now' => $now])
            ->whereRaw('(locked_at IS NULL OR locked_at < :lock_limit)', ['lock_limit' => $lockLimit])
            ->orderBy('run_at')
            ->first();

        if ($job === null) {
            return null;
        }

        // Kilidi atomik olarak al: başka bir istek aynı anda aldıysa 0 satır etkilenir.
        $claimed = $this->db->statement(
            sprintf(
                'UPDATE `%s` SET locked_at = :now, attempts = attempts + 1
                 WHERE id = :id AND (locked_at IS NULL OR locked_at < :lock_limit)',
                $this->db->t('jobs')
            ),
            ['now' => $now, 'id' => (int) $job['id'], 'lock_limit' => $lockLimit]
        );

        return $claimed > 0 ? $job : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function complete(array $job): void
    {
        $interval = (string) ($job['interval_spec'] ?? '');

        if ($interval !== '') {
            $this->db->update('jobs', [
                'run_at'     => Dates::stamp('+' . $interval),
                'locked_at'  => null,
                'attempts'   => 0,
                'last_error' => null,
            ], ['id' => (int) $job['id']]);

            return;
        }

        $this->db->delete('jobs', ['id' => (int) $job['id']]);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function fail(array $job, string $error): void
    {
        $attempts = (int) ($job['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS && (string) ($job['interval_spec'] ?? '') === '') {
            $this->db->delete('jobs', ['id' => (int) $job['id']]);

            return;
        }

        $this->db->update('jobs', [
            'run_at'     => Dates::stamp('+' . min(60 * $attempts, 3600) . ' seconds'),
            'locked_at'  => null,
            'last_error' => mb_substr($error, 0, 500),
        ], ['id' => (int) $job['id']]);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function reschedule(array $job, string $reason): void
    {
        if ((string) ($job['interval_spec'] ?? '') === '') {
            $this->db->delete('jobs', ['id' => (int) $job['id']]);

            return;
        }

        $this->db->update('jobs', [
            'run_at'     => Dates::stamp('+1 hour'),
            'locked_at'  => null,
            'last_error' => $reason,
        ], ['id' => (int) $job['id']]);
    }

    private function ready(): bool
    {
        return $this->db->tableExists('jobs');
    }
}
