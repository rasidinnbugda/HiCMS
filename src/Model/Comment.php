<?php

declare(strict_types=1);

namespace HiCMS\Model;

/**
 * Yorum. Alt yorumlar `children` alanında ağaç olarak taşınır.
 */
final class Comment
{
    public int $id = 0;
    public int $entryId = 0;
    public int $parentId = 0;
    public int $userId = 0;
    public string $authorName = '';
    public string $authorEmail = '';
    public string $authorUrl = '';
    public string $authorIp = '';
    public string $body = '';
    public string $status = 'pending';
    public string $createdAt = '';

    /** @var list<Comment> */
    public array $children = [];

    /** Yorum sahibi site ekibinden mi? Depo doldurur. */
    public bool $byStaff = false;

    /** İlişkili içeriğin başlığı — panelde listelemek için. */
    public string $entryTitle = '';

    public string $entrySlug = '';

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $comment = new self();

        $comment->id          = (int) ($row['id'] ?? 0);
        $comment->entryId     = (int) ($row['entry_id'] ?? 0);
        $comment->parentId    = (int) ($row['parent_id'] ?? 0);
        $comment->userId      = (int) ($row['user_id'] ?? 0);
        $comment->authorName  = (string) ($row['author_name'] ?? '');
        $comment->authorEmail = (string) ($row['author_email'] ?? '');
        $comment->authorUrl   = (string) ($row['author_url'] ?? '');
        $comment->authorIp    = (string) ($row['author_ip'] ?? '');
        $comment->body        = (string) ($row['body'] ?? '');
        $comment->status      = (string) ($row['status'] ?? 'pending');
        $comment->createdAt   = (string) ($row['created_at'] ?? '');
        $comment->entryTitle  = (string) ($row['entry_title'] ?? '');
        $comment->entrySlug   = (string) ($row['entry_slug'] ?? '');

        return $comment;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'entry_id'     => $this->entryId,
            'parent_id'    => $this->parentId,
            'user_id'      => $this->userId,
            'author_name'  => $this->authorName,
            'author_email' => $this->authorEmail,
            'author_url'   => $this->authorUrl,
            'author_ip'    => $this->authorIp,
            'body'         => $this->body,
            'status'       => $this->status,
        ];
    }

    /**
     * Düz listeyi ebeveyn-çocuk ağacına çevirir.
     *
     * @param list<Comment> $comments
     * @return list<Comment>
     */
    public static function tree(array $comments, int $parentId = 0): array
    {
        $branch = [];

        foreach ($comments as $comment) {
            if ($comment->parentId !== $parentId) {
                continue;
            }

            $comment->children = self::tree($comments, $comment->id);
            $branch[]          = $comment;
        }

        return $branch;
    }
}
