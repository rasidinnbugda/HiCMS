<?php

declare(strict_types=1);

namespace HiCMS\Model;

use HiCMS\Support\Str;

/**
 * Panel kullanıcısı / içerik yazarı.
 */
final class User
{
    public int $id = 0;
    public string $username = '';
    public string $email = '';
    public string $passwordHash = '';
    public string $displayName = '';
    public string $slug = '';
    public string $role = 'subscriber';
    public string $bio = '';
    public int $avatarId = 0;
    public string $locale = '';
    public string $status = 'active';
    public string $createdAt = '';
    public ?string $lastLoginAt = null;

    /** @var array<string, mixed> Panel tercihleri (JSON sütunundan) */
    public array $preferences = [];

    public int $entryCount = 0;

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $user = new self();

        $user->id           = (int) ($row['id'] ?? 0);
        $user->username     = (string) ($row['username'] ?? '');
        $user->email        = (string) ($row['email'] ?? '');
        $user->passwordHash = (string) ($row['password_hash'] ?? '');
        $user->displayName  = (string) ($row['display_name'] ?? '');
        $user->slug         = (string) ($row['slug'] ?? '');
        $user->role         = (string) ($row['role'] ?? 'subscriber');
        $user->bio          = (string) ($row['bio'] ?? '');
        $user->avatarId     = (int) ($row['avatar_id'] ?? 0);
        $user->locale       = (string) ($row['locale'] ?? '');
        $user->status       = (string) ($row['status'] ?? 'active');
        $user->createdAt    = (string) ($row['created_at'] ?? '');
        $user->lastLoginAt  = $row['last_login_at'] ?? null;

        $preferences = json_decode((string) ($row['preferences'] ?? '{}'), true);
        $user->preferences = is_array($preferences) ? $preferences : [];

        return $user;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'username'      => $this->username,
            'email'         => $this->email,
            'password_hash' => $this->passwordHash,
            'display_name'  => $this->displayName,
            'slug'          => $this->slug,
            'role'          => $this->role,
            'bio'           => $this->bio,
            'avatar_id'     => $this->avatarId,
            'locale'        => $this->locale,
            'status'        => $this->status,
            'preferences'   => (string) json_encode($this->preferences, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function initials(): string
    {
        return Str::initials($this->displayName !== '' ? $this->displayName : $this->username);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return $this->preferences[$key] ?? $default;
    }
}
