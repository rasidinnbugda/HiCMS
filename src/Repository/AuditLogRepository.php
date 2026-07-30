<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;
use HiCMS\Support\Dates;

/**
 * Denetim günlüğü.
 *
 * Yalnızca ekleme yapılır — kayıtlar güncellenmez veya silinmez (yaş sınırı
 * dışında). "Kim neyi ne zaman değiştirdi" sorusunun tek yanıtı burasıdır;
 * çok yazarlı kurulumlarda ve müşteri tesliminde tartışmayı bitirir.
 */
final class AuditLogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function record(
        string $action,
        int $userId = 0,
        string $actor = '',
        string $subjectType = '',
        int $subjectId = 0,
        string $summary = '',
        array $meta = [],
        string $ip = '',
    ): void {
        if (!$this->db->tableExists('audit_log')) {
            return;
        }

        $this->db->insert('audit_log', [
            'user_id'      => $userId,
            'actor'        => mb_substr($actor, 0, 180),
            'action'       => mb_substr($action, 0, 60),
            'subject_type' => mb_substr($subjectType, 0, 60),
            'subject_id'   => $subjectId,
            'summary'      => mb_substr($summary, 0, 250),
            'meta'         => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip'           => $ip,
            'created_at'   => Dates::stamp(),
        ]);
    }

    /**
     * @param array{action?: string, user?: int, subjectType?: string, search?: string,
     *              page?: int, perPage?: int} $args
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function paginate(array $args = []): array
    {
        $query = $this->db->builder('audit_log')->orderBy('id', 'desc');

        if (($args['action'] ?? '') !== '') {
            $query->where('action', (string) $args['action']);
        }

        if ((int) ($args['user'] ?? 0) > 0) {
            $query->where('user_id', (int) $args['user']);
        }

        if (($args['subjectType'] ?? '') !== '') {
            $query->where('subject_type', (string) $args['subjectType']);
        }

        if (($args['search'] ?? '') !== '') {
            $query->whereAnyLike(['actor', 'summary', 'action'], (string) $args['search']);
        }

        $result = $query->paginate((int) ($args['perPage'] ?? 40), (int) ($args['page'] ?? 1));

        foreach ($result['items'] as &$row) {
            $row['meta'] = $row['meta'] !== null && $row['meta'] !== ''
                ? (json_decode((string) $row['meta'], true) ?: [])
                : [];
        }
        unset($row);

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ];
    }

    /**
     * Panelde gösterilen son hareketler.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 8): array
    {
        return $this->db->builder('audit_log')->orderBy('id', 'desc')->limit($limit)->get();
    }

    /**
     * Kullanılan eylem türleri — filtre açılır listesi için.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map('strval', $this->db->builder('audit_log')
            ->select(['action'])
            ->groupBy('action')
            ->orderBy('action')
            ->pluck('action'));
    }

    /**
     * Belirtilen günden eski kayıtları siler (planlı görev çağırır).
     */
    public function prune(int $days = 180): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->db->statement(
            sprintf('DELETE FROM `%s` WHERE created_at < :cutoff', $this->db->t('audit_log')),
            ['cutoff' => Dates::stamp('-' . $days . ' days')]
        );
    }

    public function count(): int
    {
        return $this->db->builder('audit_log')->count();
    }
}
