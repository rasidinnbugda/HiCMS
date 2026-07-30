<?php

declare(strict_types=1);

namespace HiCMS\Repository;

use HiCMS\Database\Connection;
use HiCMS\Model\Comment;
use HiCMS\Support\Dates;
use HiCMS\Support\Str;

/**
 * Yorum deposu.
 *
 * Gelen yorumlar varsayılan olarak `pending` durumundadır; site ayarında
 * denetim kapatılmışsa doğrudan onaylanır. Bal küpü ve hız sınırı denetimi
 * `submit()` içinde yapılır — tema kodu bunları bilmek zorunda değildir.
 */
final class CommentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
    ) {
    }

    public function find(int $id): ?Comment
    {
        $row = $this->db->builder('comments')->where('id', $id)->first();

        return $row !== null ? Comment::fromRow($row) : null;
    }

    /**
     * Bir içeriğin onaylı yorumları, ağaç olarak.
     *
     * @return list<Comment>
     */
    public function treeFor(int $entryId): array
    {
        $rows = $this->db->builder('comments')
            ->where('entry_id', $entryId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'asc')
            ->get();

        $comments = array_map([Comment::class, 'fromRow'], $rows);
        $this->markStaff($comments);

        return Comment::tree($comments);
    }

    public function countFor(int $entryId, string $status = 'approved'): int
    {
        $query = $this->db->builder('comments')->where('entry_id', $entryId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $query->count();
    }

    /**
     * Panel listesi: içerik başlığıyla birlikte.
     *
     * @param array{status?: string, search?: string, entry?: int, page?: int, perPage?: int} $args
     * @return array{items: list<Comment>, total: int, page: int, pages: int}
     */
    public function paginate(array $args = []): array
    {
        $contentTable = $this->db->t('content');

        $query = $this->db->builder('comments')->alias('m')
            ->select([
                'm.*',
                sprintf('`%s`.`title` AS entry_title', $contentTable),
                sprintf('`%s`.`slug` AS entry_slug', $contentTable),
            ])
            ->leftJoin('content', 'content.id', '=', 'm.entry_id')
            ->orderBy('m.created_at', 'desc');

        if (($args['status'] ?? 'all') !== 'all' && ($args['status'] ?? '') !== '') {
            $query->where('m.status', (string) $args['status']);
        }

        if (($args['search'] ?? '') !== '') {
            $query->whereAnyLike(['m.author_name', 'm.author_email', 'm.body'], (string) $args['search']);
        }

        if ((int) ($args['entry'] ?? 0) > 0) {
            $query->where('m.entry_id', (int) $args['entry']);
        }

        $result   = $query->paginate((int) ($args['perPage'] ?? 20), (int) ($args['page'] ?? 1));
        $comments = array_map([Comment::class, 'fromRow'], $result['items']);

        $this->markStaff($comments);

        return [
            'items' => $comments,
            'total' => $result['total'],
            'page'  => $result['page'],
            'pages' => $result['pages'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = $this->db->builder('comments')
            ->select(['status'])
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->get();

        $counts = ['all' => 0, 'approved' => 0, 'pending' => 0, 'spam' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
            $counts['all'] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<Comment>
     */
    public function recent(int $limit = 5): array
    {
        $result = $this->paginate(['perPage' => $limit, 'page' => 1]);

        return $result['items'];
    }

    /**
     * Ziyaretçi yorumunu kaydeder.
     *
     * @param array{entry: int, parent?: int, name: string, email: string, url?: string,
     *              body: string, ip: string, honeypot?: string} $input
     * @return array{ok: bool, error: string, status: string, id: int}
     */
    public function submit(array $input, bool $moderate = true): array
    {
        $entryId = (int) ($input['entry'] ?? 0);
        $name    = trim((string) ($input['name'] ?? ''));
        $email   = strtolower(trim((string) ($input['email'] ?? '')));
        $body    = trim((string) ($input['body'] ?? ''));

        // Bal küpü: botlar gizli alanı doldurur. Sessizce başarılı görünelim.
        if (trim((string) ($input['honeypot'] ?? '')) !== '') {
            return ['ok' => true, 'error' => '', 'status' => 'spam', 'id' => 0];
        }

        if ($entryId <= 0) {
            return ['ok' => false, 'error' => 'Yorum yapılacak içerik bulunamadı.', 'status' => '', 'id' => 0];
        }

        if ($name === '' || mb_strlen($name) > 80) {
            return ['ok' => false, 'error' => 'Adınızı girin (en fazla 80 karakter).', 'status' => '', 'id' => 0];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Geçerli bir e-posta adresi girin.', 'status' => '', 'id' => 0];
        }

        if (mb_strlen($body) < 3) {
            return ['ok' => false, 'error' => 'Yorumunuz çok kısa.', 'status' => '', 'id' => 0];
        }

        if (mb_strlen($body) > 5000) {
            return ['ok' => false, 'error' => 'Yorumunuz çok uzun (en fazla 5000 karakter).', 'status' => '', 'id' => 0];
        }

        // Aynı IP'den art arda gelen yorumları sınırla.
        $recent = $this->db->builder('comments')
            ->where('author_ip', (string) ($input['ip'] ?? ''))
            ->whereRaw('created_at > :since', ['since' => Dates::stamp('-60 seconds')])
            ->count();

        if ($recent >= 2) {
            return ['ok' => false, 'error' => 'Çok hızlı yorum gönderiyorsunuz, biraz bekleyin.', 'status' => '', 'id' => 0];
        }

        // Aynı yorumun tekrarını engelle.
        $duplicate = $this->db->builder('comments')
            ->where('entry_id', $entryId)
            ->where('author_email', $email)
            ->where('body', $body)
            ->exists();

        if ($duplicate) {
            return ['ok' => false, 'error' => 'Bu yorumu daha önce göndermişsiniz.', 'status' => '', 'id' => 0];
        }

        $url = trim((string) ($input['url'] ?? ''));

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            $url = '';
        }

        $comment              = new Comment();
        $comment->entryId     = $entryId;
        $comment->parentId    = max(0, (int) ($input['parent'] ?? 0));
        $comment->authorName  = $name;
        $comment->authorEmail = $email;
        $comment->authorUrl   = $url;
        $comment->authorIp    = (string) ($input['ip'] ?? '');
        $comment->body        = $body;
        $comment->status      = $this->looksLikeSpam($body, $url) ? 'spam' : ($moderate ? 'pending' : 'approved');

        $row               = $comment->toRow();
        $row['created_at'] = Dates::stamp();
        $row['updated_at'] = Dates::stamp();

        $id = $this->db->insert('comments', $row);

        return ['ok' => true, 'error' => '', 'status' => $comment->status, 'id' => $id];
    }

    /**
     * Panelden eklenen yanıt (yönetici adına).
     */
    public function reply(int $parentId, int $userId, string $body): array
    {
        $parent = $this->find($parentId);

        if ($parent === null) {
            return ['ok' => false, 'error' => 'Yanıtlanacak yorum bulunamadı.', 'id' => 0];
        }

        $user = $this->users->find($userId);

        if ($user === null) {
            return ['ok' => false, 'error' => 'Kullanıcı bulunamadı.', 'id' => 0];
        }

        $body = trim($body);

        if ($body === '') {
            return ['ok' => false, 'error' => 'Yanıt boş olamaz.', 'id' => 0];
        }

        $id = $this->db->insert('comments', [
            'entry_id'     => $parent->entryId,
            'parent_id'    => $parent->id,
            'user_id'      => $user->id,
            'author_name'  => $user->displayName,
            'author_email' => $user->email,
            'author_url'   => '',
            'author_ip'    => '',
            'body'         => $body,
            'status'       => 'approved',
            'created_at'   => Dates::stamp(),
            'updated_at'   => Dates::stamp(),
        ]);

        return ['ok' => true, 'error' => '', 'id' => $id];
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['approved', 'pending', 'spam'], true)) {
            return false;
        }

        $this->db->update('comments', ['status' => $status, 'updated_at' => Dates::stamp()], ['id' => $id]);

        return true;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === [] || !in_array($status, ['approved', 'pending', 'spam'], true)) {
            return 0;
        }

        return $this->db->builder('comments')
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'updated_at' => Dates::stamp()]);
    }

    public function delete(int $id): bool
    {
        // Alt yanıtları da sil — yetim yorum bırakmayalım.
        $this->db->builder('comments')->where('parent_id', $id)->delete();
        $this->db->delete('comments', ['id' => $id]);

        return true;
    }

    /**
     * @param list<int> $ids
     */
    public function bulkDelete(array $ids): int
    {
        $deleted = 0;

        foreach (array_filter(array_map('intval', $ids)) as $id) {
            if ($this->delete($id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function emptySpam(): int
    {
        return $this->db->builder('comments')->where('status', 'spam')->delete();
    }

    /**
     * Site ekibinden gelen yorumları işaretler.
     *
     * @param list<Comment> $comments
     */
    private function markStaff(array $comments): void
    {
        if ($comments === []) {
            return;
        }

        $emails = array_values(array_unique(array_map(
            static fn(Comment $c): string => $c->authorEmail,
            $comments
        )));

        $staff = array_map(
            'strval',
            $this->db->builder('users')->whereIn('email', $emails)->pluck('email')
        );

        foreach ($comments as $comment) {
            $comment->byStaff = $comment->userId > 0 || in_array($comment->authorEmail, $staff, true);
        }
    }

    /**
     * Çok basit bir istenmeyen sezgisi. Gerçek filtreleme eklentinin işi;
     * buradaki amaç en kaba örnekleri panelden uzak tutmak.
     */
    private function looksLikeSpam(string $body, string $url): bool
    {
        $linkCount = preg_match_all('#https?://#i', $body);

        if ($linkCount !== false && $linkCount > 3) {
            return true;
        }

        $needles = ['casino', 'viagra', 'crypto pump', 'seo hizmeti', 'backlink pak', 'takipçi satın'];
        $haystack = mb_strtolower($body . ' ' . $url, 'UTF-8');

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        // Neredeyse tamamı büyük harf ve uzunsa şüpheli.
        if (mb_strlen($body) > 60 && Str::words($body) > 8
            && mb_strtoupper($body, 'UTF-8') === $body) {
            return true;
        }

        return false;
    }
}
